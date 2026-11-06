<?php

namespace App\Domain\Worksheets;

/**
 * Fixed A4 page geometry in millimetres (DESIGN §5.2, §5.3).
 *
 * The four ArUco marker centres sit on the corners of the frame
 * {x 16, y 16, w 178, h 265}; every layout coordinate is relative to that
 * frame and normalised to 0–1 (DESIGN §5.3). The header (title, name, number,
 * page, QR) is identical in size for every student, so all students share one
 * layout per version. Questions flow between CONTENT_TOP and CONTENT_BOTTOM.
 */
final class WorksheetGeometry
{
    public const PAGE_W = 210.0;

    public const PAGE_H = 297.0;

    public const FRAME_X = 16.0;

    public const FRAME_Y = 16.0;

    public const FRAME_W = 178.0;

    public const FRAME_H = 265.0;

    // Header (never cropped, never sent to Gemini).
    public const TITLE_X = 26.0;

    public const TITLE_Y = 10.5;

    public const TITLE_W = 128.0;

    public const TITLE_H = 8.0;

    public const STUDENT_Y = 19.5;

    public const STUDENT_H = 7.0;

    public const META_Y = 27.0;

    public const META_H = 6.0;

    /** QR: 22 mm square left of the top-right marker, with >= 4 modules of blank paper around it. */
    public const QR_X = 160.0;

    public const QR_Y = 18.0;

    public const QR_SIZE = 22.0;

    public const HEADER_RULE_Y = 44.0;

    public const FOOTER_Y = 282.0;

    // Question flow area.
    public const CONTENT_LEFT = 18.0;

    public const CONTENT_RIGHT = 192.0;

    public const CONTENT_TOP = 48.0;

    public const CONTENT_BOTTOM = 270.0;

    /** Hanging indent of the question number; answer areas start here. */
    public const INDENT = 9.0;

    public const PROMPT_GAP = 2.0;

    public const QUESTION_GAP = 6.0;

    // Answer areas.
    public const MCQ_ROW_H = 10.0;

    public const MCQ_BUBBLE_R = 2.8;

    public const MCQ_SPACING = 28.0;

    /** Distance from the option letter to the centre of its bubble. */
    public const MCQ_LABEL_TO_CENTRE = 8.0;

    public const BOX_W = 70.0;

    public const BOX_H = 16.0;

    public const LINE_H = 10.0;

    public const FINAL_GAP = 3.0;

    public static function contentWidth(): float
    {
        return self::CONTENT_RIGHT - self::CONTENT_LEFT;
    }

    public static function contentHeight(): float
    {
        return self::CONTENT_BOTTOM - self::CONTENT_TOP;
    }

    public static function regionLeft(): float
    {
        return self::CONTENT_LEFT + self::INDENT;
    }

    /** Right-aligned answer box (short answers, show_work final answer). */
    public static function boxLeft(): float
    {
        return self::CONTENT_RIGHT - self::BOX_W;
    }

    /**
     * Page rectangle (mm) -> frame-relative rectangle, 4 decimals (~0.05 mm).
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    public static function rect(float $x, float $y, float $w, float $h): array
    {
        return [
            'x' => self::nx($x),
            'y' => self::ny($y),
            'w' => self::round($w / self::FRAME_W),
            'h' => self::round($h / self::FRAME_H),
        ];
    }

    public static function nx(float $x): float
    {
        return self::round(($x - self::FRAME_X) / self::FRAME_W);
    }

    public static function ny(float $y): float
    {
        return self::round(($y - self::FRAME_Y) / self::FRAME_H);
    }

    /** Bubble radius normalised by the frame WIDTH (the phone multiplies by the warped width). */
    public static function nr(float $r): float
    {
        return self::round($r / self::FRAME_W);
    }

    /**
     * `frame_mm` of the layout JSON (whole millimetres, as in DESIGN §5.3).
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
    public static function frameMm(): array
    {
        return [
            'x' => (int) self::FRAME_X,
            'y' => (int) self::FRAME_Y,
            'w' => (int) self::FRAME_W,
            'h' => (int) self::FRAME_H,
        ];
    }

    private static function round(float $v): float
    {
        return round($v, 4);
    }
}
