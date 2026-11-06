<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §23.10 (Phase 11, the gradebook):
 *
 * - gradebook_categories: the weighted categories of a course (sum 100.00,
 *   checked in code), one of them the default of new homework;
 * - courses.grade_cutoffs / gradebook_template: the 8-level cutoffs (NULL =
 *   the default) and the template the categories started from;
 * - assignments.gradebook_category_id / excluded_from_grade ("ไม่นับเกรด");
 * - gradebook_items: the teacher's own score items of one classroom;
 * - gradebook_entries: typed scores and "ยกเว้น" per (student, item) or
 *   (student, assignment); exactly one of the two ids is set (a CHECK on
 *   MariaDB, checked in code as well because the tests run on SQLite);
 * - gradebook_special_grades: ร / มส set by the teacher;
 * - gradebook_publications / gradebook_published_grades: the snapshot a
 *   classroom's students see. total / total_rounded are NULL for a student
 *   with no value in any category (everything excused), see §23.10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gradebook_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('name', 100);
            $table->decimal('weight', 5, 2);
            $table->unsignedTinyInteger('drop_lowest')->default(0);
            $table->boolean('is_homework_default')->default(false);
            $table->timestamps();
            $table->unique(['course_id', 'position'], 'uq_category_position');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->json('grade_cutoffs')->nullable();
            $table->string('gradebook_template', 40)->nullable();
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('gradebook_category_id')->nullable()->constrained('gradebook_categories')->nullOnDelete();
            $table->boolean('excluded_from_grade')->default(false);
        });

        Schema::create('gradebook_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('gradebook_categories')->nullOnDelete();
            $table->string('name', 100);
            $table->decimal('max_points', 6, 2);
            $table->boolean('is_attendance')->default(false);
            $table->unsignedSmallInteger('position');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['classroom_id', 'course_id'], 'idx_items_class');
        });

        Schema::create('gradebook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('assignment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('gradebook_item_id')->nullable()->constrained('gradebook_items')->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('excused')->default(false);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id'], 'uq_entry_assignment');
            $table->unique(['gradebook_item_id', 'student_id'], 'uq_entry_item');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE gradebook_entries ADD CONSTRAINT chk_entry_target CHECK ((assignment_id IS NULL) <> (gradebook_item_id IS NULL))');
        }

        Schema::create('gradebook_special_grades', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->enum('special', ['r', 'ms']);
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->constrained('users');
            $table->timestamps();
            $table->primary(['course_id', 'classroom_id', 'student_id']);
        });

        Schema::create('gradebook_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->json('categories');
            $table->json('cutoffs');
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at')->useCurrent(); // explicit default, see SchemaTest
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['course_id', 'classroom_id', 'published_at'], 'idx_publications');
        });

        Schema::create('gradebook_published_grades', function (Blueprint $table) {
            $table->foreignId('publication_id')->constrained('gradebook_publications')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->json('breakdown');
            $table->decimal('total', 7, 4)->nullable();
            $table->unsignedTinyInteger('total_rounded')->nullable();
            $table->decimal('grade', 2, 1)->nullable();
            $table->enum('special', ['r', 'ms'])->nullable();
            $table->boolean('attendance_warning')->default(false);
            $table->primary(['publication_id', 'student_id']);
            $table->index('student_id', 'idx_published_student');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gradebook_published_grades');
        Schema::dropIfExists('gradebook_publications');
        Schema::dropIfExists('gradebook_special_grades');
        Schema::dropIfExists('gradebook_entries');
        Schema::dropIfExists('gradebook_items');
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gradebook_category_id');
            $table->dropColumn('excluded_from_grade');
        });
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['grade_cutoffs', 'gradebook_template']);
        });
        Schema::dropIfExists('gradebook_categories');
    }
};
