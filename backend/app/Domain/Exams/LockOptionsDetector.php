<?php

namespace App\Domain\Exams;

/**
 * Suggests "ห้ามสลับตัวเลือก" for an mcq question (DESIGN §22.5) when an
 * option only makes sense in its place: "ถูกทุกข้อ", "ผิดทุกข้อ",
 * "ไม่มีข้อใดถูก", "ถูกทั้ง…", options that name other options by their
 * letter ("ทั้ง ก และ ข", "ข้อ ก และ ข", "ก และ ค ถูก") and "all/none of
 * the above". A suggestion only: the teacher confirms it, nothing is set here.
 */
final class LockOptionsDetector
{
    /** Phrases that refer to the other options as a whole (compared without spaces, lower case). */
    private const PHRASES = [
        'ถูกทุกข้อ', 'ผิดทุกข้อ', 'ถูกหมดทุกข้อ', 'ผิดหมดทุกข้อ', 'ถูกทั้งหมด', 'ผิดทั้งหมด',
        'ไม่มีข้อใดถูก', 'ไม่มีข้อใดผิด', 'ไม่มีข้อถูก', 'ไม่มีข้อที่ถูก', 'ไม่มีคำตอบที่ถูก',
        'ทุกข้อที่กล่าวมา', 'ทุกข้อข้างต้น', 'ข้อที่กล่าวมาทั้งหมด', 'ถูกทั้ง', 'ผิดทั้ง',
        'alloftheabove', 'noneoftheabove', 'alloftheseabove', 'noneofthese', 'allofthese',
    ];

    /** The whole option names other options by letter: "ทั้ง ก และ ข", "ข้อ ก และ ข้อ ค", "ก, ข และ ค ถูก". */
    private const THAI_LETTERS = '/^(?:ทั้ง\s*)?(?:ข้อ\s*)?[กขคงจฉ][.)]?(?:(?:\s*(?:,|และ|หรือ)\s*|\s+)(?:ข้อ\s*)?[กขคงจฉ][.)]?)+\s*(?:ถูก(?:ต้อง)?|ผิด)?\s*\.?$/u';

    /** "A and B", "Both A and C", "A, B and C are correct". */
    private const LATIN_LETTERS = '/^(?:both\s+)?\(?[a-f]\)?(?:\s*(?:,|and|&|or)\s*\(?[a-f]\)?)+(?:\s+(?:only|are\s+(?:correct|true)))?\s*\.?$/i';

    /**
     * @param  list<string|null>  $optionTexts  the options' texts in any order
     */
    public static function suggests(array $optionTexts): bool
    {
        foreach ($optionTexts as $text) {
            if ($text !== null && self::refersToOthers($text)) {
                return true;
            }
        }

        return false;
    }

    public static function refersToOthers(string $text): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return false;
        }
        $compact = mb_strtolower(preg_replace('/[\s.]+/u', '', $text) ?? $text);
        foreach (self::PHRASES as $phrase) {
            if (str_contains($compact, $phrase)) {
                return true;
            }
        }

        return preg_match(self::THAI_LETTERS, $text) === 1 || preg_match(self::LATIN_LETTERS, $text) === 1;
    }
}
