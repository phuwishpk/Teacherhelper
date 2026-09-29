<?php

namespace App\Domain\Google;

use App\Models\ClassroomFeedbackPost;
use App\Models\ClassroomStudent;
use App\Models\GoogleAccount;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The private announcement of a published result (DESIGN §19.7): for every
 * assignment in Google Classroom (courseWork the app posted or mirrored from
 * the Classroom website), each publish sends the student one announcement
 * with assigneeMode INDIVIDUAL_STUDENTS carrying
 *
 *   the assignment title and the effective total "x/y" (§19.3),
 *   the per-question explanations (the teacher's edited text when there is
 *   one; responses.explanation always holds the current text), cut to
 *   3,000 characters in all; none for score_only assignments,
 *   the link APP_URL/r/{submission_id} (a Thai page that opens the app).
 *
 * queue() records a `classroom_feedback_posts` row per publish;
 * PostClassroomFeedbackJob calls post(). A student is only ever sent their
 * own result: post() re-reads the roster match and refuses when the
 * student's Classroom account is no longer the one the row was queued for.
 */
final class ClassroomFeedback
{
    public const POSTED = 'posted';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /** The explanations part of the text (§19.7). */
    public const MAX_EXPLANATION_CHARS = 3000;

    public function __construct(private readonly GoogleAccessTokens $tokens) {}

    /**
     * The row for the submission's current publish, or null when there is
     * nothing to announce (not published, the assignment is not in
     * Classroom, or the student has no matched Classroom account).
     * $created says whether this call created the row.
     */
    public static function queue(int $submissionId, ?bool &$created = null): ?ClassroomFeedbackPost
    {
        $created = false;
        $submission = Submission::query()->with('assignment.googleLink', 'assignment.classroom.googleLink')->find($submissionId);
        $assignment = $submission?->assignment;
        if ($submission === null || ! $submission->isPublished() || $submission->published_at === null
            || $assignment?->googleLink === null || $assignment->classroom?->googleLink === null) {
            return null;
        }
        $googleUserId = self::matchedAccount($assignment->classroom_id, $submission->student_id);
        if ($googleUserId === null) {
            return null;
        }

        try {
            $post = ClassroomFeedbackPost::query()->firstOrCreate(
                ['submission_id' => $submission->id, 'published_at' => $submission->published_at],
                ['course_id' => $assignment->classroom->googleLink->course_id, 'google_user_id' => $googleUserId],
            );
        } catch (UniqueConstraintViolationException) {
            return ClassroomFeedbackPost::query()
                ->where('submission_id', $submission->id)
                ->where('published_at', $submission->published_at)
                ->first();
        }
        $created = $post->wasRecentlyCreated;

        return $post;
    }

    /**
     * Sends the announcement of one row.
     *
     * @return self::POSTED|self::SKIPPED|self::FAILED
     *
     * @throws GoogleApiException only transient ones (unavailable)
     */
    public function post(int $postId): string
    {
        $post = ClassroomFeedbackPost::query()->find($postId);
        if ($post === null || $post->state === ClassroomFeedbackPost::STATE_POSTED) {
            return self::SKIPPED;
        }
        $submission = Submission::query()->with('assignment.googleLink', 'assignment.classroom.googleLink')->find($post->submission_id);
        if ($submission === null || ! $submission->isPublished() || $submission->published_at === null
            || ! $submission->published_at->equalTo($post->published_at)) {
            // Reopened or published again since: that publish has its own
            // row (or none yet); this result is stale and was never sent.
            $post->delete();

            return self::SKIPPED;
        }
        $assignment = $submission->assignment;
        $posted = $assignment?->googleLink;
        $link = $assignment?->classroom?->googleLink;
        if ($posted === null || $link === null) {
            return $this->fail($post, 'การบ้านหรือห้องเรียนนี้ไม่ได้ผูกกับ Google Classroom แล้ว');
        }
        if ($link->course_id !== $post->course_id) {
            return $this->fail($post, 'ห้องเรียนถูกผูกกับคอร์สอื่นแล้ว กดส่งประกาศอีกครั้งเพื่อส่งในคอร์สปัจจุบัน');
        }
        if (self::matchedAccount($assignment->classroom_id, $submission->student_id) !== $post->google_user_id) {
            // Never send a result to a Classroom account that is no longer this student's.
            return $this->fail($post, 'นักเรียนคนนี้ไม่ได้จับคู่กับบัญชี Google เดิมแล้ว จับคู่นักเรียนแล้วกดส่งประกาศอีกครั้ง');
        }

        $account = $this->accountFor([$posted->posted_by, $link->owner_user_id]);
        if (is_string($account)) {
            return $this->fail($post, $account);
        }

        try {
            $created = GoogleApi::forAccount($account, $this->tokens)
                ->createPrivateAnnouncement($post->course_id, self::text($submission), [$post->google_user_id]);
        } catch (GoogleApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            Log::warning('google.feedback_failed', ['post_id' => $post->id, 'kind' => $e->kind, 'message' => $e->getMessage()]);

            return $this->fail($post, self::errorText($e));
        }

        $post->state = ClassroomFeedbackPost::STATE_POSTED;
        $post->announcement_id = $created['id'] !== '' ? mb_substr($created['id'], 0, 64) : null;
        $post->posted_at = now();
        $post->last_error = null;
        $post->save();
        Log::info('google.feedback_posted', ['post_id' => $post->id, 'submission_id' => $submission->id]);

        return self::POSTED;
    }

    /** Records a failure the queue gave up on (PostClassroomFeedbackJob after its last attempt). */
    public function markFailed(int $postId, string $reason): void
    {
        $post = ClassroomFeedbackPost::query()->find($postId);
        if ($post !== null && $post->state !== ClassroomFeedbackPost::STATE_POSTED) {
            $this->fail($post, $reason);
        }
    }

    /**
     * Puts a failed row back in the queue with the student's current match
     * and course (the teacher's "ส่งประกาศอีกครั้ง"). False when there is
     * still nothing to send to (no match, or the classroom is not linked);
     * the row then stays failed with the reason.
     */
    public static function requeue(ClassroomFeedbackPost $post): bool
    {
        $submission = Submission::query()->with('assignment.classroom.googleLink')->find($post->submission_id);
        $link = $submission?->assignment?->classroom?->googleLink;
        $googleUserId = $submission !== null && $link !== null ? self::matchedAccount($submission->assignment->classroom_id, $submission->student_id) : null;
        if ($link === null || $googleUserId === null) {
            $post->last_error = $link === null
                ? 'ห้องเรียนไม่ได้ผูกกับ Google Classroom แล้ว'
                : 'นักเรียนคนนี้ยังไม่ได้จับคู่กับบัญชี Google จับคู่นักเรียนแล้วกดส่งประกาศอีกครั้ง';
            $post->save();

            return false;
        }
        $post->course_id = $link->course_id;
        $post->google_user_id = $googleUserId;
        $post->state = ClassroomFeedbackPost::STATE_QUEUED;
        $post->last_error = null;
        $post->save();

        return true;
    }

    /**
     * The announcement text (DESIGN §19.7). Plain text: Classroom shows
     * line breaks and turns the URL into a link.
     */
    public static function text(Submission $submission): string
    {
        $assignment = $submission->assignment;
        $max = (float) Question::query()->where('assignment_id', $submission->assignment_id)->sum('max_points');
        $lines = [
            'ผลการตรวจ: '.trim((string) $assignment?->title),
            'คะแนน '.self::number($submission->effectiveTotal() ?? 0.0).'/'.self::number($max),
        ];

        // score_only work has no explanations section: its responses hold
        // only template lines (FeedbackTemplates), not explanations.
        $explanations = $assignment?->score_only ? '' : self::explanations($submission);
        if ($explanations !== '') {
            $lines[] = '';
            $lines[] = 'คำอธิบายรายข้อ';
            $lines[] = $explanations;
        }

        $lines[] = '';
        $lines[] = 'ดูผลละเอียดในแอป EduVision: '.self::resultUrl($submission->id);

        return implode("\n", $lines);
    }

    /** The public page that opens the result in the app (GET /r/{submission_id}). */
    public static function resultUrl(int $submissionId): string
    {
        return rtrim((string) config('app.url'), '/').'/r/'.$submissionId;
    }

    /**
     * "ข้อ n: ..." per question with an explanation, in question order, cut
     * to MAX_EXPLANATION_CHARS in all.
     */
    private static function explanations(Submission $submission): string
    {
        $responses = Response::query()
            ->with('question:id,position')
            ->where('submission_id', $submission->id)
            ->whereNotNull('explanation')
            ->get(['id', 'question_id', 'explanation'])
            ->filter(fn (Response $r) => trim((string) $r->explanation) !== '')
            ->sortBy(fn (Response $r) => [(int) $r->question?->position, $r->id]);

        $text = $responses
            ->map(fn (Response $r) => 'ข้อ '.(int) $r->question?->position.': '.trim(preg_replace('/\s+/u', ' ', (string) $r->explanation) ?? ''))
            ->implode("\n");
        if (mb_strlen($text) <= self::MAX_EXPLANATION_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_EXPLANATION_CHARS - 1)).'…';
    }

    private static function number(float $value): string
    {
        $text = number_format(round($value, 2), 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    private static function matchedAccount(int $classroomId, int $studentId): ?string
    {
        $id = ClassroomStudent::query()
            ->where('classroom_id', $classroomId)
            ->where('student_id', $studentId)
            ->value('google_user_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The first usable account among the teachers who can post in the
     * course (who posted or mirrored the courseWork, then the owner of the
     * classroom's link), or why none can (Thai).
     *
     * @param  list<int>  $userIds
     */
    private function accountFor(array $userIds): GoogleAccount|string
    {
        $reason = null;
        foreach (array_unique($userIds) as $userId) {
            $account = GoogleAccount::query()->find($userId);
            if ($account === null) {
                continue;
            }
            if (! $account->needsReconnect()) {
                return $account;
            }
            $reason ??= $account->lacksAnnouncementsScope()
                ? GoogleAccount::ANNOUNCEMENTS_RECONNECT_MESSAGE
                : 'ต้องเชื่อมบัญชี Google ใหม่ แล้วกดส่งประกาศอีกครั้ง';
        }

        return $reason ?? 'บัญชี Google ของครูที่ผูกคอร์สนี้ไม่ได้เชื่อมอยู่ เชื่อมบัญชีแล้วกดส่งประกาศอีกครั้ง';
    }

    public static function errorText(GoogleApiException $e): string
    {
        return match ($e->kind) {
            GoogleApiException::SCOPE_MISSING => GoogleAccount::ANNOUNCEMENTS_RECONNECT_MESSAGE,
            GoogleApiException::INVALID_GRANT => 'ต้องเชื่อมบัญชี Google ใหม่ แล้วกดส่งประกาศอีกครั้ง',
            GoogleApiException::PERMISSION_DENIED, GoogleApiException::PROJECT_PERMISSION_DENIED => 'Google ไม่อนุญาตให้ส่งประกาศในคอร์สนี้ (บัญชีอาจไม่ได้เป็นครูของคอร์ส)',
            GoogleApiException::NOT_FOUND => 'ไม่พบคอร์สหรือนักเรียนคนนี้ใน Google Classroom แล้ว',
            GoogleApiException::UNAVAILABLE => 'ติดต่อ Google ไม่ได้ ลองส่งประกาศอีกครั้งภายหลัง',
            GoogleApiException::NOT_CONFIGURED, GoogleApiException::API_DISABLED => GoogleErrors::shortText($e),
            default => mb_substr('Google ไม่รับประกาศนี้'.($e->googleMessage !== null && $e->googleMessage !== '' ? ': '.$e->googleMessage : ''), 0, 250),
        };
    }

    private function fail(ClassroomFeedbackPost $post, string $reason): string
    {
        $post->state = ClassroomFeedbackPost::STATE_FAILED;
        $post->last_error = mb_substr($reason, 0, 255);
        $post->save();
        Log::warning('google.feedback_not_posted', ['post_id' => $post->id, 'submission_id' => $post->submission_id]);

        return self::FAILED;
    }
}
