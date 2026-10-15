<?php

namespace App\Domain\Gemini;

/**
 * Media resolution of an image part (DESIGN §21.5). Gemini 3.x bills an
 * image by this level, not by its size: low 280, medium 560, high 1,120
 * tokens. The level of each kind of part comes from config
 * (services.gemini.media.*, GEMINI_MEDIA_*), `high` until the calibration
 * harness (§21.10) has shown a lower level reads as well.
 *
 * Sent per part (mediaResolution.level) when services.gemini.media_per_part
 * is on, otherwise once per call as generationConfig.mediaResolution at the
 * highest level of the call's parts (the fallback of §21.5).
 */
final class MediaResolution
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const ULTRA_HIGH = 'ultra_high';

    /** ai_calls.media_resolution when the parts of a call differ. */
    public const MIXED = 'mixed';

    /** Kinds of part with their own setting (config services.gemini.media). */
    public const PART_SHORT = 'short';

    public const PART_WORK = 'work';

    public const PART_PAGE = 'page';

    public const PART_DOCUMENT = 'document';

    private const RANK = [self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3, self::ULTRA_HIGH => 4];

    private const DEFAULTS = [
        self::PART_SHORT => self::HIGH,
        self::PART_WORK => self::HIGH,
        self::PART_PAGE => self::HIGH,
        self::PART_DOCUMENT => self::MEDIUM,
    ];

    /** The configured level of a kind of part; an unknown value falls back to the default. */
    public static function forPart(string $part): string
    {
        $level = strtolower(trim((string) config("services.gemini.media.{$part}", '')));

        return isset(self::RANK[$level]) ? $level : (self::DEFAULTS[$part] ?? self::HIGH);
    }

    /**
     * @param  list<string|null>  $levels
     */
    public static function highest(array $levels): ?string
    {
        $best = null;
        foreach ($levels as $level) {
            if ($level !== null && isset(self::RANK[$level]) && ($best === null || self::RANK[$level] > self::RANK[$best])) {
                $best = $level;
            }
        }

        return $best;
    }

    /**
     * What ai_calls records for a call: its one level, `mixed`, or null
     * (no image, or no level set).
     *
     * @param  list<string|null>  $levels
     */
    public static function summary(array $levels): ?string
    {
        $distinct = array_values(array_unique(array_filter($levels, fn ($l) => $l !== null)));

        return match (count($distinct)) {
            0 => null,
            1 => $distinct[0],
            default => self::MIXED,
        };
    }

    /** The REST enum, e.g. MEDIA_RESOLUTION_HIGH. */
    public static function apiValue(string $level, bool $perPart): string
    {
        // ultra_high exists only per part.
        $level = ! $perPart && $level === self::ULTRA_HIGH ? self::HIGH : $level;

        return 'MEDIA_RESOLUTION_'.strtoupper($level);
    }
}
