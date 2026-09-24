<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.1 `student_credentials`: one row per student user. Only the SHA-256
 * of the QR-card token and the bcrypt hash of the PIN are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_credentials', function (Blueprint $table) {
            $table->foreignId('student_id')->primary()->constrained('users')->restrictOnDelete();
            $table->char('qr_token_hash', 64)->unique();
            $table->timestamp('qr_issued_at');
            $table->string('pin_hash');
            $table->unsignedTinyInteger('failed_pin_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_credentials');
    }
};
