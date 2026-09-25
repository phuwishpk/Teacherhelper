<?php

namespace App\Domain\Review;

use App\Models\Assignment;
use App\Models\Layout;
use App\Models\Question;
use App\Models\Scan;
use App\Models\Submission;

/**
 * Which questions of the printed worksheet a submission has no answer for
 * yet (DESIGN §5.3, §9.5 "ทุกข้อต้องตรวจทานแล้ว").
 *
 * ResponseWriter creates `responses` rows one scanned page at a time, so
 * "every existing row is reviewed" is not "every question is reviewed":
 * page 2 of a two-page sheet may not be scanned yet. Publishing then would
 * send the student (and Google Classroom) a total of page 1 only.
 *
 * The expected questions are the regions of the layout version the
 * submission was scanned with (the highest version among its active scans,
 * else the assignment's current layout), minus questions deleted after
 * printing (LayoutPageMatcher skips those as well). With no layout row at
 * all, every question of the assignment is expected.
 */
final class SubmissionCoverage
{
    /** @var array<int, Layout|null> version => layout */
    private array $layouts = [];

    /** @var array<int, int>|null question id => position */
    private ?array $positions = null;

    /** @var array<int, int>|null submission id => highest layout version of its active scans */
    private ?array $versions = null;

    public function __construct(private readonly Assignment $assignment) {}

    /** Reads the scan layout version of every submission of the assignment in one query (review queue). */
    public function preload(): self
    {
        $this->versions = Scan::query()
            ->where('state', Scan::STATE_ACTIVE)
            ->whereIn('submission_id', Submission::query()->select('id')->where('assignment_id', $this->assignment->id))
            ->groupBy('submission_id')
            ->selectRaw('submission_id, MAX(layout_version) AS layout_version')
            ->pluck('layout_version', 'submission_id')
            ->map(fn ($version) => (int) $version)
            ->all();

        return $this;
    }

    /**
     * @param  iterable<int>  $answered  question ids the submission has a responses row for
     * @return list<array{question_id: int, position: int, page: int|null}> ordered by position
     */
    public function missing(Submission $submission, iterable $answered): array
    {
        $have = [];
        foreach ($answered as $questionId) {
            $have[(int) $questionId] = true;
        }

        $missing = [];
        foreach ($this->expected($submission) as $questionId => $page) {
            if (! isset($have[$questionId])) {
                $missing[] = ['question_id' => $questionId, 'position' => $this->positions()[$questionId], 'page' => $page];
            }
        }
        usort($missing, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $missing;
    }

    /**
     * Questions the submission must answer, with the printed page each is on
     * (null when there is no layout to tell).
     *
     * @return array<int, int|null> question id => page
     */
    public function expected(Submission $submission): array
    {
        $positions = $this->positions();
        $layout = $this->layout($this->versionOf($submission)) ?? $this->layout($this->assignment->current_layout_version);
        if ($layout === null) {
            return array_fill_keys(array_keys($positions), null);
        }

        $expected = [];
        foreach ($layout->pages as $index => $page) {
            $pageNo = (int) ($page['page'] ?? $index + 1);
            foreach ((array) ($page['regions'] ?? []) as $region) {
                $questionId = (int) ($region['question_id'] ?? 0);
                if (isset($positions[$questionId]) && ! array_key_exists($questionId, $expected)) {
                    $expected[$questionId] = $pageNo;
                }
            }
        }

        return $expected;
    }

    /**
     * The distinct pages of missing() rows, ascending.
     *
     * @param  list<array{page: int|null}>  $missing
     * @return list<int>
     */
    public static function pages(array $missing): array
    {
        $pages = array_values(array_unique(array_filter(array_column($missing, 'page'), fn ($page) => $page !== null)));
        sort($pages);

        return $pages;
    }

    private function versionOf(Submission $submission): ?int
    {
        if ($this->versions !== null) {
            return $this->versions[$submission->id] ?? null;
        }
        $version = Scan::query()
            ->where('submission_id', $submission->id)
            ->where('state', Scan::STATE_ACTIVE)
            ->max('layout_version');

        return $version === null ? null : (int) $version;
    }

    private function layout(?int $version): ?Layout
    {
        if ($version === null) {
            return null;
        }
        if (! array_key_exists($version, $this->layouts)) {
            $this->layouts[$version] = Layout::query()
                ->where('assignment_id', $this->assignment->id)
                ->where('version', $version)
                ->first();
        }

        return $this->layouts[$version];
    }

    /** @return array<int, int> */
    private function positions(): array
    {
        return $this->positions ??= Question::query()
            ->where('assignment_id', $this->assignment->id)
            ->pluck('position', 'id')
            ->map(fn ($position) => (int) $position)
            ->all();
    }
}
