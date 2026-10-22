<?php

namespace Tests\Feature\Qa;

use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaSus;
use App\Filament\Qa\Pages\Testers;
use App\Filament\Sus\Pages\SusEvaluation;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SUS by its own role at its own page (DESIGN §39.7): evaluators answer at
 * /sus and see nothing else, testers never answer, the questionnaire is the
 * standard one and is scored and interpreted by the literature.
 */
class SusTest extends TestCase
{
    use RefreshDatabase;

    private QaTester $lead;

    private QaTester $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.github.com/*' => Http::response(['workflow_runs' => []])]);
        $this->lead = QaTester::create(['name' => 'หัวหน้า', 'email' => 'lead@qa.test', 'password' => 'secret1234', 'is_lead' => true]);
        $this->evaluator = QaTester::create(['name' => 'ครูผู้ใช้จริง', 'email' => 'eval@qa.test', 'password' => 'secret1234', 'role' => QaTester::ROLE_EVALUATOR]);
    }

    private function as(QaTester $who, string $panel): static
    {
        $this->forgetGuards();
        Filament::setCurrentPanel(Filament::getPanel($panel));

        return $this->actingAs($who, $panel);
    }

    public function test_scoring_follows_brooke(): void
    {
        $this->assertSame(100.0, QaSus::score([5, 1, 5, 1, 5, 1, 5, 1, 5, 1]));
        $this->assertSame(0.0, QaSus::score([1, 5, 1, 5, 1, 5, 1, 5, 1, 5]));
        $this->assertSame(50.0, QaSus::score(array_fill(0, 10, 3)));
        // 4,1,4,1,4,2,5,1,5,1 -> (3+4+3+4+3+3+4+4+4+4) x 2.5 = 36 x 2.5 = 90
        $this->assertSame(90.0, QaSus::score([4, 1, 4, 1, 4, 2, 5, 1, 5, 1]));
        $this->assertNull(QaSus::score([5, 1, 5]));
        $this->assertNull(QaSus::score([5, 1, 5, 1, 5, 1, 5, 1, 5, 6]));
        $this->assertCount(10, QaSus::ITEMS);
        $this->assertSame('I found the system unnecessarily complex.', QaSus::ITEMS[1]['en']);
    }

    public function test_grades_acceptability_and_adjectives_follow_the_published_scales(): void
    {
        $this->assertSame('A+', QaSus::grade(84.1));
        $this->assertSame('A', QaSus::grade(84.0));
        $this->assertSame('C', QaSus::grade(68.0));
        $this->assertSame('C-', QaSus::grade(64.9));
        $this->assertSame('D', QaSus::grade(51.7));
        $this->assertSame('F', QaSus::grade(51.6));
        $this->assertStringStartsWith('ยอมรับได้', QaSus::acceptability(70));
        $this->assertStringStartsWith('ก้ำกึ่ง', QaSus::acceptability(69.9));
        $this->assertStringStartsWith('ยอมรับไม่ได้', QaSus::acceptability(49.9));
        $this->assertStringContainsString('Good', QaSus::adjectiveFor(68));
        $this->assertStringContainsString('Excellent', QaSus::adjectiveFor(85));
        $this->assertStringContainsString('OK', QaSus::adjectiveFor(52));
    }

    public function test_statistics_with_a_t_based_confidence_interval(): void
    {
        $st = QaSus::stats([70.0, 80.0, 90.0]);
        $this->assertSame(3, $st['n']);
        $this->assertSame(80.0, $st['mean']);
        $this->assertSame(10.0, $st['sd']);
        $this->assertSame(80.0, $st['median']);
        // 80 ± 4.303 x 10 / sqrt(3) = 80 ± 24.84
        $this->assertSame(55.2, $st['ci_low']);
        $this->assertSame(100.0, $st['ci_high'], 'capped at 100');
        $one = QaSus::stats([72.5]);
        $this->assertNull($one['sd']);
        $this->assertNull($one['ci_low']);
        $this->assertSame(0, QaSus::stats([])['n']);
        $this->assertSame(2.042, QaSus::tCritical(30));
        $this->assertSame(1.96, QaSus::tCritical(500));
    }

    public function test_evaluators_open_sus_only_and_testers_never_open_it(): void
    {
        $this->get('/sus')->assertRedirect('/sus/login');

        $this->as($this->evaluator, 'sus')->get('/sus')->assertOk()
            ->assertSee('คำชี้แจง')
            ->assertSee('I thought the system was easy to use.')
            ->assertSee('Overall, I would rate the user-friendliness of this product as:');
        $this->get('/qa')->assertRedirect('/qa/login');

        $this->as($this->lead, 'sus')->get('/sus')->assertForbidden();
        $this->as($this->evaluator, 'qa')->get('/qa')->assertForbidden();
    }

    public function test_an_evaluator_answers_every_item_once_per_role_and_sees_no_score(): void
    {
        $this->as($this->evaluator, 'sus');
        Livewire::test(SusEvaluation::class)
            ->assertSet('role', 'teacher')
            ->set('answers', [4, 2, 4, 2, 4, 2, 4, 2, 4, null])
            ->call('submit')
            ->assertHasErrors(['answers.9', 'adjective', 'platform', 'experience'])
            ->set('answers.9', '2')
            ->set('adjective', 5)
            ->set('platform', 'web')
            ->set('experience', 'first')
            ->set('comment', 'ใช้ง่าย')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('justSent', true)
            ->assertDontSee('75.0')          // no score shown to the evaluator
            ->call('submit');                // a second send of the same role does nothing

        $row = QaSusResponse::query()->sole();
        $this->assertSame(75.0, $row->score);
        $this->assertSame([4, 2, 4, 2, 4, 2, 4, 2, 4, 2], $row->answers);
        $this->assertSame(5, $row->adjective);
        $this->assertSame('first', $row->experience);

        // The student side is a separate response.
        Livewire::test(SusEvaluation::class)
            ->assertSet('role', 'student')
            ->set('answers', array_fill(0, 10, 3))
            ->set('adjective', 4)->set('platform', 'android')->set('experience', 'first')
            ->call('submit')
            ->assertHasNoErrors();
        $this->assertSame(2, QaSusResponse::query()->count());
    }

    public function test_the_results_page_reports_the_standard_summary(): void
    {
        foreach ([[5, 1, 5, 1, 5, 1, 5, 1, 5, 1], [4, 2, 4, 2, 4, 2, 4, 2, 4, 2]] as $i => $answers) {
            $e = QaTester::create(['name' => "ผู้ประเมิน {$i}", 'email' => "e{$i}@qa.test", 'password' => 'secret1234', 'role' => QaTester::ROLE_EVALUATOR]);
            QaSusResponse::create(['qa_tester_id' => $e->id, 'role' => 'teacher', 'answers' => $answers, 'score' => QaSus::score($answers), 'adjective' => 6, 'platform' => 'web', 'experience' => 'first']);
        }
        $this->assertSame(87.5, (new QaBoard)->susStats('teacher')['mean']);
        $this->assertSame(0, (new QaBoard)->susStats('student')['n']);

        $this->as($this->lead, 'qa')->get('/qa/sus')->assertOk()
            ->assertSee('87.5 ± 17.7')
            ->assertSee('A+')
            ->assertSee('ยอมรับได้ (Acceptable)')
            ->assertSee('ผู้ประเมิน 0')
            ->assertSee('ครูผู้ใช้จริง: ยังไม่ประเมิน');
        $this->get('/qa')->assertOk()->assertSee('เกรด A+');
    }

    public function test_testers_add_evaluators_who_are_never_leads(): void
    {
        $tester = QaTester::create(['name' => 'ผู้ทดสอบ', 'email' => 't@qa.test', 'password' => 'secret1234']);
        $this->as($this->lead, 'qa');
        Livewire::test(Testers::class)
            ->set('role', QaTester::ROLE_EVALUATOR)
            ->set('name', 'นักเรียนอาสา')->set('email', 'kid@qa.test')->set('password', 'secret1234')->set('isLead', true)
            ->call('addTester')
            ->assertHasNoErrors();
        $kid = QaTester::query()->where('email', 'kid@qa.test')->sole();
        $this->assertTrue($kid->isEvaluator());
        $this->assertFalse($kid->is_lead);

        Livewire::test(Testers::class)->call('toggleLead', $kid->id);
        $this->assertFalse($kid->fresh()->is_lead, 'an evaluator is never made lead');
        $this->assertEqualsCanonicalizing([$tester->id, $this->lead->id], (new QaBoard)->testers()->pluck('id')->all(), 'evaluators are not testers');

        $this->get('/qa/testers')->assertOk()->assertSee('ผู้ประเมิน SUS (2 คน)')->assertSee('kid@qa.test');
    }
}
