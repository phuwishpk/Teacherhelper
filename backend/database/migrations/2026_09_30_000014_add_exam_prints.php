<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.14 (Phase 10 build step 2, printing exams): worksheet_prints
 * also holds exam prints.
 *
 * - kind: worksheet | exam_booklet | answer_sheet | key_sheet;
 * - version_no: the version of an exam_booklet;
 * - layout_version becomes nullable: a booklet has no layout (it carries no
 *   QR and is never scanned), so it names none. Answer and key sheets name
 *   the answer-sheet layout version they were drawn from, like worksheets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheet_prints', function (Blueprint $table) {
            $table->enum('kind', ['worksheet', 'exam_booklet', 'answer_sheet', 'key_sheet'])->default('worksheet');
            $table->unsignedTinyInteger('version_no')->nullable();
        });
        Schema::table('worksheet_prints', function (Blueprint $table) {
            $table->unsignedSmallInteger('layout_version')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('worksheet_prints')->whereNull('layout_version')->delete();
        Schema::table('worksheet_prints', function (Blueprint $table) {
            $table->unsignedSmallInteger('layout_version')->nullable(false)->change();
        });
        Schema::table('worksheet_prints', function (Blueprint $table) {
            $table->dropColumn(['kind', 'version_no']);
        });
    }
};
