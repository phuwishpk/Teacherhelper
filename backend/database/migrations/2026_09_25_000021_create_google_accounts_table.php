<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §18.4 `google_accounts`: the Google account a teacher connected
 * (only teachers connect, §18.1). Only the refresh token is kept, encrypted
 * with APP_KEY (Eloquent `encrypted` cast); access tokens live in the cache.
 * last_error is set when the token stopped working (invalid_grant: revoked,
 * or the 7-day limit of an OAuth app in Testing mode) and cleared by a new
 * POST /google/connect. No created_at: connected_at plays that role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('google_sub', 64)->unique();
            $table->string('email');
            $table->text('encrypted_refresh_token');
            $table->text('scopes');
            // Explicit defaults: see scans.scanned_at (MariaDB would otherwise add
            // ON UPDATE CURRENT_TIMESTAMP to the first NOT NULL TIMESTAMP).
            $table->timestamp('connected_at')->useCurrent();
            $table->string('last_error')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
