<?php

namespace Tests\Feature\Qa;

use App\Domain\Qa\QaCatalog;
use App\Models\QaResult;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §39.8: the short usability set for five outside testers and its
 * printable report.
 */
class QuickUsabilityTest extends TestCase
{
    use RefreshDatabase;

    private function asQa(QaTester $who): static
    {
        $this->forgetGuards();
        Filament::setCurrentPanel(Filament::getPanel('qa'));

        return $this->actingAs($who, 'qa');
    }

    private function tester(int $n): QaTester
    {
        return QaTester::create(['name' => "ผู้ทดสอบ {$n}", 'email' => "t{$n}@qa.test", 'password' => 'secret1234']);
    }

    public function test_the_set_is_four_tasks_on_the_demo_data(): void
    {
        $tasks = QaCatalog::ofKind(QaCatalog::QUICK);

        $this->assertSame(['QK-1', 'QK-2', 'QK-3', 'QK-4'], array_column($tasks, 'code'));
        $this->assertSame(QaCatalog::QUICK_GROUP, array_key_first(QaCatalog::usabilityGroups()));
        foreach ($tasks as $task) {
            $this->assertNotEmpty($task['steps']);
            $this->assertStringContainsString('ระบบสาธิต', $task['pre']);
            foreach ($task['ucs'] as $uc) {
                $this->assertNotNull(QaCatalog::find($uc), "{$task['code']} names {$uc}");
            }
        }
    }

    public function test_the_report_says_how_many_testers_are_missing(): void
    {
        $lead = $this->tester(0);
        $this->asQa($lead)->get('/qa/report')->assertOk()
            ->assertSee('0 / 5')
            ->assertSee('ยังขาดอีก 5 คน')
            ->assertSee('QK-4');
        $this->get('/qa/run?section='.urlencode(QaCatalog::QUICK_GROUP))->assertOk()->assertSee('QK-1')->assertSee('ระบบสาธิต');
    }

    public function test_five_testers_make_a_complete_report(): void
    {
        foreach (range(1, 5) as $n) {
            $t = $this->tester($n);
            foreach (['QK-1', 'QK-2', 'QK-3', 'QK-4'] as $code) {
                QaResult::create([
                    'qa_tester_id' => $t->id,
                    'case_code' => $code,
                    'status' => $n === 5 && $code === 'QK-2' ? 'assisted' : 'success',
                    'time_sec' => 60 + $n,
                    'error_count' => $n === 5 ? 2 : 0,
                    'note' => $n === 1 && $code === 'QK-3' ? 'หาปุ่มถ่ายรูปไม่เจอตอนแรก' : null,
                ]);
            }
            QaSusResponse::create(['qa_tester_id' => $t->id, 'role' => 'teacher', 'answers' => [5, 1, 5, 1, 5, 1, 5, 1, 5, 1], 'score' => 70 + $n]);
        }
        // Someone who only skipped is not a participant.
        QaResult::create(['qa_tester_id' => $this->tester(6)->id, 'case_code' => 'QK-1', 'status' => 'skip']);

        $this->asQa(QaTester::query()->first())->get('/qa/report')->assertOk()
            ->assertSee('5 / 5')
            ->assertSee('ครบตามเกณฑ์โครงงาน')
            ->assertSee('73.0')                 // SUS mean of 71..75
            ->assertSee('80%')                  // QK-2: 4 of 5 alone
            ->assertSee('หาปุ่มถ่ายรูปไม่เจอตอนแรก')
            ->assertDontSee('ผู้ทดสอบ 6');
    }
}
