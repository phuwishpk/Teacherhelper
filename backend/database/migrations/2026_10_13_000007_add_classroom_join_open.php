<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.13.3 (step 3ข): pupils join a classroom with its class code or
 * the QR of `/app/#/join/<code>`; the owner can close joining.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->boolean('join_open')->default(true)->after('class_code');
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn('join_open');
        });
    }
};
