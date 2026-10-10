<?php

namespace Tests\Feature\Api;

use App\Domain\Courses\CourseDocumentResult;
use App\Domain\Courses\CourseDocuments;
use App\Domain\Courses\IndicatorMatcher;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiRequest;
use App\Models\AiCall;
use App\Models\Course;
use App\Models\DocumentExtraction;
use App\Models\LessonPlan;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Tests\TestCase;

/**
 * DESIGN §20.1, §20.7, §20.10, §21.2: a course description, course
 * structure or lesson plans read once by Gemini (document_read, cached for
 * the school by the files' SHA-256), indicator codes matched to skills
 * after normalising spaces and dots, and the teacher's confirmed form
 * imported in one transaction. Gemini is the FakeGeminiClient.
 */
class CourseDocumentTest extends TestCase
{
    use RefreshDatabase;

    private FakeGeminiClient $gemini;

    private User $teacher;

    private Subject $math;

    private Skill $p51;

    private Skill $p52;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
        config([
            'services.gemini.price_input_per_m' => '0.50',
            'services.gemini.price_output_per_m' => '3.00',
            'eduvision.usd_thb_rate' => '33',
            'services.gemini.media.document' => 'medium',
        ]);

        $this->teacher = $this->makeTeacher();
        $this->math = Subject::query()->updateOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);
        $standard = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1.1', 'level' => Skill::LEVEL_STANDARD, 'grade_level' => null]);
        $this->p51 = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/1', 'grade_level' => 5]);
        $this->p52 = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/2', 'grade_level' => 5]);
    }

    private static function photo(string $content = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('course.jpg', "\xFF\xD8\xFF\xE0JFIF course ".$content.' '.bin2hex(random_bytes(4)));
    }

    private static function pdf(int $pages): UploadedFile
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/testing')]);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML("<p>page {$i}</p>");
        }

        return UploadedFile::fake()->createWithContent('plans.pdf', $mpdf->Output('', 'S'));
    }

    /** @return list<int> */
    private function documents(array $files, ?User $teacher = null): array
    {
        return array_column($this->asUser($teacher ?? $this->teacher)->post('/api/v1/documents', ['files' => $files], ['Accept' => 'application/json'])->assertCreated()->json('data'), 'id');
    }

    /** @return list<GeminiRequest> */
    private function sent(): array
    {
        return array_values(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === 'document_read'));
    }

    public function test_a_course_document_is_read_once_matched_to_indicators_and_cached_for_the_school(): void
    {
        $file = self::photo();
        $bytes = (string) file_get_contents($file->getRealPath());
        $ids = $this->documents([$file]);

        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract/estimate', ['document_ids' => $ids, 'purpose' => 'course'])
            ->assertOk()
            ->assertJsonPath('data.purpose', 'course')
            ->assertJsonPath('data.pages', 1)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.estimate.input_tokens', 2060);

        $queued = $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'course'])
            ->assertStatus(202)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.estimate.input_tokens', 2060)
            ->assertJsonPath('data.extraction.purpose', 'course');
        $this->assertCount(1, $this->sent());
        $request = $this->sent()[0];
        $this->assertSame(['medium', 16384, 'medium'], [$request->thinkingLevel, $request->maxOutputTokens, $request->images[0]->mediaResolution]);
        $this->assertStringContainsString('course description', $request->userText);

        // The queue is sync in tests: the read is done; the app polls the extraction.
        $id = $queued->json('data.extraction.id');
        $poll = $this->asUser($this->teacher)->getJson("/api/v1/document-extractions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result.course.code', 'ค15101')
            ->assertJsonPath('data.result.units.1.title', 'ทศนิยม')
            ->assertJsonPath('data.result.lesson_plans.2.unit_position', 2)
            ->json('data');
        $matches = array_column($poll['indicator_matches'], 'skill', 'code');
        $this->assertSame($this->p51->id, $matches['ค 1.1 ป.5/1']['id']);
        $this->assertSame($this->p52->id, $matches['ค1.1 ป.๕/๒']['id'], 'spaces, dots and Thai digits are normalised');
        $this->assertNull($matches['ค 9.9 ป.5/9'], 'a code the curriculum lacks is left for the teacher');

        $call = AiCall::query()->where('purpose', 'document_read')->sole();
        $this->assertSame(['course_import', 'medium'], [$call->feature, $call->media_resolution]);

        // A colleague uploads the same file: the school's read comes back free, without Gemini.
        $colleague = $this->makeTeacher($this->teacher->school);
        $again = $this->documents([UploadedFile::fake()->createWithContent('same.jpg', $bytes)], $colleague);
        $this->asUser($colleague)->postJson('/api/v1/courses/extract/estimate', ['document_ids' => $again, 'purpose' => 'course'])->assertOk()->assertJsonPath('data.cached', true);
        config(['services.gemini.api_key' => '']);
        $this->asUser($colleague)->postJson('/api/v1/courses/extract', ['document_ids' => $again, 'purpose' => 'course'])
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.estimate', null)
            ->assertJsonPath('data.result.course.name', 'คณิตศาสตร์ 5')
            ->assertJsonCount(3, 'data.indicator_matches');
        $this->assertCount(1, $this->sent());
        // The same files read as lesson plans are another read.
        $this->asUser($colleague)->postJson('/api/v1/courses/extract', ['document_ids' => $again, 'purpose' => 'lesson_plan'])->assertStatus(422)->assertJsonPath('code', 'ai_key_missing');

        // Another school does not share the cache nor see the extraction.
        $this->asUser($this->makeTeacher())->getJson("/api/v1/document-extractions/{$id}")->assertNotFound();
    }

    public function test_a_teacher_added_indicator_is_matched_once_it_exists(): void
    {
        $ids = $this->documents([self::photo()]);
        $id = $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'course'])->json('data.extraction.id');
        $standard = Skill::query()->where('code', 'ค 1.1')->firstOrFail();
        $this->asUser($this->teacher)->postJson('/api/v1/skills', ['parent_id' => $standard->id, 'name' => 'ตัวที่ขาด', 'code' => 'ค 9.9 ป.5/9'])->assertCreated();

        $matches = array_column($this->asUser($this->teacher)->getJson("/api/v1/document-extractions/{$id}")->json('data.indicator_matches'), 'skill', 'code');
        $this->assertSame('ครูเพิ่มเอง', $matches['ค 9.9 ป.5/9']['source_label']);
    }

    public function test_lesson_plans_are_read_with_the_same_rules_and_page_cap(): void
    {
        $long = $this->documents([self::pdf(31)]);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $long, 'purpose' => 'lesson_plan'])
            ->assertStatus(422)->assertJsonPath('code', 'document_too_long');
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract/estimate', ['document_ids' => $long, 'purpose' => 'lesson_plan', 'page_from' => 1, 'page_to' => 31])
            ->assertStatus(422)->assertJsonPath('code', 'document_too_long');
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract/estimate', ['document_ids' => $long, 'purpose' => 'lesson_plan', 'page_from' => 3, 'page_to' => 12])
            ->assertOk()->assertJsonPath('data.pages', 10)->assertJsonPath('data.estimate.input_tokens', 10 * 560 + 1500);

        $ids = $this->documents([self::pdf(2)]);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'lesson_plan'])->assertStatus(202);
        $extraction = DocumentExtraction::query()->where('purpose', 'lesson_plan')->sole();
        $this->assertSame([null, 3], [$extraction->result['course']['name'], count($extraction->result['lesson_plans'])]);
        $this->assertStringContainsString('lesson plans', $this->sent()[0]->userText);

        foreach ([['purpose' => 'syllabus', 'document_ids' => $ids], ['purpose' => 'course'], ['purpose' => 'course', 'document_ids' => [999999]]] as $body) {
            $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_a_failed_read_is_reported_and_can_be_asked_again(): void
    {
        $ids = $this->documents([self::photo('[fake:empty]')]);
        $id = $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'course'])->assertStatus(202)->json('data.extraction.id');
        $this->asUser($this->teacher)->getJson("/api/v1/document-extractions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result', null)
            ->assertJsonPath('data.indicator_matches', []);
        $this->assertStringContainsString('AI อ่านเอกสารไม่สำเร็จ', (string) DocumentExtraction::query()->findOrFail($id)->error);
        $this->assertCount(2, $this->sent(), 'invalid output is retried once');

        // Asking again queues the same row again.
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'course'])->assertStatus(202)->assertJsonPath('data.extraction.id', $id);
        $this->assertCount(4, $this->sent());

        // A transport error is retried by the queue; after the last try the read is failed.
        $broken = $this->documents([self::photo('[fake:error]')]);
        $row = DocumentExtraction::create(['school_id' => $this->teacher->school_id, 'input_hash' => str_repeat('c', 64), 'purpose' => 'course', 'requested_by' => $this->teacher->id]);
        try {
            app(CourseDocuments::class)->process($row->id, 'course', $broken, null, null, $this->teacher->id, lastAttempt: false);
            $this->fail('a transient error must reach the queue');
        } catch (GeminiException) {
            $this->assertSame(DocumentExtraction::STATUS_QUEUED, $row->refresh()->status);
        }
        app(CourseDocuments::class)->process($row->id, 'course', $broken, null, null, $this->teacher->id, lastAttempt: true);
        $this->assertSame([DocumentExtraction::STATUS_FAILED, 'ติดต่อ AI ไม่ได้ ลองใหม่อีกครั้งภายหลัง'], [$row->refresh()->status, $row->error]);

        // Without any usable key the job fails at once.
        config(['services.gemini.api_key' => '']);
        $row->forceFill(['status' => DocumentExtraction::STATUS_QUEUED])->save();
        app(CourseDocuments::class)->process($row->id, 'course', $broken, null, null, $this->teacher->id, lastAttempt: false);
        $this->assertStringContainsString('Gemini API key', (string) $row->refresh()->error);
    }

    public function test_the_confirmed_form_imports_a_course_with_units_and_plans_in_one_transaction(): void
    {
        $room = $this->makeClassroom($this->teacher);
        $ids = $this->documents([self::photo()]);
        $extractionId = $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'course'])->json('data.extraction.id');

        $body = [
            'extraction_id' => $extractionId,
            'course' => ['code' => 'ค15101', 'name' => 'คณิตศาสตร์ 5', 'subject_id' => $this->math->id, 'grade_level' => 5, 'academic_year' => 2569, 'hours' => 160],
            'classroom_ids' => [$room->id],
            'skill_ids' => [$this->p51->id, $this->p52->id],
            'units' => [
                ['title' => 'เศษส่วน', 'hours' => 20, 'skill_ids' => [$this->p51->id]],
                ['title' => 'ทศนิยม', 'hours' => 16],
            ],
            'lesson_plans' => [
                ['title' => 'การบวกเศษส่วน', 'unit_index' => 0, 'objectives' => 'บวกได้', 'skill_ids' => [$this->p51->id]],
                ['title' => 'การลบเศษส่วน', 'unit_index' => 0],
                ['title' => 'ทศนิยม', 'unit_index' => 1, 'skill_ids' => [$this->p52->id]],
            ],
        ];

        // Any bad row rolls back everything.
        $bad = $body;
        $bad['lesson_plans'][2]['skill_ids'] = [999999];
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', $bad)->assertStatus(422)->assertJsonValidationErrors(['lesson_plans.2.skill_ids.0']);
        $bad = $body;
        $bad['units'][1]['title'] = '';
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', $bad)->assertStatus(422)->assertJsonValidationErrors(['units.1.title']);
        $bad = $body;
        $bad['lesson_plans'][1]['unit_index'] = 5;
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', $bad)->assertStatus(422)->assertJsonValidationErrors(['lesson_plans.1.unit_index']);
        $bad = $body;
        unset($bad['course']['name']);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', $bad)->assertStatus(422)->assertJsonValidationErrors(['course.name']);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', ['extraction_id' => 999999] + $body)->assertStatus(422)->assertJsonValidationErrors(['extraction_id']);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', ['course_id' => 1] + $body)->assertStatus(422)->assertJsonValidationErrors(['course']);
        $this->assertSame([0, 0, 0], [Course::query()->count(), Unit::query()->count(), LessonPlan::query()->count()]);

        $course = $this->asUser($this->teacher)->postJson('/api/v1/courses/import', $body)
            ->assertCreated()
            ->assertJsonPath('data.code', 'ค15101')
            ->assertJsonPath('data.classroom_ids', [$room->id])
            ->assertJsonCount(2, 'data.indicators')
            ->assertJsonCount(2, 'data.units')
            ->assertJsonCount(3, 'data.lesson_plans')
            ->json('data');
        [$u1, $u2] = array_column($course['units'], 'id');
        $this->assertSame([$u1, $u1, $u2], array_column($course['lesson_plans'], 'unit_id'));
        $this->assertSame([1, 2, 3], array_column($course['lesson_plans'], 'position'));
        $this->assertSame('บวกได้', $course['lesson_plans'][0]['objectives']);

        // Lesson plans read later are added to the existing course, after its plans.
        $more = $this->asUser($this->teacher)->postJson('/api/v1/courses/import', [
            'course_id' => $course['id'],
            'skill_ids' => [$this->p51->id],
            'lesson_plans' => [['title' => 'โจทย์ปัญหาเศษส่วน', 'unit_id' => $u1], ['title' => 'ทบทวน']],
        ])->assertCreated()->json('data');
        $this->assertSame([4, 5], array_slice(array_column($more['lesson_plans'], 'position'), 3));
        $this->assertSame([$u1, null], array_slice(array_column($more['lesson_plans'], 'unit_id'), 3));
        $this->assertCount(2, $more['indicators'], 'indicators are added, not replaced');

        // Units of another course, or of none, are refused.
        $other = $this->makeCourse($this->teacher, [], ['code' => 'ค15102']);
        $foreignUnit = Unit::create(['course_id' => $other->id, 'position' => 1, 'title' => 'x']);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/import', ['course_id' => $course['id'], 'lesson_plans' => [['title' => 'x', 'unit_id' => $foreignUnit->id]]])
            ->assertStatus(422)->assertJsonValidationErrors(['lesson_plans.0.unit_id']);
        $this->asUser($this->makeTeacher($this->teacher->school))->postJson('/api/v1/courses/import', ['course_id' => $course['id'], 'lesson_plans' => [['title' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors(['course_id']);
    }

    public function test_codes_are_normalised_for_spaces_dots_and_thai_digits(): void
    {
        $this->assertSame('ค11ป5/1', IndicatorMatcher::normalize(' ค 1.1  ป.5/1 '));
        $this->assertSame(IndicatorMatcher::normalize('ค 1.1 ป.5/1'), IndicatorMatcher::normalize('ค ๑.๑ ป.๕/๑'));

        $result = CourseDocumentResult::fromGemini('course', [
            'indicators' => [['code' => ' ค 1.1 ป.5/1 '], ['code' => 'ค 1.1 ป.5/1'], ['code' => '']],
            'units' => [['position' => 7, 'title' => 'หน่วย ก'], ['title' => '']],
            'lesson_plans' => [['title' => 'แผน', 'unit_position' => 7, 'hours' => -1], ['title' => 'แผนสอง', 'unit_position' => 3]],
        ]);
        $this->assertSame([['code' => 'ค 1.1 ป.5/1', 'text' => null]], $result['indicators']);
        $this->assertSame([1, null], [$result['units'][0]['position'], $result['lesson_plans'][0]['hours']]);
        $this->assertSame([1, null], array_column($result['lesson_plans'], 'unit_position'));
        $this->assertSame(['ค 1.1 ป.5/1'], CourseDocumentResult::allCodes($result));
    }
}
