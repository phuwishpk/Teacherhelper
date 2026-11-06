<?php

namespace App\Domain\Accounts;

use App\Domain\Classrooms\ClassroomLifecycle;
use App\Domain\Documents\SourceDocuments;
use App\Domain\Google\GoogleAccounts;
use App\Domain\Pages\PageFiles;
use App\Domain\Scans\ScanFiles;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The second half of an account deletion (DESIGN §31.3): once the 30 days
 * to restore are over, the account's personal data goes for good. Runs from
 * eduvision:purge-images (the daily Plesk task), once per account: the
 * `account.purge` audit row marks it done.
 *
 * The users row itself stays, because about 70 foreign keys point at it
 * with RESTRICT and a student's score rows must keep their owner so class
 * averages do not change. What happens to every column pointing at users is
 * listed in USER_COLUMNS (AccountPurgeCoverageTest keeps it complete).
 */
final class AccountPurge
{
    public const STUDENT_NAME = 'นักเรียนที่ถูกลบ';

    public const TEACHER_NAME = 'ครูที่ถูกลบ';

    /** Rows of the purged account deleted (both roles, unless noted). */
    public const DELETE = 'delete';

    /** Rows kept, their owner shown with the anonymous name. */
    public const KEEP = 'keep';

    /** Teacher: deleted with the classrooms the deletion closed, or by the teacher steps below. */
    public const TEACHER_DATA = 'teacher data';

    /** A staff column (who approved, published, resolved…): history, kept. */
    public const STAFF = 'staff';

    /**
     * Every foreign key to users.id and what the purge does with the rows of
     * the purged account.
     */
    public const USER_COLUMNS = [
        // The account's own sign-in and personal rows.
        'student_credentials.student_id' => self::DELETE,
        'user_google_identities.user_id' => self::DELETE,
        'user_google_identities.linked_by' => self::STAFF,
        'device_tokens.user_id' => self::DELETE,
        'login_card_prints.student_id' => self::DELETE, // with the PDF files
        'login_card_prints.requested_by' => self::STAFF,
        'google_events.user_id' => self::DELETE, // they hold the Google account's email
        'google_accounts.user_id' => self::DELETE, // teacher: revoked at Google first
        'teacher_api_keys.user_id' => self::DELETE,
        'teacher_indicators.user_id' => self::DELETE,
        'assistant_conversations.user_id' => self::DELETE, // messages and labels cascade
        'class_post_files.owner_id' => self::DELETE, // with the files and the posts that attach them
        'class_posts.author_id' => self::DELETE, // teacher: the posts they wrote in classrooms that stay
        'source_documents.uploaded_by' => self::DELETE, // with the file unless another row shares it
        'classroom_google_links.owner_user_id' => self::DELETE, // the Google grant is gone
        'classroom_teachers.teacher_id' => self::DELETE, // co-teaching in classrooms that stay
        'classroom_teachers.invited_by' => self::STAFF,
        'assignment_recipients.student_id' => self::KEEP,
        'class_post_recipients.student_id' => self::KEEP,

        // A student's school record: kept, without a name (score rows, §31).
        'classroom_students.student_id' => self::KEEP,
        'submissions.student_id' => self::KEEP, // the answer images are deleted
        'appeals.student_id' => self::KEEP,
        'gradebook_entries.student_id' => self::KEEP,
        'attendance_records.student_id' => self::KEEP,
        'gradebook_special_grades.student_id' => self::KEEP,
        'gradebook_published_grades.student_id' => self::KEEP,
        'skill_observations.student_id' => self::KEEP,
        'mastery.student_id' => self::KEEP,
        'practice_attempts.student_id' => self::KEEP,
        'classroom_submission_imports.student_id' => self::KEEP,
        'scans.uploaded_by' => self::KEEP, // the page image is deleted
        'submission_pages.uploaded_by' => self::KEEP, // the page file is deleted
        'student_analyses.student_id' => self::KEEP,
        'score_events.actor_user_id' => self::KEEP,

        // A teacher's work: the classrooms they owned go (TEACHER_DATA); what
        // other teachers share stays under "ครูที่ถูกลบ".
        'classrooms.teacher_id' => self::TEACHER_DATA,
        'assignments.created_by' => self::KEEP,
        'courses.created_by' => self::KEEP,
        'subjects.owner_user_id' => self::KEEP,
        'skills.created_by' => self::KEEP,
        'gradebook_items.created_by' => self::KEEP,
        'attendance_sessions.created_by' => self::KEEP,
        'timetable_slots.created_by' => self::KEEP,
        'timetable_cancellations.created_by' => self::KEEP,
        'learning_resources.added_by' => self::KEEP,
        'practice_items.approved_by' => self::KEEP,
        'document_extractions.requested_by' => self::KEEP, // text only, no file
        'exam_imports.requested_by' => self::KEEP,
        'exam_page_images.uploaded_by' => self::KEEP,
        'analysis_batches.key_owner_id' => self::KEEP,
        'classroom_course_requests.requested_by' => self::KEEP,

        // Who did what: history, kept.
        'users.approved_by' => self::STAFF,
        'users.merged_into_id' => self::STAFF,
        'classrooms.closed_by' => self::STAFF,
        'assignments.key_approved_by' => self::STAFF,
        'worksheet_prints.requested_by' => self::STAFF,
        'submissions.published_by' => self::STAFF,
        'responses.reviewed_by' => self::STAFF,
        'appeals.resolved_by' => self::STAFF,
        'assignment_google_links.posted_by' => self::STAFF,
        'grade_conflicts.resolved_by' => self::STAFF,
        'student_analyses.approved_by' => self::STAFF,
        'ai_calls.guidance_by' => self::STAFF,
        'gradebook_entries.updated_by' => self::STAFF,
        'attendance_records.updated_by' => self::STAFF,
        'gradebook_special_grades.set_by' => self::STAFF,
        'gradebook_publications.published_by' => self::STAFF,
        'student_merges.kept_student_id' => self::STAFF,
        'student_merges.merged_student_id' => self::STAFF,
        'student_merges.merged_by' => self::STAFF,
        'classroom_course_requests.decided_by' => self::STAFF,
        'audit_logs.actor_id' => self::STAFF,
        'audit_logs.target_user_id' => self::STAFF,
    ];

    public function __construct(
        private readonly ClassroomLifecycle $classrooms,
        private readonly GoogleAccounts $google,
    ) {}

    /**
     * Deleted teachers and students whose 30 days are over and who were not
     * purged yet.
     *
     * @return Builder<User>
     */
    public static function due(?CarbonInterface $now = null): Builder
    {
        $cutoff = ($now ?? now())->copy()->subDays(AccountDeletion::RESTORE_DAYS);

        return User::query()
            ->whereIn('role', [User::ROLE_TEACHER, User::ROLE_STUDENT])
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('audit_logs')
                ->whereColumn('audit_logs.target_user_id', 'users.id')
                ->where('audit_logs.action', AuditLog::ACCOUNT_PURGE));
    }

    public static function isPurged(User $user): bool
    {
        return $user->deleted_at !== null
            && AuditLog::query()->where('target_user_id', $user->id)->where('action', AuditLog::ACCOUNT_PURGE)->exists();
    }

    /**
     * Purges every due account; one failing account is logged and skipped,
     * so it is tried again the next day.
     *
     * @return array{students: int, teachers: int, failed: int}
     */
    public function purgeDue(?CarbonInterface $now = null): array
    {
        $done = ['students' => 0, 'teachers' => 0, 'failed' => 0];
        foreach (self::due($now)->orderBy('id')->get() as $user) {
            try {
                $this->purge($user);
                $done[$user->isTeacher() ? 'teachers' : 'students']++;
            } catch (Throwable $e) {
                $done['failed']++;
                Log::error('account.purge_failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);
            }
        }

        return $done;
    }

    /**
     * @return array<string, int> what went, also stored on the audit row
     */
    public function purge(User $user): array
    {
        $purgeAt = AccountDeletion::purgeAt($user);
        if ($purgeAt === null || $purgeAt->isFuture() || ! ($user->isTeacher() || $user->isStudent())) {
            throw new \LogicException("user {$user->id} is not due for the purge");
        }

        $counts = $user->isTeacher() ? $this->purgeTeacher($user) : $this->purgeStudent($user);
        $this->forgetPerson($user);

        $actor = User::query()->find($user->deleted_by) ?? $user;
        AuditLog::record($actor, AuditLog::ACCOUNT_PURGE, $user, $counts);
        Log::info('account.purge', ['user_id' => $user->id, 'role' => $user->role, ...$counts]);

        return $counts;
    }

    /**
     * The images of the student's work (crops, scanned pages, whole-page
     * files, training copies) and their sign-in cards. Score rows stay.
     *
     * @return array<string, int>
     */
    private function purgeStudent(User $student): array
    {
        $files = ['scan' => [], 'page' => [], 'local' => []];
        DB::transaction(function () use ($student, &$files) {
            $submissions = DB::table('submissions')->select('id')->where('student_id', $student->id);
            $responses = DB::table('responses')->select('id')->whereIn('submission_id', $submissions);

            foreach (DB::table('responses')->whereIn('submission_id', $submissions)
                ->where(fn ($q) => $q->whereNotNull('crop_path')->orWhereNotNull('final_crop_path'))
                ->get(['crop_path', 'final_crop_path']) as $r) {
                array_push($files['scan'], ...array_filter([$r->crop_path, $r->final_crop_path]));
            }
            DB::table('responses')->whereIn('submission_id', $submissions)->update(['crop_path' => null, 'final_crop_path' => null]);

            array_push($files['scan'], ...DB::table('scans')->whereIn('submission_id', $submissions)->whereNotNull('page_image_path')->pluck('page_image_path')->all());
            DB::table('scans')->whereIn('submission_id', $submissions)->update(['page_image_path' => null]);

            // The handwriting copies kept for training the digit model (§16).
            array_push($files['scan'], ...DB::table('training_samples')->whereIn('response_id', $responses)->whereNotNull('crop_path')->pluck('crop_path')->all());
            DB::table('training_samples')->whereIn('response_id', $responses)->delete();

            array_push($files['page'], ...DB::table('submission_pages')->whereIn('submission_id', $submissions)->whereNotNull('file_path')->pluck('file_path')->all());
            DB::table('submission_pages')->whereIn('submission_id', $submissions)->update(['file_path' => null]);

            array_push($files['local'], ...DB::table('login_card_prints')->where('student_id', $student->id)->whereNotNull('file_path')->pluck('file_path')->all());
            DB::table('login_card_prints')->where('student_id', $student->id)->delete();
            DB::table('student_credentials')->where('student_id', $student->id)->delete();
        });

        return [
            'files' => self::deleteFiles(ScanFiles::disk(), $files['scan'])
                + self::deleteFiles(PageFiles::disk(), $files['page'])
                + self::deleteFiles(Storage::disk('local'), $files['local']),
        ];
    }

    /**
     * The classrooms the deletion closed (with everything in them), the
     * Google Classroom grant, the Gemini key, assistant conversations, the
     * teacher's files and posts, and co-teaching seats in classrooms that stay.
     *
     * @return array<string, int>
     */
    private function purgeTeacher(User $teacher): array
    {
        $counts = ['classrooms' => 0, 'assignments' => 0, 'submissions' => 0, 'files' => 0];
        $rooms = Classroom::query()
            ->where('teacher_id', $teacher->id)
            ->where('deleted_at', $teacher->deleted_at)
            ->orderBy('id')
            ->get();
        foreach ($rooms as $room) {
            $gone = $this->classrooms->purge($room);
            $counts['classrooms']++;
            $counts['assignments'] += $gone['assignments'];
            $counts['submissions'] += $gone['submissions'];
            $counts['files'] += $gone['files'];
        }

        // Revoked at Google, then deleted (no-op without a connection).
        $this->google->disconnect($teacher);

        $files = ['local' => [], 'documents' => []];
        DB::transaction(function () use ($teacher, &$files) {
            DB::table('classroom_google_links')->where('owner_user_id', $teacher->id)->delete();
            DB::table('classroom_teachers')->where('teacher_id', $teacher->id)->delete();
            DB::table('teacher_api_keys')->where('user_id', $teacher->id)->delete();
            DB::table('teacher_indicators')->where('user_id', $teacher->id)->delete();
            DB::table('assistant_conversations')->where('user_id', $teacher->id)->delete();

            // Posts the teacher wrote in classrooms that stay, then their files
            // (attachments RESTRICT the file rows).
            DB::table('class_posts')->where('author_id', $teacher->id)->delete();
            $fileIds = DB::table('class_post_files')->select('id')->where('owner_id', $teacher->id);
            DB::table('assignment_attachments')->whereIn('class_post_file_id', $fileIds)->delete();
            DB::table('class_post_attachments')->whereIn('class_post_file_id', $fileIds)->delete();
            $files['local'] = DB::table('class_post_files')->where('owner_id', $teacher->id)->pluck('path')->all();
            DB::table('class_post_files')->where('owner_id', $teacher->id)->delete();

            // Documents are stored by content: keep a file another uploader still uses.
            foreach (DB::table('source_documents')->where('uploaded_by', $teacher->id)->whereNotNull('file_path')->distinct()->pluck('file_path') as $path) {
                $shared = DB::table('source_documents')->where('file_path', $path)->where('uploaded_by', '!=', $teacher->id)->exists();
                if (! $shared) {
                    $files['documents'][] = $path;
                }
            }
            DB::table('source_documents')->where('uploaded_by', $teacher->id)->delete();
        });

        $counts['files'] += self::deleteFiles(Storage::disk('local'), $files['local'])
            + self::deleteFiles(SourceDocuments::disk(), $files['documents']);

        return $counts;
    }

    /** Both roles: the person behind the account and every way back in. */
    private function forgetPerson(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            DB::table('device_tokens')->where('user_id', $user->id)->delete();
            DB::table('user_google_identities')->where('user_id', $user->id)->delete();
            DB::table('google_events')->where('user_id', $user->id)->delete();
            $user->forceFill([
                'name' => $user->isTeacher() ? self::TEACHER_NAME : self::STUDENT_NAME,
                'email' => null,
                'username' => null,
                'student_code' => null,
                'school_name' => null,
                // Unusable: nobody knows it and the account stays deleted anyway.
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'assistant_data_ack_at' => null,
            ])->save();
        });
    }

    /**
     * @param  list<string>  $paths
     */
    private static function deleteFiles(Filesystem $disk, array $paths): int
    {
        $paths = array_values(array_unique(array_filter($paths)));
        foreach (array_chunk($paths, 200) as $chunk) {
            $disk->delete($chunk);
        }

        return count($paths);
    }
}
