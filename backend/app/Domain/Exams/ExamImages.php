<?php

namespace App\Domain\Exams;

use App\Models\Assignment;
use App\Models\Question;
use App\Models\QuestionOption;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Images of exam questions and options on the private disk (DESIGN §22.4,
 * §22.15): the teacher uploads JPEG, PNG or WebP up to
 * eduvision.exams.image_max_kb; GD scales the long side down to
 * eduvision.exams.image_max_px and stores a JPEG (quality 85) at
 *
 *   exams/{school}/{assignment}/figures/q{question_id}.jpg
 *   exams/{school}/{assignment}/figures/o{option_id}.jpg
 *
 * (the q/o prefix keeps question and option ids apart). Figures live as
 * long as the exam and are streamed only after the policy check.
 */
final class ExamImages
{
    public const FIELD = 'image';

    /** Larger pictures are refused before GD decodes them (memory on shared hosting). */
    private const MAX_SOURCE_PX = 8000;

    /** Decoded pixels at most (about 5 bytes each in GD), whatever the memory_limit says. */
    private const MAX_SOURCE_PIXELS = 25_000_000;

    private const QUALITY = 85;

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    public static function directory(Assignment $exam): string
    {
        return "exams/{$exam->school_id}/{$exam->id}";
    }

    public static function questionPath(Question $question, Assignment $exam): string
    {
        return self::directory($exam)."/figures/q{$question->id}.jpg";
    }

    public static function optionPath(QuestionOption $option, Assignment $exam): string
    {
        return self::directory($exam)."/figures/o{$option->id}.jpg";
    }

    /**
     * Checks the upload, scales it and writes the JPEG at $path.
     *
     * @throws ValidationException errors.image
     */
    public static function store(mixed $file, string $path): void
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            self::fail('กรุณาแนบรูปภาพ');
        }
        $maxKb = (int) config('eduvision.exams.image_max_kb');
        if ($file->getSize() > $maxKb * 1024) {
            self::fail('รูปภาพต้องมีขนาดไม่เกิน '.intdiv($maxKb, 1024).' MB');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            self::fail('รูปภาพต้องเป็นไฟล์ JPEG, PNG หรือ WebP');
        }
        if ($info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > self::MAX_SOURCE_PX) {
            self::fail('รูปภาพใหญ่เกินไป ด้านยาวต้องไม่เกิน '.number_format(self::MAX_SOURCE_PX).' พิกเซล');
        }
        if ($info[0] * $info[1] > self::MAX_SOURCE_PIXELS || ! self::fitsInMemory($info[0], $info[1])) {
            // A 48 MP photo needs about 240 MB in GD: refuse it instead of a fatal out-of-memory 500.
            self::fail('รูปภาพมีจำนวนพิกเซลมากเกินไป ย่อรูปให้ด้านยาวไม่เกิน 4,000 พิกเซลแล้วแนบใหม่');
        }
        $image = @imagecreatefromstring($bytes);
        unset($bytes);
        if (! $image instanceof GdImage) {
            self::fail('อ่านรูปภาพนี้ไม่ได้ ลองบันทึกเป็น JPEG แล้วแนบใหม่');
        }

        self::writeJpeg(self::scaled($image), $path);
    }

    /**
     * Whether GD can decode a w x h picture (truecolor, about 5 bytes a
     * pixel with the scaled copy) within the PHP memory_limit left, so a
     * large photo on shared hosting fails softly instead of fatally.
     */
    public static function fitsInMemory(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $width * $height * 5 + 16 * 1024 * 1024 <= $bytes - memory_get_usage();
    }

    /** Writes $image as a JPEG (quality 85) at $path on the private disk. */
    public static function writeJpeg(GdImage $image, string $path): void
    {
        self::disk()->put($path, self::jpeg($image));
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            self::disk()->delete($path);
        }
    }

    /** Every figure of the exam (a draft exam that is deleted). */
    public static function deleteExam(Assignment $exam): void
    {
        self::disk()->deleteDirectory(self::directory($exam));
    }

    /**
     * White background (PNG/WebP transparency) and the long side scaled down
     * to $maxPx (default eduvision.exams.image_max_px).
     */
    public static function scaled(GdImage $source, ?int $maxPx = null): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $maxPx ??= (int) config('eduvision.exams.image_max_px');
        $scale = min(1.0, $maxPx / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        return $out;
    }

    public static function jpeg(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();

        return $jpeg;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages([self::FIELD => $message]);
    }
}
