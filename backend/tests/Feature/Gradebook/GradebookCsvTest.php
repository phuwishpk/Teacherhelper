<?php

namespace Tests\Feature\Gradebook;

use App\Domain\Gradebook\GradebookCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DESIGN §23.8: the CSV export (BOM, Thai headers, CRLF, formula injection, in progress). */
class GradebookCsvTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld(2);
    }

    public function test_a_complete_classroom_exports_points_totals_and_grades(): void
    {
        $cats = $this->useTemplate('collect_final');
        [$a, $b] = $this->students;
        $a->forceFill(['name' => '=HYPERLINK("http://evil")'])->save();
        $collect = $this->item('งาน, "พิเศษ"', 10, $cats['คะแนนเก็บ']);
        $final = $this->item('สอบ', 10, $cats['ปลายภาค']);
        $this->putItemScores($collect, [['student_id' => $a->id, 'score' => 7.5], ['student_id' => $b->id, 'score' => 10]])->assertOk();
        $this->putItemScores($final, [['student_id' => $a->id, 'score' => 9], ['student_id' => $b->id, 'score' => 5]])->assertOk();
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/special-grades", [
            'classroom_id' => $this->classroom->id, 'student_id' => $b->id, 'special' => 'ms',
        ])->assertOk();

        $response = $this->asUser($this->teacher)->get("/api/v1/courses/{$this->course->id}/gradebook/export?classroom_id={$this->classroom->id}")->assertOk();

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('gradebook-ค15101-ป.5-1.csv'), (string) $response->headers->get('Content-Disposition'));
        $body = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = explode("\r\n", substr($body, 3));
        $this->assertSame('', end($lines));
        $this->assertSame(['เลขที่', 'ชื่อ', 'คะแนนเก็บ (70)', 'ปลายภาค (30)', 'รวม', 'เกรด'], self::csv($lines[0]));
        // 7.5/10 × 70 = 52.5, 9/10 × 30 = 27 → 79.5 → 80 → 4; the name is defused and quoted (RFC 4180).
        $this->assertSame('1,"\'=HYPERLINK(""http://evil"")",52.50,27.00,80,4', $lines[1]);
        // 70 + 15 = 85, but มส replaces the grade.
        $this->assertSame(['2', 'เด็กหญิงสอง รักเรียน', '70.00', '15.00', '85', 'มส'], self::csv($lines[2]));
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $body));
    }

    public function test_an_incomplete_classroom_exports_the_in_progress_percent_without_grades(): void
    {
        $cats = $this->useTemplate('collect_final');
        $collect = $this->item('งาน', 10, $cats['คะแนนเก็บ']);
        $this->putItemScores($collect, [['student_id' => $this->students[0]->id, 'score' => 6]])->assertOk();

        $body = $this->asUser($this->teacher)->get("/api/v1/courses/{$this->course->id}/gradebook/export?classroom_id={$this->classroom->id}")->assertOk()->getContent();
        $lines = explode("\r\n", substr($body, 3));

        $this->assertSame(['เลขที่', 'ชื่อ', 'คะแนนเก็บ (70)', 'ปลายภาค (30)', 'รวมระหว่างภาค (ร้อยละ)', 'เกรด'], self::csv($lines[0]));
        $this->assertSame(['1', 'เด็กชายหนึ่ง ใจดี', '42.00', '', '60.00', ''], self::csv($lines[1]));
        $this->assertSame(['2', 'เด็กหญิงสอง รักเรียน', '0.00', '', '0.00', ''], self::csv($lines[2]));
    }

    public function test_an_unconfigured_course_cannot_export_and_text_is_defused(): void
    {
        $this->asUser($this->teacher)->get("/api/v1/courses/{$this->course->id}/gradebook/export?classroom_id={$this->classroom->id}")
            ->assertStatus(409)->assertJsonPath('code', 'gradebook_not_configured');

        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $text) {
            $this->assertSame("'".$text, GradebookCsv::text($text));
        }
        $this->assertSame('ปกติ', GradebookCsv::text('ปกติ'));
        $this->assertSame('gradebook-ค15101-ม.1-2.csv', GradebookCsv::fileName('ค15101', 'ม.1/2'));
    }

    /** @return list<string|null> */
    private static function csv(string $line): array
    {
        return str_getcsv($line, ',', '"', '');
    }
}
