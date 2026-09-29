<?php

namespace App\Domain\Documents;

use App\Domain\Gemini\MediaResolution;

/**
 * The cost the app shows before a document is read (DESIGN §19.5):
 *
 *   input  = pages × tokens per page at GEMINI_MEDIA_DOCUMENT (560 at
 *            medium, a PDF page and a photo alike) + ~1,500 for the prompt
 *   output = questions × ~150 (questions unknown: ~5 per page), at most the
 *            prompt's 16,384 output limit
 *   thb    = (input × GEMINI_PRICE_INPUT_PER_M + output ×
 *            GEMINI_PRICE_OUTPUT_PER_M) / 1e6 × USD_THB_RATE, null while any
 *            of the three is not set (prices live in .env, never in code)
 *
 * An estimate only: ai_calls records what a read really used.
 */
final class CostEstimate
{
    public const PROMPT_TOKENS = 1500;

    public const OUTPUT_PER_QUESTION = 150;

    public const QUESTIONS_PER_PAGE = 5;

    public const MAX_OUTPUT = 16384;

    private const TOKENS_PER_PAGE = [
        MediaResolution::LOW => 280,
        MediaResolution::MEDIUM => 560,
        MediaResolution::HIGH => 1120,
        MediaResolution::ULTRA_HIGH => 2240,
    ];

    public static function tokensPerPage(): int
    {
        return self::TOKENS_PER_PAGE[MediaResolution::forPart(MediaResolution::PART_DOCUMENT)] ?? 560;
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, thb: float|null}
     */
    public static function forPages(int $pages, ?int $questions = null): array
    {
        $pages = max(0, $pages);
        $questions = $questions !== null && $questions > 0 ? $questions : max(1, $pages * self::QUESTIONS_PER_PAGE);
        $input = $pages * self::tokensPerPage() + self::PROMPT_TOKENS;
        $output = min(self::MAX_OUTPUT, $questions * self::OUTPUT_PER_QUESTION);

        return ['input_tokens' => $input, 'output_tokens' => $output, 'thb' => self::thb($input, $output)];
    }

    public static function thb(int $input, int $output): ?float
    {
        $in = config('services.gemini.price_input_per_m');
        $out = config('services.gemini.price_output_per_m');
        $rate = config('eduvision.usd_thb_rate');
        if (! is_numeric($in) || ! is_numeric($out) || ! is_numeric($rate)) {
            return null;
        }

        return round(($input * (float) $in + $output * (float) $out) / 1_000_000 * (float) $rate, 2);
    }
}
