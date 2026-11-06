<?php

namespace App\Domain\Pages;

use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Throwable;

/**
 * Pages of a PDF without converting it (DESIGN §19.4 `PdfPageCounter`).
 * The free FPDI that comes with mPDF cannot read PDFs with a
 * cross-reference stream (PDF 1.5+, e.g. Word's "Save as PDF"), so:
 *
 * 1. FPDI's page tree when it can parse the file;
 * 2. otherwise count the page objects (`/Type /Page`, not `/Pages`) in the
 *    raw bytes plus inside every object stream (`/Type /ObjStm`) inflated
 *    with gzuncompress (zlib; files are at most 10 MB, so this is light);
 * 3. 0 means unreadable (encrypted or broken): the caller answers
 *    pdf_unreadable / marks the Classroom hand-in `unsupported`.
 */
final class PdfPageCounter
{
    private const PAGE = '#/Type\s*/Page(?![A-Za-z])#';

    public static function count(string $bytes): int
    {
        if (! str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF')) {
            return 0;
        }
        if (self::encrypted($bytes)) {
            return 0;
        }

        try {
            $count = (new PdfReader(new PdfParser(StreamReader::createByString($bytes))))->getPageCount();
            if ($count > 0) {
                return $count;
            }
        } catch (Throwable) {
            // cross-reference stream or damage: count the objects instead
        }

        return self::countObjects($bytes);
    }

    /** Step 2: page objects in the file and in its object streams. */
    public static function countObjects(string $bytes): int
    {
        $count = preg_match_all(self::PAGE, $bytes);
        foreach (self::objectStreams($bytes) as $content) {
            $count += preg_match_all(self::PAGE, $content);
        }

        return (int) $count;
    }

    /** @return list<string> the inflated content of every /Type /ObjStm object */
    private static function objectStreams(string $bytes): array
    {
        $streams = [];
        $offset = 0;
        while (($at = strpos($bytes, '/ObjStm', $offset)) !== false) {
            $offset = $at + 7;
            $start = strpos($bytes, 'stream', $at);
            $end = $start === false ? false : strpos($bytes, 'endstream', $start);
            $objEnd = strpos($bytes, 'endobj', $at);
            if ($start === false || $end === false || ($objEnd !== false && $objEnd < $start)) {
                continue;
            }
            $data = substr($bytes, $start + 6, $end - $start - 6);
            $data = ltrim($data, "\r\n");
            $inflated = @gzuncompress($data);
            if ($inflated === false) {
                $inflated = @gzuncompress(rtrim($data, "\r\n"));
            }
            if (is_string($inflated)) {
                $streams[] = $inflated;
            }
            $offset = $end;
        }

        return $streams;
    }

    /** An /Encrypt entry in a trailer or xref-stream dictionary. */
    private static function encrypted(string $bytes): bool
    {
        return preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $bytes) === 1;
    }
}
