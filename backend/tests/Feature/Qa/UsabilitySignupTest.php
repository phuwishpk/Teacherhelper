<?php

namespace Tests\Feature\Qa;

use App\Domain\Qa\QaCatalog;
use App\Domain\Qa\QaSignupCode;
use App\Domain\Qa\QaSus;
use App\Filament\Qa\Pages\Testers;
use App\Models\QaResult;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The usability set for outside participants (DESIGN §39.10): they sign
 * themselves up at /usability with a name, an occupation and the group's
 * code, rate the four tasks of the short set, then answer SUS. Nothing
 * else of the Test Lab is theirs.
 */
class UsabilitySignupTest extends TestCase
{
    use RefreshDatabase;

    private QaTester $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.github.com/*' => Http::response(['workflow_runs' => []])]);
        $this->lead = QaTester::create(['name' => 'หัวหน้า', 'email' => 'lead@qa.test', 'password' => 'secret1234', 'is_lead' => true]);
    }

    private function as(QaTester $who, string $panel): static
    {
        $this->forgetGuards();
        Filament::setCurrentPanel(Filament::getPanel($panel));

        return $this->actingAs($who, $panel);
    }

    /** Opens sign-up and signs one participant in; returns them. */
    private function participant(string $name = 'สมชาย', string $occupation = 'ครูประถม'): QaTester
    {
        $code = QaSignupCode::renew();
        $this->post('/usability/signup', ['name' => $name, 'occupation' => $occupation, 'code' => $code])
            ->assertRedirect('/usability');

        return QaTester::query()->where('role', QaTester::ROLE_PARTICIPANT)->latest('id')->firstOrFail();
    }

    private function saveAllTasks(): void
    {
        foreach (QaCatalog::ofKind(QaCatalog::QUICK) as $task) {
            $this->post('/usability/tasks/'.$task['code'], ['status' => 'success', 'ease' => 4])->assertSessionHasNoErrors();
        }
    }

    /** @return array<string, mixed> */
    private function sus(array $q): array
    {
        $form = ['adjective' => 5, 'platform' => 'web', 'experience' => 'first'];
        foreach ($q as $i => $a) {
            $form['q'.($i + 1)] = $a;
        }

        return $form;
    }

    public function test_signup_is_closed_until_the_lead_sets_a_code(): void
    {
        $this->get('/usability')->assertOk()->assertSee('ยังไม่เปิดรับผู้ทดสอบ');
        $this->post('/usability/signup', ['name' => 'ก', 'occupation' => 'ข', 'code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, QaTester::query()->where('role', QaTester::ROLE_PARTICIPANT)->count());
    }

    public function test_a_wrong_code_signs_nobody_up(): void
    {
        $code = QaSignupCode::renew();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->get('/usability')->assertOk()->assertSee('รหัสกลุ่ม');
        $this->post('/usability/signup', ['name' => 'ก', 'occupation' => 'ข', 'code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, QaTester::query()->where('role', QaTester::ROLE_PARTICIPANT)->count());
    }

    public function test_a_name_an_occupation_and_the_code_let_a_participant_straight_in(): void
    {
        $code = QaSignupCode::renew();
        $this->post('/usability/signup', ['name' => 'สมชาย', 'occupation' => '', 'code' => $code])
            ->assertSessionHasErrors('occupation');

        $this->post('/usability/signup', ['name' => '  สมชาย ใจดี ', 'occupation' => ' ครูประถม ', 'code' => " {$code} "])
            ->assertRedirect('/usability');

        $p = QaTester::query()->where('role', QaTester::ROLE_PARTICIPANT)->sole();
        $this->assertSame('สมชาย ใจดี', $p->name);
        $this->assertSame('ครูประถม', $p->occupation);
        $this->assertStringEndsWith('@usability.invalid', $p->email);
        $this->assertFalse($p->is_lead);

        $page = $this->get('/usability')->assertOk()->assertSee('สมชาย ใจดี');
        foreach (QaCatalog::ofKind(QaCatalog::QUICK) as $task) {
            $page->assertSee($task['title']);
        }
    }

    public function test_a_participant_opens_nothing_else(): void
    {
        $p = $this->participant();

        $this->assertFalse($p->canAccessPanel(Filament::getPanel('qa')));
        $this->as($p, 'qa')->get('/qa')->assertForbidden();
        $this->forgetGuards();
        $this->actingAs($p, 'sus')->get('/sus')->assertRedirect('/sus/login');
    }

    public function test_the_pages_carry_no_script_and_their_own_policy(): void
    {
        $page = $this->get('/usability')->assertOk();
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('<script', $page->getContent());

        $this->participant();
        $this->assertStringNotContainsString('<script', $this->get('/usability')->getContent());
    }

    public function test_signup_is_throttled(): void
    {
        $code = QaSignupCode::renew();
        $wrong = $code === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $_) {
            $this->post('/usability/signup', ['name' => 'ก', 'occupation' => 'ข', 'code' => $wrong])->assertStatus(302);
        }

        $this->post('/usability/signup', ['name' => 'ก', 'occupation' => 'ข', 'code' => $code])->assertStatus(429);
    }

    public function test_each_task_is_rated_and_saved_on_its_own(): void
    {
        $p = $this->participant();

        $this->post('/usability/tasks/QK-2', ['status' => 'assisted', 'ease' => 2, 'note' => ' หาปุ่มเผยแพร่ไม่เจอ '])
            ->assertRedirect('/usability#QK-2');
        $this->assertDatabaseHas('qa_results', ['qa_tester_id' => $p->id, 'case_code' => 'QK-2', 'status' => 'assisted', 'ease' => 2, 'note' => 'หาปุ่มเผยแพร่ไม่เจอ']);

        $this->post('/usability/tasks/QK-2', ['status' => 'success', 'ease' => 4, 'note' => ''])->assertSessionHasNoErrors();
        $row = QaResult::query()->where('qa_tester_id', $p->id)->where('case_code', 'QK-2')->sole();
        $this->assertSame(['success', 4, null], [$row->status, $row->ease, $row->note]);

        $this->post('/usability/tasks/QK-1', ['status' => 'skip', 'ease' => 6])->assertSessionHasErrors(['status', 'ease']);
        // A rejected task comes back to its own card, with the error and what
        // was typed on it (read from the page: asserting on the session here
        // reopens it and hides the next request's errors under JSON sessions).
        $this->post('/usability/tasks/QK-1', ['status' => 'success', 'note' => 'ลืมให้คะแนน'])
            ->assertRedirect('/usability#QK-1');
        $this->get('/usability')->assertOk()
            ->assertSeeInOrder(['id="QK-1"', 'ให้คะแนนความง่าย 1 ถึง 5', 'ลืมให้คะแนน', 'id="QK-2"'], false);
        $this->post('/usability/tasks/PR-01', ['status' => 'success', 'ease' => 3])->assertNotFound();
        $this->post('/usability/tasks/QK-9', ['status' => 'success', 'ease' => 3])->assertNotFound();

        $this->get('/usability')->assertOk()->assertSee('บันทึกแล้ว 1 จาก 4 งาน');
    }

    public function test_nobody_saves_a_task_without_signing_up(): void
    {
        $this->post('/usability/tasks/QK-1', ['status' => 'success', 'ease' => 3])->assertRedirect('/usability');
        $this->post('/usability/sus', $this->sus(array_fill(0, 10, 3)))->assertRedirect('/usability');

        $this->assertSame(0, QaResult::query()->count());
    }

    public function test_sus_opens_once_every_task_is_saved_and_is_sent_once(): void
    {
        $p = $this->participant();
        $this->get('/usability/sus')->assertRedirect('/usability');

        $this->saveAllTasks();
        $this->get('/usability/sus')->assertOk()->assertSee(QaSus::ITEMS[0]['th']);

        $this->post('/usability/sus', $this->sus(array_fill(0, 10, 6)))->assertSessionHasErrors(['q1', 'q10']);
        $this->post('/usability/sus', $this->sus([4, 2, 4, 2, 4, 2, 4, 2, 4, 2]))->assertRedirect('/usability/sus');

        $response = QaSusResponse::query()->where('qa_tester_id', $p->id)->sole();
        $this->assertSame('teacher', $response->role);
        $this->assertSame(75.0, (float) $response->score);

        $this->post('/usability/sus', $this->sus(array_fill(0, 10, 1)))->assertRedirect('/usability/sus');
        $this->assertSame(1, QaSusResponse::query()->where('qa_tester_id', $p->id)->count());
        $this->get('/usability/sus')->assertOk()->assertSee('ขอบคุณ');
    }

    public function test_the_lead_opens_and_closes_signup_and_sees_the_code_once(): void
    {
        $tester = QaTester::create(['name' => 'ผู้ทดสอบ', 'email' => 't@qa.test', 'password' => 'secret1234']);
        $this->as($tester, 'qa');
        Livewire::test(Testers::class)->call('renewSignupCode')->assertSet('newCode', null);
        $this->assertFalse(QaSignupCode::isOpen(), 'only the lead opens sign-up');

        $this->as($this->lead, 'qa');
        $page = Livewire::test(Testers::class)->call('renewSignupCode');
        $code = $page->get('newCode');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertTrue(QaSignupCode::matches((string) $code));
        $page->assertSee($code);

        $page->call('closeSignup')->assertSet('newCode', null);
        $this->assertFalse(QaSignupCode::isOpen());
        $this->assertFalse(QaSignupCode::matches((string) $code));
    }

    public function test_the_team_sees_the_participants_and_the_report_has_their_answers(): void
    {
        $p = $this->participant('มานี มีนา', 'พนักงานบริษัท');
        $this->saveAllTasks();
        $this->post('/usability/tasks/QK-3', ['status' => 'fail', 'ease' => 1, 'note' => 'ไม่รู้จะถ่ายรูปตรงไหน']);
        $this->post('/usability/sus', $this->sus([4, 2, 4, 2, 4, 2, 4, 2, 4, 2]));

        $this->as($this->lead, 'qa');
        $this->get('/qa/testers')->assertOk()
            ->assertSee('ผู้เข้าร่วมทดสอบ (1 คน)')->assertSee('มานี มีนา')->assertSee('พนักงานบริษัท');

        $this->get('/qa/report')->assertOk()
            ->assertSee('1 / '.QaCatalog::QUICK_MIN_TESTERS)
            ->assertSee('มานี มีนา')->assertSee('พนักงานบริษัท')
            ->assertSee('75.0')
            ->assertSee('ไม่รู้จะถ่ายรูปตรงไหน')
            ->assertSee('ความง่ายเฉลี่ย');
        $this->assertNotNull($p->fresh());
    }
}
