<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The bubble fill of one answer-sheet page as the phone read it (DESIGN
 * §22.9): {version_fill?, rows, digits?}, checked against the page of the
 * layout the QR names (§22.8). Used by POST /exam-sheets and
 * POST /exams/{id}/key-sheet-read.
 *
 * - rows must name exactly the page's omr_row sheet numbers and digits
 *   exactly its digit_block sheet numbers, a block with as many columns as
 *   printed; otherwise 422 page_mismatch (read with another page's layout).
 * - Every printed bubble gets a value: one the phone left out reads 0,
 *   values of bubbles that are not printed are dropped. The sign is null
 *   when the block has no sign bubble; version_fill is null on a page
 *   without version bubbles.
 */
final readonly class ExamSheetReading
{
    /**
     * @param  array<string, float>|null  $versionFill
     * @param  array<string, array<string, float>>  $rows
     * @param  array<string, array{sign: float|null, columns: list<array<string, float>>}>  $digits
     */
    public function __construct(
        public ?array $versionFill,
        public array $rows,
        public array $digits,
    ) {}

    /**
     * Laravel rules for the reading fields under $prefix ("" or "meta.").
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $fill = ['numeric', 'between:0,1'];

        return [
            "{$prefix}version_fill" => ['sometimes', 'nullable', 'array', 'max:10'],
            "{$prefix}version_fill.*" => $fill,
            "{$prefix}rows" => ['present', 'array', 'max:'.ExamSheetCapacity::rowCapacity(0)],
            "{$prefix}rows.*" => ['array', 'max:6'],
            "{$prefix}rows.*.*" => $fill,
            "{$prefix}digits" => ['sometimes', 'nullable', 'array', 'max:16'],
            "{$prefix}digits.*" => ['array'],
            "{$prefix}digits.*.sign" => ['nullable', ...$fill],
            "{$prefix}digits.*.columns" => ['required', 'list', 'max:7'],
            "{$prefix}digits.*.columns.*" => ['array', 'max:11'],
            "{$prefix}digits.*.columns.*.*" => $fill,
        ];
    }

    /**
     * Thai messages for rules(); every rule has one so no English text
     * reaches the teacher.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'array' => ':attribute ต้องเป็น JSON object',
            'list' => ':attribute ต้องเป็น array',
            'numeric' => 'ค่าการฝนต้องเป็นตัวเลข',
            'between' => 'ค่าการฝนต้องอยู่ระหว่าง 0–1',
            'present' => 'ไม่มีค่าการฝน (rows)',
            'required' => 'ไม่มี :attribute',
            'max' => ':attribute มีรายการมากเกินไป',
        ];
    }

    /**
     * Validates the fields of $data (already decoded) without a prefix.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function validate(array $data): void
    {
        Validator::make($data, self::rules(), self::messages())->validate();
    }

    /**
     * @param  array<string, mixed>|null  $versionFill
     * @param  array<int|string, mixed>  $rows
     * @param  array<int|string, mixed>|null  $digits
     * @param  array<string, mixed>  $page  one page of layouts.pages (sheet = exam)
     *
     * @throws ApiException 422 page_mismatch
     */
    public static function against(?array $versionFill, array $rows, ?array $digits, array $page): self
    {
        $digits ??= [];
        $pageNo = (int) ($page['page'] ?? 1);
        $mismatch = fn () => new ApiException(
            "ค่าที่อ่านได้ไม่ตรงกับหน้า {$pageNo} ของกระดาษคำตอบ ลองสแกนใหม่",
            'page_mismatch',
            422,
        );

        $layoutRows = [];
        $layoutBlocks = [];
        $layoutVersion = null;
        foreach ($page['regions'] ?? [] as $region) {
            match ($region['kind'] ?? null) {
                'omr_row' => $layoutRows[(string) $region['sheet_no']] = $region,
                'digit_block' => $layoutBlocks[(string) $region['sheet_no']] = $region,
                'version_bubbles' => $layoutVersion = $region,
                default => null,
            };
        }

        $rowKeys = array_map('strval', array_keys($rows));
        $blockKeys = array_map('strval', array_keys($digits));
        sort($rowKeys);
        sort($blockKeys);
        $expectedRows = array_map('strval', array_keys($layoutRows));
        $expectedBlocks = array_map('strval', array_keys($layoutBlocks));
        sort($expectedRows);
        sort($expectedBlocks);
        if ($rowKeys !== $expectedRows || $blockKeys !== $expectedBlocks) {
            throw $mismatch();
        }

        $outRows = [];
        foreach ($layoutRows as $sheetNo => $region) {
            $outRows[$sheetNo] = self::bubbles($region['bubbles'] ?? [], (array) $rows[$sheetNo]);
        }

        $outDigits = [];
        foreach ($layoutBlocks as $sheetNo => $region) {
            $block = (array) $digits[$sheetNo];
            $columns = array_values((array) ($block['columns'] ?? []));
            $printed = array_values($region['columns'] ?? []);
            if (count($columns) !== count($printed)) {
                throw $mismatch();
            }
            $sign = null;
            if (($region['sign'] ?? null) !== null) {
                $sign = is_numeric($block['sign'] ?? null) ? round((float) $block['sign'], 4) : 0.0;
            }
            $outDigits[$sheetNo] = [
                'sign' => $sign,
                'columns' => array_map(
                    fn (array $column, int $i) => self::bubbles($column['bubbles'] ?? [], (array) $columns[$i]),
                    $printed,
                    array_keys($printed),
                ),
            ];
        }

        $outVersion = null;
        if ($layoutVersion !== null) {
            $outVersion = self::bubbles($layoutVersion['bubbles'] ?? [], $versionFill ?? []);
        }

        return new self($outVersion, $outRows, $outDigits);
    }

    /**
     * @param  list<array<string, mixed>>  $bubbles
     * @param  array<int|string, mixed>  $fill
     * @return array<string, float>
     */
    private static function bubbles(array $bubbles, array $fill): array
    {
        $out = [];
        foreach ($bubbles as $bubble) {
            $value = (string) $bubble['value'];
            $out[$value] = is_numeric($fill[$value] ?? null) ? round((float) $fill[$value], 4) : 0.0;
        }

        return $out;
    }
}
