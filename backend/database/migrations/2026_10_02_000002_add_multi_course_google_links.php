<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §24.3 D (build 4): several Google Classroom courses per classroom,
 * one per teacher. The homeroom teacher and each subject teacher link their
 * own course to the same classroom; app_course_id says which app course the
 * Google course is. Work of a course is posted and synced through the link
 * whose owner_user_id is the course's creator.
 *
 * The primary key moves from classroom_id to a new id, which SQLite (tests)
 * cannot alter in place, so the table is rebuilt: a new table is created
 * under a temporary name, the rows are copied, the old table is dropped and
 * the new one renamed. Foreign keys get explicit names (cgl_*) because
 * MariaDB constraint names are unique per database and the old table's
 * names are still taken while it exists.
 *
 * Data (§24.15 step 5): every row is copied; app_course_id is set when the
 * classroom has exactly one bound course created by the link's owner,
 * otherwise NULL (the teacher picks it later). course_id was unique by code
 * before; a duplicate (there should be none) keeps the row of the lowest
 * classroom id and is logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_google_links_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained('classrooms', 'id', 'cgl_classroom_fk')->cascadeOnDelete();
            $table->string('course_id', 64);
            $table->string('course_name');
            $table->foreignId('owner_user_id')->constrained('users', 'id', 'cgl_owner_fk')->restrictOnDelete();
            $table->foreignId('app_course_id')->nullable()->constrained('courses', 'id', 'cgl_app_course_fk')->nullOnDelete();
            $table->timestamp('linked_at')->useCurrent(); // explicit default, see SchemaTest
            $table->timestamp('roster_synced_at')->nullable();
            $table->timestamp('work_synced_at')->nullable();
            $table->unique('course_id', 'uq_google_course');
            $table->unique(['classroom_id', 'owner_user_id'], 'uq_class_google_owner');
        });

        $seen = [];
        foreach (DB::table('classroom_google_links')->orderBy('classroom_id')->get() as $row) {
            if (isset($seen[$row->course_id])) {
                Log::warning('migration.google_link_duplicate_course', ['classroom_id' => $row->classroom_id]);

                continue;
            }
            $seen[$row->course_id] = true;
            DB::table('classroom_google_links_v2')->insert([
                'classroom_id' => $row->classroom_id,
                'course_id' => $row->course_id,
                'course_name' => $row->course_name,
                'owner_user_id' => $row->owner_user_id,
                'app_course_id' => $this->onlyOwnCourse((int) $row->classroom_id, (int) $row->owner_user_id),
                'linked_at' => $row->linked_at,
                'roster_synced_at' => $row->roster_synced_at,
                'work_synced_at' => $row->work_synced_at,
            ]);
        }

        Schema::drop('classroom_google_links');
        Schema::rename('classroom_google_links_v2', 'classroom_google_links');
    }

    public function down(): void
    {
        Schema::create('classroom_google_links_v1', function (Blueprint $table) {
            $table->foreignId('classroom_id')->primary()->constrained('classrooms', 'id', 'cgl1_classroom_fk')->cascadeOnDelete();
            $table->string('course_id', 64);
            $table->string('course_name');
            $table->foreignId('owner_user_id')->constrained('users', 'id', 'cgl1_owner_fk')->restrictOnDelete();
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamp('roster_synced_at')->nullable();
            $table->timestamp('work_synced_at')->nullable();
            $table->index('course_id');
        });

        // One course per classroom again: the homeroom teacher's, else the oldest link.
        $rows = DB::table('classroom_google_links')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_google_links.classroom_id')
            ->orderBy('classroom_google_links.classroom_id')
            ->orderByRaw('CASE WHEN classroom_google_links.owner_user_id = classrooms.teacher_id THEN 0 ELSE 1 END')
            ->orderBy('classroom_google_links.id')
            ->get(['classroom_google_links.*']);
        $kept = [];
        foreach ($rows as $row) {
            if (isset($kept[$row->classroom_id])) {
                Log::warning('migration.google_link_dropped_on_rollback', ['classroom_id' => $row->classroom_id, 'owner_user_id' => $row->owner_user_id]);

                continue;
            }
            $kept[$row->classroom_id] = true;
            DB::table('classroom_google_links_v1')->insert([
                'classroom_id' => $row->classroom_id,
                'course_id' => $row->course_id,
                'course_name' => $row->course_name,
                'owner_user_id' => $row->owner_user_id,
                'linked_at' => $row->linked_at,
                'roster_synced_at' => $row->roster_synced_at,
                'work_synced_at' => $row->work_synced_at,
            ]);
        }

        Schema::drop('classroom_google_links');
        Schema::rename('classroom_google_links_v1', 'classroom_google_links');
    }

    /** The one course of $ownerId bound to the classroom, or null. */
    private function onlyOwnCourse(int $classroomId, int $ownerId): ?int
    {
        $ids = DB::table('course_classroom')
            ->join('courses', 'courses.id', '=', 'course_classroom.course_id')
            ->where('course_classroom.classroom_id', $classroomId)
            ->where('courses.created_by', $ownerId)
            ->limit(2)
            ->pluck('courses.id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
};
