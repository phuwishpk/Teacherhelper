<?php

namespace App\Domain\Worksheets;

use App\Models\Question;
use Illuminate\Support\Facades\Storage;

/**
 * The HTML of one question's prompt: "{n}. {prompt} ({points} คะแนน)" with a
 * hanging indent, plus the optional prompt image. Measured by LayoutBuilder
 * and written by WorksheetPdfRenderer, so both must use this one builder.
 */
final class PromptHtml
{
    private const IMAGE_MAX_W = 120.0;

    private const IMAGE_MAX_H = 60.0;

    public static function for(Question $question, int $number): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $text = nl2br($e(str_replace(["\r\n", "\r"], "\n", trim($question->prompt_text))), false);
        $html = '<div class="q" lang="th">'.$number.'.&nbsp;&nbsp;'.$text
            .' <span class="pts">('.self::points($question->max_points).' คะแนน)</span></div>';

        $image = self::image($question);
        if ($image !== null) {
            $html .= '<div class="qimg"><img src="'.$e($image['path']).'" width="'.$image['w'].'mm" height="'.$image['h'].'mm" /></div>';
        }

        return $html;
    }

    public static function points(float $points): string
    {
        return rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');
    }

    /** @return array{path: string, w: float, h: float}|null */
    private static function image(Question $question): ?array
    {
        $relative = $question->prompt_image_path;
        if ($relative === null || $relative === '' || ! Storage::disk('local')->exists($relative)) {
            return null;
        }

        $path = Storage::disk('local')->path($relative);
        $size = @getimagesize($path);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $scale = min(self::IMAGE_MAX_W / $size[0], self::IMAGE_MAX_H / $size[1]);

        return ['path' => $path, 'w' => round($size[0] * $scale, 2), 'h' => round($size[1] * $scale, 2)];
    }
}
