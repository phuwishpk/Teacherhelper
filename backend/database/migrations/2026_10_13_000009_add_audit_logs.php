<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.14 (step 4): an append-only record of what an admin did on
 * behalf of a user, starting with "เข้าใช้แทน" (impersonation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');
            $table->index(['actor_id', 'created_at'], 'idx_audit_actor');
            $table->index(['target_user_id', 'created_at'], 'idx_audit_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
