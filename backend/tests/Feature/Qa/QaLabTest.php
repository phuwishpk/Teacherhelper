<?php

namespace Tests\Feature\Qa;

use App\Domain\Qa\CiStatus;
use App\Domain\Qa\QaCatalog;
use App\Domain\Qa\TestIndex;
use App\Filament\Qa\Pages\MyResults;
use App\Filament\Qa\Pages\QaOverview;
use App\Filament\Qa\Pages\SusSurvey;
use App\Models\QaResult;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Krucheck Test Lab at /qa (DESIGN §39): its own accounts, every page,
 * results a tester writes only for themselves, SUS, the test index and the
 * CI status read from GitHub.
 */
class QaLabTest extends TestCase
{
    use RefreshDatabase;

    private QaTester $lead;

    private QaTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        TestIndex::flush();
        $this->lead = QaTester::create(['name' => 'หัวหน้าทดสอบ', 'email' => 'lead@qa.test', 'password' => 'secret1234', 'is_lead' => true]);
        $this->tester = QaTester::create(['name' => 'ผู้ทดสอบหนึ่ง', 'email' => 'one@qa.test', 'password' => 'secret1234']);
    }

    private function fakeGithub(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['workflow_runs' => [
            ['name' => 'backend', 'conclusion' => 'success', 'status' => 'completed', 'head_sha' => 'abcdef1234567', 'display_title' => 'feat: something', 'created_at' => '2026-10-22T04:47:43Z', 'html_url' => 'https://github.com/x/runs/1'],
            ['name' => 'app', 'conclusion' => 'failure', 'status' => 'completed', 'head_sha' => 'abcdef1234567', 'display_title' => 'feat: something', 'created_at' => '2026-10-22T04:47:43Z', 'html_url' => 'https://github.com/x/runs/2'],
            ['name' => 'backend', 'conclusion' => 'failure', 'status' => 'completed', 'head_sha' => '0000000', 'display_title' => 'older', 'created_at' => '2026-10-21T04:47:43Z', 'html_url' => 'https://github.com/x/runs/0'],
        ]])]);
    }

    private function asQa(QaTester $who): static
    {
        $this->forgetGuards();
        Filament::setCurrentPanel(Filament::getPanel('qa'));

        return $this->actingAs($who, 'qa');
    }

    public function test_the_catalog_is_complete_and_points_at_real_files(): void
    {
        $codes = array_column(QaCatalog::cases(), 'code');
        $this->assertSame(count($codes), count(array_unique($codes)), 'codes are unique');
        $this->assertCount(16, QaCatalog::ofKind(QaCatalog::AUTO));
        $this->assertCount(13, QaCatalog::ofKind(QaCatalog::MANUAL));
        $this->assertCount(13, QaCatalog::ofKind(QaCatalog::USABILITY));
        foreach (QaCatalog::ofKind(QaCatalog::AUTO) as $case) {
            $this->assertContains($case['workflow'], CiStatus::WORKFLOWS, $case['code']);
            foreach ($case['refs'] as $ref) {
                $this->assertFileExists(TestIndex::repoPath($ref), "{$case['code']} names {$ref}");
                $this->assertNotEmpty(TestIndex::forRef($ref), "{$ref} has tests");
            }
        }
        foreach (QaCatalog::ofKind(QaCatalog::MANUAL) as $case) {
            $this->assertNotEmpty($case['steps']);
            $this->assertNotEmpty($case['why']);
        }
    }

    public function test_sus_scoring_follows_brooke(): void
    {
        $this->assertSame(100.0, QaCatalog::susScore([5, 1, 5, 1, 5, 1, 5, 1, 5, 1]));
        $this->assertSame(0.0, QaCatalog::susScore([1, 5, 1, 5, 1, 5, 1, 5, 1, 5]));
        $this->assertSame(50.0, QaCatalog::susScore(array_fill(0, 10, 3)));
        $this->assertNull(QaCatalog::susScore([5, 1, 5]));
        $this->assertNull(QaCatalog::susScore([5, 1, 5, 1, 5, 1, 5, 1, 5, 6]));
    }

    public function test_the_test_index_reads_php_dart_and_workflows(): void
    {
        $this->assertSame(['a teacher signs in', 'nothing else'], TestIndex::parse('x.php', "<?php\nfunction test_a_teacher_signs_in(): void {}\npublic function test_nothing_else() {}\nfunction helper() {}"));
        $this->assertSame(['joined across lines', 'single'], TestIndex::parse('x.dart', "testWidgets(\n  'joined '\n  'across lines',\n  (t) async {});\n  test('single', () {});"));
        $this->assertSame(['analyze, test', 'debug APK'], TestIndex::parse('w.yml', "name: app\njobs:\n  test:\n    name: analyze, test\n  apk:\n    name: debug APK\n"));
    }

    public function test_without_the_files_the_index_reads_the_deploy_manifest(): void
    {
        $manifest = base_path(TestIndex::MANIFEST);
        $had = is_file($manifest) ? file_get_contents($manifest) : null;
        try {
            file_put_contents($manifest, json_encode(['refs' => ['backend/tests/Feature/Gone/GoneTest.php' => ['from the manifest']]]));
            TestIndex::flush();
            $this->assertSame(['from the manifest'], TestIndex::forRef('backend/tests/Feature/Gone/GoneTest.php'));
            $this->assertNull(TestIndex::forRef('backend/tests/Feature/Gone/Unknown.php'));
        } finally {
            $had === null ? @unlink($manifest) : file_put_contents($manifest, $had);
            TestIndex::flush();
        }
    }

    public function test_the_index_command_writes_every_auto_ref(): void
    {
        $out = sys_get_temp_dir().'/qa-index-'.uniqid().'.json';
        $this->artisan('eduvision:qa-index', ['--output' => $out])->assertSuccessful();
        $refs = json_decode((string) file_get_contents($out), true)['refs'];
        @unlink($out);
        $this->assertArrayHasKey('backend/tests/Feature/Classrooms/ClassJoinTest.php', $refs);
        $this->assertContains('debug APK', $refs['.github/workflows/app.yml']);
    }

    public function test_ci_status_takes_the_newest_run_of_each_workflow_and_caches_it(): void
    {
        $this->fakeGithub();
        $ci = CiStatus::latest();
        $this->assertSame('abcdef1', $ci['sha']);
        $this->assertSame('success', $ci['workflows']['backend']['conclusion']);
        $this->assertSame('failure', $ci['workflows']['app']['conclusion']);
        $this->assertArrayNotHasKey('deploy', $ci['workflows']);
        CiStatus::latest();
        Http::assertSentCount(1);
        $this->assertNull(CiStatus::deployedCommit(), 'no deploy tree here');
    }

    public function test_a_github_outage_is_null_and_retried_after_a_minute(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 503)]);
        $this->assertNull(CiStatus::latest());
        $this->assertNull(CiStatus::latest());
        Http::assertSentCount(1);
    }

    public function test_only_test_lab_accounts_open_qa_and_they_never_open_admin(): void
    {
        $this->fakeGithub();
        $this->get('/qa')->assertRedirect('/qa/login');

        $admin = $this->makeAdmin();
        $this->forgetGuards();
        $this->actingAs($admin, 'web')->get('/qa')->assertRedirect('/qa/login');

        $this->asQa($this->tester)->get('/qa')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_every_page_renders_with_details(): void
    {
        $this->fakeGithub();
        QaResult::create(['qa_tester_id' => $this->tester->id, 'case_code' => 'IT-M01', 'status' => 'fail', 'note' => 'AI-QUOTA ทุกแผ่น']);
        QaResult::create(['qa_tester_id' => $this->lead->id, 'case_code' => 'IT-M01', 'status' => 'pass']);
        QaResult::create(['qa_tester_id' => $this->tester->id, 'case_code' => 'UT-1', 'status' => 'assisted', 'time_sec' => 95, 'error_count' => 2]);
        QaSusResponse::create(['qa_tester_id' => $this->tester->id, 'role' => 'teacher', 'answers' => [5, 1, 5, 1, 5, 1, 5, 1, 5, 1], 'score' => 100]);
        $this->asQa($this->tester);

        $this->get('/qa')->assertOk()
            ->assertSee('ภาพรวมการทดสอบ')
            ->assertSee('abcdef1')
            ->assertSee('ผู้ทดสอบหนึ่ง')
            ->assertSee('100')               // SUS mean
            ->assertSee('0 / 1 / 0 จาก 1');   // UT-1: one assisted
        $this->get('/qa/plan')->assertOk()->assertSee('IT-A01')->assertSee('UT-8')->assertSee('เลือกอย่างไรว่าอันไหนอัตโนมัติ');

        $this->get('/qa/cases/IT-A02')->assertOk()
            ->assertSee('backend/tests/Feature/Classrooms/ClassJoinTest.php')
            ->assertSee('a pupil claims the seat the teacher made for them')
            ->assertSee('https://github.com/x/runs/1', false);
        $this->get('/qa/cases/IT-M01')->assertOk()->assertSee('ทำไมต้องทำมือ')->assertSee('AI-QUOTA ทุกแผ่น');
        $this->get('/qa/cases/UT-1')->assertOk()->assertSee('95 วิ');
        $this->get('/qa/cases/NOPE-1')->assertNotFound();
        $this->get('/qa/run')->assertOk()->assertSee('IT-M13')->assertSee('US-5');
        $this->get('/qa/sus')->assertOk()->assertSee('ฉันคิดว่าแอปนี้ใช้งานง่าย');
    }

    public function test_a_tester_saves_only_their_own_results(): void
    {
        QaResult::create(['qa_tester_id' => $this->lead->id, 'case_code' => 'IT-M02', 'status' => 'pass', 'note' => 'ของหัวหน้า']);
        $this->asQa($this->tester);

        Livewire::test(MyResults::class)
            ->set('form.IT_M02.status', 'fail')
            ->set('form.IT_M02.note', 'แสงน้อยแล้วจับมุมไม่ได้')
            ->call('save', 'IT-M02')
            ->assertHasNoErrors()
            ->set('form.UT_3.status', 'success')
            ->set('form.UT_3.time_sec', '240')
            ->set('form.UT_3.error_count', '1')
            ->call('save', 'UT-3')
            ->set('form.IT_M03.status', 'success') // a usability status on a manual case
            ->call('save', 'IT-M03')
            ->assertHasErrors(['form.IT_M03.status'])
            ->call('save', 'IT-A01'); // automated cases are not filled in

        $mine = QaResult::query()->where('qa_tester_id', $this->tester->id)->get()->keyBy('case_code');
        $this->assertSame(['IT-M02', 'UT-3'], $mine->keys()->sort()->values()->all());
        $this->assertSame('แสงน้อยแล้วจับมุมไม่ได้', $mine['IT-M02']->note);
        $this->assertSame(240, $mine['UT-3']->time_sec);
        $this->assertSame(1, $mine['UT-3']->error_count);
        $this->assertSame('ของหัวหน้า', QaResult::query()->where('qa_tester_id', $this->lead->id)->sole()->note);

        // The page loads them back.
        Livewire::test(MyResults::class)->assertSet('form.UT_3.time_sec', 240)->assertSet('form.IT_M02.status', 'fail');
    }

    public function test_the_sus_survey_needs_every_answer_and_stores_the_score(): void
    {
        $this->asQa($this->tester);
        Livewire::test(SusSurvey::class)
            ->set('answers', [4, 2, 4, 2, 4, 2, 4, 2, 4, null])
            ->call('save')
            ->assertHasErrors()
            ->set('answers.9', '2')
            ->set('role', 'student')
            ->set('comment', 'ใช้ง่าย')
            ->call('save')
            ->assertHasNoErrors();

        $row = QaSusResponse::query()->sole();
        $this->assertSame(75.0, $row->score);
        $this->assertSame('student', $row->role);
        $this->assertSame([4, 2, 4, 2, 4, 2, 4, 2, 4, 2], $row->answers);
    }

    public function test_only_the_lead_refreshes_the_ci_status(): void
    {
        $this->fakeGithub();
        $this->asQa($this->tester);
        Livewire::test(QaOverview::class)->call('refreshCi');
        Http::assertSentCount(1); // the first render; the tester's refresh did nothing

        $this->asQa($this->lead);
        Livewire::test(QaOverview::class)->call('refreshCi');
        Http::assertSentCount(2); // the lead's refresh asked GitHub again
    }

    public function test_admins_manage_test_lab_accounts(): void
    {
        $admin = $this->makeAdmin();
        $this->forgetGuards();
        $this->actingAs($admin, 'web')->get('/admin/qa-testers')->assertOk()->assertSee('ผู้ทดสอบหนึ่ง');
    }
}
