<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.20.7: an exam section may be written by hand, `open` or
 * `show_work`. Only the enum of exam_sections.type changes; the questions
 * table already has both types.
 */
return new class extends Migration
{
    private const TYPES = ['mcq', 'true_false', 'numeric'];

    private const TYPES_NEW = ['mcq', 'true_false', 'numeric', 'show_work', 'open'];

    public function up(): void
    {
        Schema::table('exam_sections', function (Blueprint $table) {
            $table->enum('type', self::TYPES_NEW)->change();
        });
    }

    public function down(): void
    {
        // Written sections cannot survive the narrower enum; their questions go with them (cascade).
        DB::table('exam_sections')->whereIn('type', ['show_work', 'open'])->delete();

        Schema::table('exam_sections', function (Blueprint $table) {
            $table->enum('type', self::TYPES)->change();
        });
    }
};
