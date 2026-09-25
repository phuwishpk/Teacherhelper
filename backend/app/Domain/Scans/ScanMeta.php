<?php

namespace App\Domain\Scans;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * The `meta` JSON field of POST /scans (DESIGN §9.4), parsed and validated.
 * Checks that need the layout (region set, mcq options, final-answer crops)
 * happen in ScanIngestor once the QR names the layout.
 */
final readonly class ScanMeta
{
    /** More answer areas than any A4 page can hold. */
    public const MAX_REGIONS = 40;

    /** Multipart field of the warped page image. */
    public const PAGE_FIELD = 'page';

    /** Allowed multipart field names for crops (also used in stored file names). */
    public const FILE_FIELD_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /**
     * @param  list<ScanRegion>  $regions
     */
    public function __construct(
        public string $clientScanId,
        public string $qr,
        public CarbonImmutable $scannedAt,
        public float $blurScore,
        public array $regions,
    ) {}

    /**
     * The client_scan_id if `meta` carries a well-formed one, lower-cased.
     * Read before full validation so a retry is answered from the stored
     * scan even if the request would no longer validate.
     */
    public static function clientScanIdFrom(Request $request): ?string
    {
        $meta = self::decode($request, false);
        $id = is_array($meta) ? ($meta['client_scan_id'] ?? null) : null;

        return is_string($id) && preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $id) === 1
            ? strtolower($id)
            : null;
    }

    /**
     * @throws ValidationException 422 validation_failed
     */
    public static function fromRequest(Request $request): self
    {
        $meta = self::decode($request, true);

        $validated = Validator::make(['meta' => $meta], self::rules(), self::messages(), self::attributes())->validate()['meta'];

        $regions = array_map(fn (array $r) => ScanRegion::fromValidated($r), array_values($validated['regions']));
        self::assertDistinctFiles($regions);

        $scannedAt = CarbonImmutable::parse($validated['scanned_at'])->utc();
        // A phone clock running ahead must not produce a scan "from the future".
        if ($scannedAt->isFuture()) {
            $scannedAt = CarbonImmutable::now()->utc();
        }

        return new self(
            clientScanId: strtolower($validated['client_scan_id']),
            qr: trim($validated['qr']),
            scannedAt: $scannedAt,
            blurScore: (float) $validated['blur_score'],
            regions: $regions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(): array
    {
        $field = ['string', 'regex:'.self::FILE_FIELD_PATTERN, 'not_in:'.self::PAGE_FIELD];

        return [
            'meta' => ['required', 'array'],
            'meta.client_scan_id' => ['required', 'string', 'uuid'],
            'meta.qr' => ['required', 'string', 'max:128'],
            'meta.scanned_at' => ['required', 'date', 'after:2020-01-01'],
            'meta.blur_score' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'meta.regions' => ['required', 'list', 'min:1', 'max:'.self::MAX_REGIONS],
            'meta.regions.*' => ['required', 'array'],
            'meta.regions.*.region_id' => ['required', 'string', 'max:64', 'distinct'],
            'meta.regions.*.question_id' => ['required', 'integer', 'min:1'],
            'meta.regions.*.file' => ['required', ...$field],
            'meta.regions.*.final_file' => ['sometimes', 'nullable', ...$field],
            'meta.regions.*.ink_ratio' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'meta.regions.*.mcq_fill' => ['sometimes', 'nullable', 'array', 'max:10'],
            'meta.regions.*.mcq_fill.*' => ['numeric', 'between:0,1'],
            'meta.regions.*.cnn' => ['sometimes', 'nullable', 'array'],
            'meta.regions.*.cnn.text' => ['required_with:meta.regions.*.cnn', 'string', 'max:32'],
            'meta.regions.*.cnn.confidence' => ['required_with:meta.regions.*.cnn', 'numeric', 'between:0,1'],
        ];
    }

    /**
     * Specific messages first, then a Thai line for every rule used above so
     * no answer falls back to Laravel's English text (the app shows the
     * message of a 422 to the teacher as the reason the scan was refused).
     *
     * @return array<string, string|array<string, string>>
     */
    private static function messages(): array
    {
        return [
            'meta.required' => 'ไม่มีข้อมูล meta ของสแกน',
            'meta.array' => 'meta ต้องเป็น JSON object',
            'meta.client_scan_id.required' => 'ไม่มี client_scan_id',
            'meta.client_scan_id.uuid' => 'client_scan_id ต้องเป็น UUID',
            'meta.qr.required' => 'ไม่มีข้อความ QR ของใบงาน',
            'meta.scanned_at.required' => 'ไม่มีเวลาที่สแกน',
            'meta.scanned_at.date' => 'เวลาที่สแกนไม่ถูกต้อง',
            'meta.scanned_at.after' => 'เวลาที่สแกนไม่ถูกต้อง',
            'meta.blur_score.required' => 'ไม่มีค่าความคมชัดของภาพ (blur_score)',
            'meta.regions.required' => 'ไม่มีข้อมูลช่องคำตอบ (regions)',
            'meta.regions.list' => 'regions ต้องเป็น array',
            'meta.regions.max' => 'ช่องคำตอบในหนึ่งหน้ามีได้ไม่เกิน '.self::MAX_REGIONS.' ช่อง',
            'meta.regions.*.region_id.distinct' => 'region_id ซ้ำกัน',
            'meta.regions.*.file.required' => 'ช่องคำตอบต้องอ้างถึงไฟล์ crop',
            'meta.regions.*.file.regex' => 'ชื่อไฟล์ crop ไม่ถูกต้อง',
            'meta.regions.*.final_file.regex' => 'ชื่อไฟล์ crop ของกรอบคำตอบสุดท้ายไม่ถูกต้อง',
            'meta.regions.*.ink_ratio.between' => 'ink_ratio ต้องอยู่ระหว่าง 0–1',
            'meta.regions.*.mcq_fill.*.between' => 'ค่าการฝนต้องอยู่ระหว่าง 0–1',
            'meta.regions.*.cnn.confidence.between' => 'ความมั่นใจของตัวอ่านเลขต้องอยู่ระหว่าง 0–1',

            // Rule-level fallbacks for this validator only.
            'required' => 'ไม่มี :attribute',
            'required_with' => 'ไม่มี :attribute',
            'array' => ':attribute ต้องเป็น JSON object',
            'list' => ':attribute ต้องเป็น array',
            'string' => ':attribute ต้องเป็นข้อความ',
            'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
            'numeric' => ':attribute ต้องเป็นตัวเลข',
            'uuid' => ':attribute ต้องเป็น UUID',
            'date' => ':attribute ต้องเป็นวันเวลาที่ถูกต้อง',
            'after' => ':attribute ต้องเป็นวันเวลาที่ถูกต้อง',
            'regex' => ':attribute มีรูปแบบไม่ถูกต้อง',
            'not_in' => ':attribute ใช้ค่านี้ไม่ได้',
            'distinct' => ':attribute ซ้ำกัน',
            'min' => [
                'numeric' => ':attribute ต้องไม่น้อยกว่า :min',
                'string' => ':attribute ต้องยาวอย่างน้อย :min ตัวอักษร',
                'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
            ],
            'max' => [
                'numeric' => ':attribute ต้องไม่เกิน :max',
                'string' => ':attribute ยาวได้ไม่เกิน :max ตัวอักษร',
                'array' => ':attribute มีได้ไม่เกิน :max รายการ',
            ],
            'between' => [
                'numeric' => ':attribute ต้องอยู่ระหว่าง :min–:max',
                'string' => ':attribute ต้องยาว :min–:max ตัวอักษร',
                'array' => ':attribute ต้องมี :min–:max รายการ',
            ],
        ];
    }

    /**
     * Names used for :attribute in the messages above.
     *
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return [
            'meta' => 'ข้อมูล meta ของสแกน',
            'meta.client_scan_id' => 'client_scan_id',
            'meta.qr' => 'ข้อความ QR ของใบงาน',
            'meta.scanned_at' => 'เวลาที่สแกน',
            'meta.blur_score' => 'ค่าความคมชัดของภาพ (blur_score)',
            'meta.regions' => 'ข้อมูลช่องคำตอบ (regions)',
            'meta.regions.*' => 'ช่องคำตอบ',
            'meta.regions.*.region_id' => 'region_id ของช่องคำตอบ',
            'meta.regions.*.question_id' => 'question_id ของช่องคำตอบ',
            'meta.regions.*.file' => 'ชื่อไฟล์ crop',
            'meta.regions.*.final_file' => 'ชื่อไฟล์ crop ของกรอบคำตอบสุดท้าย',
            'meta.regions.*.ink_ratio' => 'ink_ratio',
            'meta.regions.*.mcq_fill' => 'ค่าการฝน (mcq_fill)',
            'meta.regions.*.mcq_fill.*' => 'ค่าการฝนของตัวเลือก',
            'meta.regions.*.cnn' => 'ผลของตัวอ่านเลข (cnn)',
            'meta.regions.*.cnn.text' => 'ข้อความจากตัวอ่านเลข',
            'meta.regions.*.cnn.confidence' => 'ความมั่นใจของตัวอ่านเลข',
        ];
    }

    /**
     * @param  list<ScanRegion>  $regions
     */
    private static function assertDistinctFiles(array $regions): void
    {
        $seen = [];
        $errors = [];
        foreach ($regions as $i => $region) {
            foreach (['file' => $region->file, 'final_file' => $region->finalFile] as $key => $field) {
                if ($field === null) {
                    continue;
                }
                if (isset($seen[$field])) {
                    $errors["meta.regions.{$i}.{$key}"][] = "ไฟล์ {$field} ถูกอ้างซ้ำ";
                }
                $seen[$field] = true;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(Request $request, bool $strict): mixed
    {
        $raw = $request->input('meta');
        if (is_array($raw)) {
            return $raw; // sent as meta[...] form fields instead of a JSON string
        }
        if (! is_string($raw) || $raw === '') {
            if ($strict) {
                throw ValidationException::withMessages(['meta' => ['ไม่มีข้อมูล meta ของสแกน']]);
            }

            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($strict) {
                throw ValidationException::withMessages(['meta' => ['meta ต้องเป็น JSON ที่ถูกต้อง']]);
            }

            return null;
        }

        if (! is_array($decoded)) {
            if ($strict) {
                throw ValidationException::withMessages(['meta' => ['meta ต้องเป็น JSON object']]);
            }

            return null;
        }

        return $decoded;
    }
}
