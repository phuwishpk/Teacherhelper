<?php

namespace App\Domain\Scans;

/**
 * One entry of `meta.regions` in POST /scans (DESIGN §9.4): the phone's
 * readings of one answer area and the multipart fields of its crops.
 */
final readonly class ScanRegion
{
    /**
     * @param  array<string, float>|null  $mcqFill  option => fill ratio (mcq only)
     */
    public function __construct(
        public string $regionId,
        public int $questionId,
        public string $file,
        public ?string $finalFile,
        public ?array $mcqFill,
        public ?float $inkRatio,
        public ?string $cnnText,
        public ?float $cnnConfidence,
    ) {}

    /**
     * @param  array<string, mixed>  $data  a validated meta.regions entry
     */
    public static function fromValidated(array $data): self
    {
        // An empty `cnn` object means the digit reader abstained, like no `cnn` at all.
        $cnn = is_array($data['cnn'] ?? null) && isset($data['cnn']['text'], $data['cnn']['confidence']) ? $data['cnn'] : null;
        $fill = is_array($data['mcq_fill'] ?? null)
            ? array_map(fn ($v) => (float) $v, $data['mcq_fill'])
            : null;

        return new self(
            regionId: (string) $data['region_id'],
            questionId: (int) $data['question_id'],
            file: (string) $data['file'],
            finalFile: isset($data['final_file']) && $data['final_file'] !== '' ? (string) $data['final_file'] : null,
            mcqFill: $fill,
            inkRatio: isset($data['ink_ratio']) ? (float) $data['ink_ratio'] : null,
            cnnText: $cnn !== null ? (string) $cnn['text'] : null,
            cnnConfidence: $cnn !== null ? (float) $cnn['confidence'] : null,
        );
    }

    /**
     * Serialised form kept next to the crops of a pending_confirm scan.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'region_id' => $this->regionId,
            'question_id' => $this->questionId,
            'file' => $this->file,
            'final_file' => $this->finalFile,
            'mcq_fill' => $this->mcqFill,
            'ink_ratio' => $this->inkRatio,
            'cnn' => $this->cnnText === null ? null : ['text' => $this->cnnText, 'confidence' => $this->cnnConfidence],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  output of toArray()
     */
    public static function fromArray(array $data): self
    {
        return self::fromValidated($data);
    }
}
