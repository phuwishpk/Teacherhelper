<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.2 / §19.8: classroom_students.pin_pending_at marks a student the
 * background roster sync (SyncClassroomRosterJob) added: their first PIN was
 * never shown to anyone. The app lists them as "ยังไม่ได้รับ PIN" until the
 * teacher issues their PINs (POST /classrooms/{id}/students/pending-pins or a
 * single PIN reset).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_students', function (Blueprint $table) {
            $table->timestamp('pin_pending_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classroom_students', function (Blueprint $table) {
            $table->dropColumn('pin_pending_at');
        });
    }
};
