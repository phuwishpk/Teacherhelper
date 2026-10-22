<?php

namespace App\Domain\Qa;

use App\Models\QaResult;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Illuminate\Support\Collection;

/**
 * The numbers of the Test Lab's overview (DESIGN §39), from qa_results and
 * qa_sus_responses. One read of each table per page load.
 */
final class QaBoard
{
    /** @var Collection<int, QaResult> */
    private Collection $results;

    /** @var Collection<int, QaSusResponse> */
    private Collection $sus;

    /** @var Collection<int, QaTester> */
    private Collection $testers;

    public function __construct()
    {
        $this->testers = QaTester::query()->where('role', QaTester::ROLE_TESTER)->orderBy('name')->get();
        $this->results = QaResult::query()->get();
        $this->sus = QaSusResponse::query()->get();
    }

    /** @return Collection<int, QaTester> */
    public function testers(): Collection
    {
        return $this->testers;
    }

    public function result(int $testerId, string $code): ?QaResult
    {
        return $this->results->first(fn (QaResult $r) => $r->qa_tester_id === $testerId && $r->case_code === $code);
    }

    /** @return Collection<int, QaResult> results with a status for one case */
    public function resultsFor(string $code): Collection
    {
        return $this->results->filter(fn (QaResult $r) => $r->case_code === $code && $r->status !== null)->values();
    }

    /**
     * How many cases of the kinds the tester has a status for ("skip" counts:
     * the tester decided).
     *
     * @param  string|list<string>  $kinds
     */
    public function filled(int $testerId, string|array $kinds): int
    {
        $codes = array_column(QaCatalog::ofKinds((array) $kinds), 'code');

        return $this->results->filter(fn (QaResult $r) => $r->qa_tester_id === $testerId && $r->status !== null && in_array($r->case_code, $codes, true))->count();
    }

    /** The tester's status of one case, or null. */
    public function statusOf(int $testerId, string $code): ?string
    {
        return $this->result($testerId, $code)?->status;
    }

    /**
     * Per usability group: how many tasks, how many someone has tried
     * (not skipped), and the success / assisted / fail attempts.
     *
     * @return array<string, array{tasks: int, tried: int, success: int, assisted: int, fail: int}>
     */
    public function groupSummary(): array
    {
        $out = [];
        foreach (QaCatalog::usabilityGroups() as $group => $count) {
            $codes = array_column(QaCatalog::inGroup($group), 'code');
            $rows = $this->results->filter(fn (QaResult $r) => in_array($r->case_code, $codes, true) && in_array($r->status, ['success', 'assisted', 'fail'], true));
            $out[$group] = [
                'tasks' => $count,
                'tried' => $rows->pluck('case_code')->unique()->count(),
                'success' => $rows->where('status', 'success')->count(),
                'assisted' => $rows->where('status', 'assisted')->count(),
                'fail' => $rows->where('status', 'fail')->count(),
            ];
        }

        return $out;
    }

    /**
     * One verdict for a manual case from everyone's results: any fail = fail,
     * else any pass = pass, else any blocked = blocked, else not tested.
     *
     * @return array{0: string|null, 1: string, 2: string} [value, label, tone]
     */
    public function manualVerdict(string $code): array
    {
        $statuses = $this->resultsFor($code)->pluck('status')->all();
        foreach (['fail', 'pass', 'blocked'] as $status) {
            if (in_array($status, $statuses, true)) {
                [$label, $tone] = QaCatalog::MANUAL_STATUSES[$status];

                return [$status, $status === 'blocked' ? 'ทำไม่ได้' : $label, $tone];
            }
        }

        return [null, 'ยังไม่ทดสอบ', 'gray'];
    }

    /** @return array{n: int, success: int, assisted: int, fail: int, avg_time: float|null, avg_errors: float|null} */
    public function usability(string $code): array
    {
        $rows = $this->resultsFor($code)->filter(fn (QaResult $r) => $r->status !== 'skip')->values();
        $times = $rows->filter(fn (QaResult $r) => $r->status !== 'fail' && $r->time_sec !== null)->pluck('time_sec');
        $errors = $rows->filter(fn (QaResult $r) => $r->error_count !== null)->pluck('error_count');

        return [
            'n' => $rows->count(),
            'success' => $rows->where('status', 'success')->count(),
            'assisted' => $rows->where('status', 'assisted')->count(),
            'fail' => $rows->where('status', 'fail')->count(),
            'avg_time' => $times->isEmpty() ? null : (float) $times->avg(),
            'avg_errors' => $errors->isEmpty() ? null : (float) $errors->avg(),
        ];
    }

    /** @return array{attempts: int, success: int} every usability result together */
    public function usabilityTotals(): array
    {
        $codes = array_column(QaCatalog::ofKinds(QaCatalog::USABILITY_KINDS), 'code');
        $rows = $this->results->filter(fn (QaResult $r) => in_array($r->status, ['success', 'assisted', 'fail'], true) && in_array($r->case_code, $codes, true));

        return ['attempts' => $rows->count(), 'success' => $rows->where('status', 'success')->count()];
    }

    /**
     * SUS statistics of the evaluators (DESIGN §39.7), for one role of the
     * app or all together.
     *
     * @return array{n: int, mean: float|null, sd: float|null, median: float|null, min: float|null, max: float|null, ci_low: float|null, ci_high: float|null}
     */
    public function susStats(?string $role = null): array
    {
        $rows = $role === null ? $this->sus : $this->sus->where('role', $role);

        return QaSus::stats($rows->pluck('score')->map(fn ($s) => (float) $s)->values()->all());
    }

    /** @return Collection<int, QaSusResponse> */
    public function susResponses(?string $role = null): Collection
    {
        return ($role === null ? $this->sus : $this->sus->where('role', $role))->values();
    }

    /**
     * An automated case's verdict from its workflow's latest run.
     *
     * @param  array<string, mixed>|null  $ci
     * @return array{0: string|null, 1: string, 2: string}
     */
    public static function autoVerdict(?array $ci, string $workflow): array
    {
        $run = $ci['workflows'][$workflow] ?? null;
        if (! is_array($run)) {
            return [null, 'ยังไม่มีข้อมูล', 'gray'];
        }

        return match (true) {
            $run['conclusion'] === 'success' => ['pass', 'ผ่าน', 'success'],
            $run['conclusion'] === null => [null, 'กำลังรัน', 'warning'],
            default => ['fail', 'ไม่ผ่าน', 'danger'],
        };
    }
}
