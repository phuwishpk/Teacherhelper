<?php

namespace Tests\Feature\Exams;

use App\Domain\Worksheets\QrSigner;
use App\Models\Assignment;
use App\Models\Layout;
use App\Models\Response;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Printed answer sheets and scanned pages of them (DESIGN §22.9–§22.11),
 * for tests that also use ExamTestHelpers.
 */
trait ExamSheetTestHelpers
{
    /**
     * An approved app exam with $mcq 4-option rows and $numeric 2-digit
     * blocks, printed once as answer sheets (layout version 1, locked).
     */
    protected function printedExam(int $mcq = 4, int $numeric = 0, int $versions = 1): Assignment
    {
        $exam = $this->createExam(['version_count' => $versions]);
        while ($mcq > 0) {
            $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => min(100, $mcq)]);
            $mcq -= 100;
        }
        if ($numeric > 0) {
            $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_decimal' => true], 'question_count' => $numeric]);
        }
        $exam = $this->approveExam($exam);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);

        return $exam->refresh();
    }

    protected function layout(Assignment $exam): Layout
    {
        return $exam->refresh()->currentLayout() ?? $this->fail('no layout');
    }

    /**
     * Readings of one layout page: every bubble 0.02 except the marks.
     * $marks: sheet_no => displayed position (int), several (list) or a
     * number (string); $version: the version bubble to mark.
     *
     * @param  array<int, int|list<int>|string>  $marks
     * @return array{version_fill: array<string, float>|null, rows: array<string, array<string, float>>, digits: array<string, mixed>}
     */
    protected function reading(Assignment $exam, int $page, array $marks, ?int $version = null): array
    {
        $layoutPage = $this->layout($exam)->pages[$page - 1];
        $versionFill = null;
        $rows = [];
        $digits = [];
        foreach ($layoutPage['regions'] as $region) {
            if ($region['kind'] === 'version_bubbles') {
                foreach ($region['bubbles'] as $b) {
                    $versionFill[(string) $b['value']] = $b['value'] === $version ? 0.92 : 0.02;
                }
            } elseif ($region['kind'] === 'omr_row') {
                $mark = (array) ($marks[$region['sheet_no']] ?? []);
                foreach ($region['bubbles'] as $b) {
                    $rows[(string) $region['sheet_no']][(string) $b['value']] = in_array($b['value'], $mark, true) ? 0.9 : 0.02;
                }
            } else {
                $text = (string) ($marks[$region['sheet_no']] ?? '');
                $columns = [];
                foreach ($region['columns'] as $i => $column) {
                    $fill = [];
                    foreach ($column['bubbles'] as $b) {
                        $fill[(string) $b['value']] = ($text[$i] ?? null) === (string) $b['value'] ? 0.9 : 0.02;
                    }
                    $columns[] = $fill;
                }
                $digits[(string) $region['sheet_no']] = ['sign' => $region['sign'] === null ? null : 0.02, 'columns' => $columns];
            }
        }

        return ['version_fill' => $versionFill, 'rows' => $rows, 'digits' => $digits];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $extra
     */
    protected function upload(Assignment $exam, User $student, int $page, array $reading, array $extra = []): TestResponse
    {
        $qr = app(QrSigner::class)->signExamSheet($exam->id, $student->id, $page, $this->layout($exam)->version);
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => $qr,
            'scanned_at' => '2026-10-15T03:00:00Z',
            'blur_score' => 150.5,
            ...$reading,
            ...$extra,
        ];

        return $this->asUser($this->teacher)->post('/api/v1/exam-sheets', [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp'))),
        ], ['Accept' => 'application/json']);
    }

    /** @return array<int, Response> by original question position */
    protected function responses(Assignment $exam, User $student): array
    {
        $submission = Submission::query()->where('assignment_id', $exam->id)->where('student_id', $student->id)->firstOrFail();
        $out = [];
        foreach (Response::query()->where('submission_id', $submission->id)->with('question')->get() as $r) {
            $out[$r->question->position] = $r;
        }
        ksort($out);

        return $out;
    }
}
