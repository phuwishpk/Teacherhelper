<?php

namespace App\Domain\Students;

use App\Domain\Analysis\StudentAnalyses;
use App\Domain\Mastery\MasteryCalculator;
use App\Exceptions\ApiException;
use App\Models\StudentMerge;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "รวมบัญชีนักเรียน" (DESIGN §24.5): the account D (merge) is folded into
 * the account K (keep) of the same school, in one transaction:
 *
 * - D's rows move to K, with the collision rules of the §24.5 table (the
 *   same classroom keeps K's row and number; an empty submission gives way
 *   to a real one; equal gradebook values drop D's copy; K's published
 *   grade wins);
 * - real collisions (work handed in by both on one assignment, different
 *   scores or ร/มส, two different student codes) refuse the whole merge
 *   with 409 merge_conflict and nothing written;
 * - K's mastery is recomputed from the combined observations and K's
 *   per-student analyses are recomputed by code;
 * - D's credentials, sessions, push tokens and login cards are deleted, and
 *   D becomes disabled with merged_into_id = K (the row stays: foreign keys
 *   are RESTRICT and it is the evidence);
 * - one student_merges row keeps the moved ids and the dropped rows. There
 *   is no undo.
 *
 * COLUMNS lists every foreign key to users.id and what a merge does with
 * it; StudentMergeCoverageTest fails when a new one is missing.
 */
final class StudentMerger
{
    public const MOVE = 'move';

    public const DELETE = 'delete';

    /** Columns that only ever hold staff (teachers, admins), or the merge bookkeeping itself. */
    public const UNRELATED = 'unrelated';

    /** @var array<string, string> "table.column" => what a merge does with D's value */
    public const COLUMNS = [
        'classroom_students.student_id' => self::MOVE,
        'submissions.student_id' => self::MOVE,
        'appeals.student_id' => self::MOVE,
        'gradebook_entries.student_id' => self::MOVE,
        'gradebook_special_grades.student_id' => self::MOVE,
        'gradebook_published_grades.student_id' => self::MOVE,
        'skill_observations.student_id' => self::MOVE,
        'practice_attempts.student_id' => self::MOVE,
        'classroom_submission_imports.student_id' => self::MOVE,
        'scans.uploaded_by' => self::MOVE,
        'submission_pages.uploaded_by' => self::MOVE,
        'score_events.actor_user_id' => self::MOVE,
        'student_analyses.student_id' => self::MOVE,
        'user_google_identities.user_id' => self::MOVE, // only when K has none; both linked refuses the merge
        'user_google_identities.linked_by' => self::MOVE,
        'mastery.student_id' => self::DELETE, // recomputed for K from the moved observations
        'student_credentials.student_id' => self::DELETE,
        'device_tokens.user_id' => self::DELETE,
        'login_card_prints.student_id' => self::DELETE,
        'users.approved_by' => self::UNRELATED,
        'users.merged_into_id' => self::UNRELATED,
        'classrooms.teacher_id' => self::UNRELATED,
        'classrooms.closed_by' => self::UNRELATED,
        'teacher_api_keys.user_id' => self::UNRELATED,
        'login_card_prints.requested_by' => self::UNRELATED,
        'assignments.created_by' => self::UNRELATED,
        'assignments.key_approved_by' => self::UNRELATED,
        'worksheet_prints.requested_by' => self::UNRELATED,
        'submissions.published_by' => self::UNRELATED,
        'responses.reviewed_by' => self::UNRELATED,
        'appeals.resolved_by' => self::UNRELATED,
        'google_accounts.user_id' => self::UNRELATED,
        'classroom_google_links.owner_user_id' => self::UNRELATED,
        'assignment_google_links.posted_by' => self::UNRELATED,
        'practice_items.approved_by' => self::UNRELATED,
        'learning_resources.added_by' => self::UNRELATED,
        'source_documents.uploaded_by' => self::UNRELATED,
        'document_extractions.requested_by' => self::UNRELATED,
        'grade_conflicts.resolved_by' => self::UNRELATED,
        'skills.created_by' => self::UNRELATED,
        'courses.created_by' => self::UNRELATED,
        'analysis_batches.key_owner_id' => self::UNRELATED,
        'student_analyses.approved_by' => self::UNRELATED,
        'ai_calls.guidance_by' => self::UNRELATED,
        'gradebook_items.created_by' => self::UNRELATED,
        'gradebook_entries.updated_by' => self::UNRELATED,
        'gradebook_special_grades.set_by' => self::UNRELATED,
        'gradebook_publications.published_by' => self::UNRELATED,
        'exam_page_images.uploaded_by' => self::UNRELATED,
        'exam_imports.requested_by' => self::UNRELATED,
        'student_merges.kept_student_id' => self::UNRELATED,
        'student_merges.merged_student_id' => self::UNRELATED,
        'student_merges.merged_by' => self::UNRELATED,
        'classroom_course_requests.requested_by' => self::UNRELATED,
        'classroom_course_requests.decided_by' => self::UNRELATED,
    ];

    public function __construct(
        private readonly MasteryCalculator $mastery,
        private readonly StudentAnalyses $analyses,
    ) {}

    /**
     * GET /students/merge-preview: both accounts side by side, the
     * conflicts that would refuse the merge, and can_merge.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 422 merge_invalid
     */
    public function preview(User $keep, User $merge): array
    {
        self::assertValid($keep, $merge);
        $conflicts = $this->conflicts($keep, $merge);

        return [
            'keep' => $this->account($keep),
            'merge' => $this->account($merge),
            'conflicts' => $conflicts,
            'can_merge' => $conflicts === [],
        ];
    }

    /**
     * POST /students/merge.
     *
     * @throws ApiException 422 merge_invalid, 409 merge_conflict
     */
    public function merge(User $keep, User $merge, User $actor): StudentMerge
    {
        $files = [];
        $record = DB::transaction(function () use ($keep, $merge, $actor, &$files) {
            // Both rows locked in id order, so two merges of the same pair cannot deadlock.
            $locked = User::query()->whereIn('id', [$keep->id, $merge->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $k = $locked->get($keep->id) ?? $keep;
            $d = $locked->get($merge->id) ?? $merge;
            self::assertValid($k, $d);

            $conflicts = $this->conflicts($k, $d);
            if ($conflicts !== []) {
                $errors = [];
                foreach ($conflicts as $conflict) {
                    $errors[$conflict['type']][] = $conflict['message'];
                }

                throw new ApiException('รวมบัญชีไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน', 'merge_conflict', 409, $errors);
            }

            $summary = [];
            $summary['classroom_students'] = $this->moveClassrooms($k->id, $d->id);
            $summary['submissions'] = $this->moveSubmissions($k->id, $d->id);
            $summary['appeals'] = ['moved' => $this->moveColumn('appeals', 'student_id', $k->id, $d->id)];
            $summary['gradebook_entries'] = $this->moveGradebookEntries($k->id, $d->id);
            $summary['gradebook_special_grades'] = $this->moveKeyed('gradebook_special_grades', ['course_id', 'classroom_id'], $k->id, $d->id);
            $summary['gradebook_published_grades'] = $this->moveKeyed('gradebook_published_grades', ['publication_id'], $k->id, $d->id);

            $skillIds = DB::table('skill_observations')->where('student_id', $d->id)->distinct()->pluck('skill_id')
                ->merge(DB::table('mastery')->where('student_id', $d->id)->pluck('skill_id'))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $summary['skill_observations'] = ['moved' => $this->moveColumn('skill_observations', 'student_id', $k->id, $d->id)];
            $summary['practice_attempts'] = ['moved' => $this->moveColumn('practice_attempts', 'student_id', $k->id, $d->id)];
            $summary['classroom_submission_imports'] = ['moved' => $this->moveColumn('classroom_submission_imports', 'student_id', $k->id, $d->id)];
            $summary['scans'] = ['moved' => $this->moveColumn('scans', 'uploaded_by', $k->id, $d->id)];
            $summary['submission_pages'] = ['moved' => $this->moveColumn('submission_pages', 'uploaded_by', $k->id, $d->id)];
            $summary['score_events'] = ['moved' => $this->moveColumn('score_events', 'actor_user_id', $k->id, $d->id)];

            $droppedMastery = DB::table('mastery')->where('student_id', $d->id)->get()->map(fn ($r) => (array) $r)->all();
            DB::table('mastery')->where('student_id', $d->id)->delete();
            foreach ($skillIds as $skillId) {
                $this->mastery->recompute($k->id, $skillId);
            }
            $summary['mastery'] = ['dropped' => $droppedMastery, 'recomputed_skill_ids' => $skillIds];

            $summary['student_analyses'] = $this->moveKeyed('student_analyses', ['classroom_id'], $k->id, $d->id, 'id');
            // Google sign-in (§24.5): conflicts() refused the merge when both are linked.
            $summary['user_google_identities'] = [
                'moved' => $this->moveColumn('user_google_identities', 'user_id', $k->id, $d->id),
                'linked_by_moved' => $this->moveColumn('user_google_identities', 'linked_by', $k->id, $d->id),
            ];

            if ($k->student_code === null && $d->student_code !== null) {
                $code = $d->student_code;
                User::query()->whereKey($d->id)->update(['student_code' => null]);
                User::query()->whereKey($k->id)->update(['student_code' => $code]);
                $summary['users'] = ['student_code_moved' => $code];
            }

            $summary['student_credentials'] = ['dropped' => DB::table('student_credentials')->where('student_id', $d->id)->pluck('student_id')->all()];
            DB::table('student_credentials')->where('student_id', $d->id)->delete();
            $summary['personal_access_tokens'] = ['dropped' => $d->tokens()->pluck('id')->all()];
            $d->tokens()->delete();
            $summary['device_tokens'] = ['dropped' => DB::table('device_tokens')->where('user_id', $d->id)->pluck('id')->all()];
            DB::table('device_tokens')->where('user_id', $d->id)->delete();
            $prints = DB::table('login_card_prints')->where('student_id', $d->id)->get(['id', 'file_path']);
            $files = $prints->pluck('file_path')->filter()->values()->all();
            $summary['login_card_prints'] = ['dropped' => $prints->pluck('id')->all()];
            DB::table('login_card_prints')->where('student_id', $d->id)->delete();

            User::query()->whereKey($d->id)->update([
                'status' => User::STATUS_DISABLED,
                'merged_into_id' => $k->id,
                'updated_at' => now(),
            ]);

            // The code-computed part of every analysis of K (the AI texts follow in the nightly round, §20.8).
            $this->analyses->refreshStudent($k->id);

            return StudentMerge::create([
                'school_id' => $k->school_id,
                'kept_student_id' => $k->id,
                'merged_student_id' => $d->id,
                'merged_by' => $actor->id,
                'summary' => $summary,
            ]);
        });

        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }

        return $record;
    }

    /**
     * @throws ApiException 422 merge_invalid
     */
    public static function assertValid(User $keep, User $merge): void
    {
        $reason = match (true) {
            $keep->id === $merge->id => 'เลือกบัญชีเดียวกันทั้งสองฝั่ง',
            ! $keep->isStudent() || ! $merge->isStudent() => 'รวมได้เฉพาะบัญชีนักเรียน',
            $keep->school_id === null || $keep->school_id !== $merge->school_id => 'รวมได้เฉพาะบัญชีของโรงเรียนเดียวกัน',
            $merge->isMerged() => 'บัญชีนี้ถูกรวมเข้าบัญชีอื่นแล้ว',
            $keep->isMerged() => 'บัญชีที่จะเก็บไว้ถูกรวมเข้าบัญชีอื่นแล้ว',
            default => null,
        };
        if ($reason !== null) {
            throw new ApiException($reason, 'merge_invalid', 422, ['merge_id' => [$reason]]);
        }
    }

    /**
     * Collisions that refuse the merge (DESIGN §24.5), each {type, message}.
     *
     * @return list<array{type: string, message: string}>
     */
    public function conflicts(User $keep, User $merge): array
    {
        $conflicts = [];

        $pairs = DB::table('submissions as d')
            ->join('submissions as k', fn ($j) => $j->on('k.assignment_id', '=', 'd.assignment_id')->where('k.student_id', '=', $keep->id))
            ->join('assignments', 'assignments.id', '=', 'd.assignment_id')
            ->join('classrooms', 'classrooms.id', '=', 'assignments.classroom_id')
            ->where('d.student_id', $merge->id)
            ->orderBy('d.id')
            ->get(['d.id as d_id', 'k.id as k_id', 'assignments.title', 'classrooms.name as classroom_name']);
        foreach ($pairs as $pair) {
            if (! self::isEmptySubmission((int) $pair->d_id) && ! self::isEmptySubmission((int) $pair->k_id)) {
                $conflicts[] = ['type' => 'submissions', 'message' => "ทั้งสองบัญชีมีงาน {$pair->title} ห้อง {$pair->classroom_name}"];
            }
        }

        foreach ($this->entryPairs($keep->id, $merge->id) as [$k, $d, $label]) {
            if (! self::sameEntry($k, $d)) {
                $conflicts[] = ['type' => 'gradebook_entries', 'message' => "คะแนน {$label} ของสองบัญชีไม่เท่ากัน"];
            }
        }

        $specials = DB::table('gradebook_special_grades as d')
            ->join('gradebook_special_grades as k', fn ($j) => $j->on('k.course_id', '=', 'd.course_id')->on('k.classroom_id', '=', 'd.classroom_id')->where('k.student_id', '=', $keep->id))
            ->join('courses', 'courses.id', '=', 'd.course_id')
            ->where('d.student_id', $merge->id)
            ->get(['d.special as d_special', 'k.special as k_special', 'courses.code']);
        foreach ($specials as $row) {
            if ($row->d_special !== $row->k_special) {
                $conflicts[] = ['type' => 'gradebook_special_grades', 'message' => "ร/มส ของรายวิชา {$row->code} ของสองบัญชีไม่เท่ากัน"];
            }
        }

        if (DB::table('user_google_identities')->where('user_id', $keep->id)->exists()
            && DB::table('user_google_identities')->where('user_id', $merge->id)->exists()) {
            $conflicts[] = ['type' => 'google', 'message' => 'ทั้งสองบัญชีเชื่อมบัญชี Google สำหรับเข้าสู่ระบบไว้ ยกเลิกการเชื่อมของบัญชีใดบัญชีหนึ่งก่อน'];
        }

        if ($keep->student_code !== null && $merge->student_code !== null && $keep->student_code !== $merge->student_code) {
            $conflicts[] = ['type' => 'student_code', 'message' => "เลขประจำตัวต่างกัน ({$keep->student_code} กับ {$merge->student_code}) ล้างเลขของบัญชีใดบัญชีหนึ่งก่อน"];
        }

        return $conflicts;
    }

    /**
     * An "empty" submission (§24.5): still awaiting a scan, with no scan,
     * page, answer or grade conflict, so dropping it loses nothing.
     */
    public static function isEmptySubmission(int $submissionId): bool
    {
        return DB::table('submissions')->where('id', $submissionId)->where('status', Submission::STATUS_AWAITING_SCAN)->exists()
            && ! DB::table('scans')->where('submission_id', $submissionId)->exists()
            && ! DB::table('submission_pages')->where('submission_id', $submissionId)->exists()
            && ! DB::table('responses')->where('submission_id', $submissionId)->exists()
            && ! DB::table('grade_conflicts')->where('submission_id', $submissionId)->exists();
    }

    /** @return array<string, mixed> */
    private function account(User $student): array
    {
        $classrooms = DB::table('classroom_students')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_students.classroom_id')
            ->where('classroom_students.student_id', $student->id)
            ->orderByDesc('classrooms.academic_year')->orderBy('classrooms.name')
            ->get(['classrooms.id', 'classrooms.name', 'classrooms.academic_year', 'classroom_students.student_number', 'classrooms.closed_at', 'classroom_students.google_email']);

        return [
            'id' => $student->id,
            'name' => $student->name,
            'student_code' => $student->student_code,
            'status' => $student->status,
            'classrooms' => $classrooms->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'academic_year' => (int) $c->academic_year,
                'student_number' => (int) $c->student_number,
                'closed' => $c->closed_at !== null,
            ])->values()->all(),
            'submissions' => [
                'total' => DB::table('submissions')->where('student_id', $student->id)->count(),
                'published' => DB::table('submissions')->where('student_id', $student->id)->where('status', Submission::STATUS_PUBLISHED)->count(),
            ],
            'gradebook_entries' => DB::table('gradebook_entries')->where('student_id', $student->id)->count(),
            'special_grades' => DB::table('gradebook_special_grades')->where('student_id', $student->id)->count(),
            'published_grades' => DB::table('gradebook_published_grades')->where('student_id', $student->id)->count(),
            'practice_attempts' => DB::table('practice_attempts')->where('student_id', $student->id)->count(),
            'observations' => DB::table('skill_observations')->where('student_id', $student->id)->count(),
            'mastery_skills' => DB::table('mastery')->where('student_id', $student->id)->count(),
            'analyses' => DB::table('student_analyses')->where('student_id', $student->id)->count(),
            // The Google sign-in account (§24.9) first, then those the Classroom rosters matched (§18.4).
            'google_emails' => collect([DB::table('user_google_identities')->where('user_id', $student->id)->value('email')])
                ->merge($classrooms->pluck('google_email'))
                ->filter()->map(fn ($e) => mb_strtolower((string) $e))->unique()->values()->all(),
        ];
    }

    /** @return array{moved: list<int>, kept: list<int>, dropped: list<array<string, mixed>>} classroom ids */
    private function moveClassrooms(int $keepId, int $mergeId): array
    {
        $out = ['moved' => [], 'kept' => [], 'dropped' => []];
        foreach (DB::table('classroom_students')->where('student_id', $mergeId)->get() as $row) {
            $kRow = DB::table('classroom_students')->where('classroom_id', $row->classroom_id)->where('student_id', $keepId)->first();
            if ($kRow === null) {
                DB::table('classroom_students')->where('classroom_id', $row->classroom_id)->where('student_id', $mergeId)->update(['student_id' => $keepId]);
                $out['moved'][] = (int) $row->classroom_id;

                continue;
            }
            // Same classroom: K's row and number stay. D's row goes first so
            // its Google account can move to K without hitting uq_class_google_user.
            DB::table('classroom_students')->where('classroom_id', $row->classroom_id)->where('student_id', $mergeId)->delete();
            $out['dropped'][] = (array) $row;
            $out['kept'][] = (int) $row->classroom_id;
            if ($kRow->google_user_id === null && $row->google_user_id !== null) {
                DB::table('classroom_students')->where('classroom_id', $row->classroom_id)->where('student_id', $keepId)->update([
                    'google_user_id' => $row->google_user_id,
                    'google_email' => $row->google_email,
                    'left_course_at' => $row->left_course_at,
                ]);
            }
        }

        // A row the roster sync added for D still waits for a PIN. K already has
        // a working PIN and card, so issuing one there would only reset them.
        if ($out['moved'] !== [] && DB::table('student_credentials')->where('student_id', $keepId)->exists()) {
            DB::table('classroom_students')->where('student_id', $keepId)->whereIn('classroom_id', $out['moved'])
                ->whereNotNull('pin_pending_at')->update(['pin_pending_at' => null]);
        }

        return $out;
    }

    /** @return array{moved: list<int>, dropped: list<array<string, mixed>>} submission ids */
    private function moveSubmissions(int $keepId, int $mergeId): array
    {
        $out = ['moved' => [], 'dropped' => []];
        foreach (DB::table('submissions')->where('student_id', $mergeId)->orderBy('id')->get() as $row) {
            $kRow = DB::table('submissions')->where('assignment_id', $row->assignment_id)->where('student_id', $keepId)->first();
            if ($kRow !== null) {
                // conflicts() already refused two real ones: drop the empty side.
                $drop = self::isEmptySubmission((int) $row->id) ? $row : $kRow;
                DB::table('submissions')->where('id', $drop->id)->delete();
                $out['dropped'][] = (array) $drop;
                if ($drop === $row) {
                    continue;
                }
            }
            DB::table('submissions')->where('id', $row->id)->update(['student_id' => $keepId]);
            $out['moved'][] = (int) $row->id;
        }

        return $out;
    }

    /** @return array{moved: list<int>, dropped: list<array<string, mixed>>} entry ids */
    private function moveGradebookEntries(int $keepId, int $mergeId): array
    {
        $out = ['moved' => [], 'dropped' => []];
        foreach ($this->entryPairs($keepId, $mergeId) as [, $d]) {
            DB::table('gradebook_entries')->where('id', $d->id)->delete(); // equal to K's (conflicts() checked)
            $out['dropped'][] = (array) $d;
        }
        $out['moved'] = $this->moveColumn('gradebook_entries', 'student_id', $keepId, $mergeId);

        return $out;
    }

    /**
     * Pairs of entries of the same assignment or gradebook item: [K's, D's, label].
     *
     * @return list<array{0: object, 1: object, 2: string}>
     */
    private function entryPairs(int $keepId, int $mergeId): array
    {
        $pairs = [];
        $kEntries = DB::table('gradebook_entries')->where('student_id', $keepId)->get();
        $byAssignment = $kEntries->whereNotNull('assignment_id')->keyBy('assignment_id');
        $byItem = $kEntries->whereNotNull('gradebook_item_id')->keyBy('gradebook_item_id');
        foreach (DB::table('gradebook_entries')->where('student_id', $mergeId)->orderBy('id')->get() as $d) {
            $k = $d->assignment_id !== null ? $byAssignment->get($d->assignment_id) : $byItem->get($d->gradebook_item_id);
            if ($k === null) {
                continue;
            }
            $label = $d->assignment_id !== null
                ? (string) DB::table('assignments')->where('id', $d->assignment_id)->value('title')
                : (string) DB::table('gradebook_items')->where('id', $d->gradebook_item_id)->value('name');
            $pairs[] = [$k, $d, $label];
        }

        return $pairs;
    }

    private static function sameEntry(object $k, object $d): bool
    {
        $score = fn ($v) => $v === null ? null : round((float) $v, 2);

        return $score($k->score) === $score($d->score) && (bool) $k->excused === (bool) $d->excused;
    }

    /**
     * Moves D's rows of a table keyed by (student, $keys): where K already
     * has a row for the same key, K's stays and D's is dropped.
     *
     * @param  list<string>  $keys
     * @return array{moved: list<mixed>, dropped: list<array<string, mixed>>}
     */
    private function moveKeyed(string $table, array $keys, int $keepId, int $mergeId, ?string $idColumn = null): array
    {
        $out = ['moved' => [], 'dropped' => []];
        foreach (DB::table($table)->where('student_id', $mergeId)->get() as $row) {
            $match = fn ($q) => $q->where(collect($keys)->mapWithKeys(fn ($k) => [$k => $row->{$k}])->all());
            $where = DB::table($table)->where('student_id', $mergeId)->where($match);
            if (DB::table($table)->where('student_id', $keepId)->where($match)->exists()) {
                $where->delete();
                $out['dropped'][] = (array) $row;

                continue;
            }
            $where->update(['student_id' => $keepId]);
            $out['moved'][] = $idColumn !== null ? (int) $row->{$idColumn} : collect($keys)->mapWithKeys(fn ($k) => [$k => (int) $row->{$k}])->all();
        }

        return $out;
    }

    /** @return list<int> ids of the moved rows */
    private function moveColumn(string $table, string $column, int $keepId, int $mergeId): array
    {
        $ids = DB::table($table)->where($column, $mergeId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids !== []) {
            DB::table($table)->where($column, $mergeId)->update([$column => $keepId]);
        }

        return $ids;
    }
}
