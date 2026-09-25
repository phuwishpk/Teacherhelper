<?php

namespace Tests\Feature\Scans;

use App\Domain\Worksheets\QrSigner;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Layout;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A two-page assignment with a hand-written layout v1 (DESIGN §5.3):
 *   page 1: q{mcq} (mcq, key B, 1 pt) + q{short} (numeric box, 2 pts)
 *   page 2: q{work} (show_work lines + final-answer box, 5 pts) + q{open} (lines, 4 pts)
 * and helpers that build POST /scans multipart requests the way the app does
 * (app/lib/features/scan/scan_meta.dart).
 */
trait ScanFixtures
{
    protected User $teacher;

    protected Classroom $classroom;

    protected User $student;

    protected Assignment $assignment;

    protected Question $mcq;

    protected Question $short;

    protected Question $work;

    protected Question $open;

    protected function makeScanWorld(): void
    {
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->student = $this->enrollStudent($this->classroom, 12, 'ด.ญ. ทดสอบ')['student'];
        $this->assignment = Assignment::factory()->for_classroom($this->classroom)->create([
            'status' => Assignment::STATUS_READY,
            'current_layout_version' => 1,
        ]);
        $this->mcq = Question::factory()->create(['assignment_id' => $this->assignment->id]);
        $this->short = Question::factory()->short()->create(['assignment_id' => $this->assignment->id]);
        $this->work = Question::factory()->showWork()->create(['assignment_id' => $this->assignment->id]);
        $this->open = Question::factory()->open()->create(['assignment_id' => $this->assignment->id]);

        Layout::create([
            'assignment_id' => $this->assignment->id,
            'version' => 1,
            'pages' => $this->layoutPages(1),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function layoutPages(int $version): array
    {
        $common = [
            'assignment_id' => $this->assignment->id,
            'version' => $version,
            'page_count' => 2,
            'marker' => ['dictionary' => 'DICT_4X4_50', 'ids' => [0, 1, 2, 3], 'size_mm' => 12],
            'frame_mm' => ['x' => 16, 'y' => 16, 'w' => 178, 'h' => 265],
        ];

        return [
            [...$common, 'page' => 1, 'regions' => [
                [
                    'region_id' => 'q'.$this->mcq->id, 'question_id' => $this->mcq->id, 'kind' => 'mcq',
                    'rect' => ['x' => 0.08, 'y' => 0.18, 'w' => 0.6, 'h' => 0.05],
                    'bubbles' => array_map(fn (string $o, int $i) => ['option' => $o, 'cx' => 0.12 + 0.12 * $i, 'cy' => 0.205, 'r' => 0.012], ['A', 'B', 'C', 'D'], [0, 1, 2, 3]),
                ],
                [
                    'region_id' => 'q'.$this->short->id, 'question_id' => $this->short->id, 'kind' => 'box', 'numeric' => true,
                    'rect' => ['x' => 0.55, 'y' => 0.27, 'w' => 0.3, 'h' => 0.05],
                ],
            ]],
            [...$common, 'page' => 2, 'regions' => [
                [
                    'region_id' => 'q'.$this->work->id, 'question_id' => $this->work->id, 'kind' => 'lines',
                    'rect' => ['x' => 0.06, 'y' => 0.1, 'w' => 0.88, 'h' => 0.18], 'line_count' => 4,
                    'final_answer' => ['rect' => ['x' => 0.62, 'y' => 0.3, 'w' => 0.3, 'h' => 0.05], 'numeric' => true],
                ],
                [
                    'region_id' => 'q'.$this->open->id, 'question_id' => $this->open->id, 'kind' => 'lines',
                    'rect' => ['x' => 0.06, 'y' => 0.4, 'w' => 0.88, 'h' => 0.2], 'line_count' => 5,
                ],
            ]],
        ];
    }

    protected function qr(int $page = 1, int $version = 1, ?int $studentId = null, ?int $assignmentId = null): string
    {
        return app(QrSigner::class)->sign($assignmentId ?? $this->assignment->id, $studentId ?? $this->student->id, $page, $version);
    }

    /**
     * meta for one page as the app builds it.
     *
     * @param  array<string, float>  $fill
     * @return array<string, mixed>
     */
    protected function metaFor(int $page = 1, array $fill = ['A' => 0.04, 'B' => 0.83, 'C' => 0.06, 'D' => 0.05], ?string $clientScanId = null, ?string $qr = null): array
    {
        $regions = $page === 1
            ? [
                ['region_id' => 'q'.$this->mcq->id, 'question_id' => $this->mcq->id, 'file' => 'crop_q'.$this->mcq->id, 'mcq_fill' => $fill],
                ['region_id' => 'q'.$this->short->id, 'question_id' => $this->short->id, 'file' => 'crop_q'.$this->short->id,
                    'ink_ratio' => 0.08, 'cnn' => ['text' => '20', 'confidence' => 0.97]],
            ]
            : [
                ['region_id' => 'q'.$this->work->id, 'question_id' => $this->work->id, 'file' => 'crop_q'.$this->work->id,
                    'ink_ratio' => 0.12, 'final_file' => 'crop_q'.$this->work->id.'_final', 'cnn' => ['text' => '5', 'confidence' => 0.91]],
                ['region_id' => 'q'.$this->open->id, 'question_id' => $this->open->id, 'file' => 'crop_q'.$this->open->id, 'ink_ratio' => 0.2],
            ];

        return [
            'client_scan_id' => $clientScanId ?? (string) Str::uuid(),
            'qr' => $qr ?? $this->qr($page),
            'scanned_at' => '2026-09-20T09:15:00+07:00',
            'blur_score' => 182.4,
            'regions' => $regions,
        ];
    }

    /**
     * The multipart files named in $meta plus the page image.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, UploadedFile>
     */
    protected function filesFor(array $meta, string $crop = 'crop.webp'): array
    {
        $files = ['page' => $this->webp('page.webp')];
        foreach ($meta['regions'] as $region) {
            $files[$region['file']] = $this->webp($crop);
            if (isset($region['final_file'])) {
                $files[$region['final_file']] = $this->webp('crop_alt.webp');
            }
        }

        return $files;
    }

    protected function webp(string $fixture): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($fixture, $this->fixtureBytes($fixture));
    }

    protected function fixtureBytes(string $fixture): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/scans/'.$fixture));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, UploadedFile>|null  $files
     */
    protected function postScan(array $meta, ?array $files = null, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->teacher)->post(
            '/api/v1/scans',
            ['meta' => json_encode($meta), ...($files ?? $this->filesFor($meta))],
            ['Accept' => 'application/json'],
        );
    }
}
