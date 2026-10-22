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
        $this->testers = QaTester::query()->orderBy('name')->get();
        $this->results = QaResult::query()->get();
        $this->sus = QaSusResponse::query()->get()->keyBy('qa_tester_id');
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

    public function sus(int $testerId): ?QaSusResponse
    {
        return $this->sus->get($testerId);
    }

    /** How many cases of one kind the tester has a status for. */
    public function filled(int $testerId, string $kind): int
    {
        $codes = array_column(QaCatalog::ofKind($kind), 'code');

        return $this->results->filter(fn (QaResult $r) => $r->qa_tester_id === $testerId && $r->status !== null && in_array($r->case_code, $codes, true))->count();
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
        $rows = $this->resultsFor($code);
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
        $codes = array_column(QaCatalog::ofKind(QaCatalog::USABILITY), 'code');
        $rows = $this->results->filter(fn (QaResult $r) => $r->status !== null && in_array($r->case_code, $codes, true));

        return ['attempts' => $rows->count(), 'success' => $rows->where('status', 'success')->count()];
    }

    /** @return array{mean: float|null, n: int} */
    public function susSummary(): array
    {
        return ['mean' => $this->sus->isEmpty() ? null : round((float) $this->sus->avg('score'), 1), 'n' => $this->sus->count()];
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
