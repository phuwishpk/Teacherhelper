<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §32: what went wrong when someone signed in with, linked or
 * connected Google, so an admin can see it per account in Filament. Rows
 * (and the Google e-mail they may hold) are deleted after 30 days by
 * eduvision:purge-images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('flow', 20);
            $table->string('event', 40);
            $table->string('result', 60);
            $table->string('detail', 60)->nullable();
            $table->string('email')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at'], 'idx_google_events_user');
            $table->index('created_at', 'idx_google_events_created');
            $table->index('email', 'idx_google_events_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_events');
    }
};
