<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.4 `scans`: one uploaded page. client_scan_id (UUID from the
 * phone) makes POST /scans idempotent. state: `active` (the page in use),
 * `superseded` (replaced by a later scan of the same page) or
 * `pending_confirm` (a rescan of a published submission waiting for
 * POST /scans/{id}/confirm-replace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->char('client_scan_id', 36)->unique();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('page_no');
            $table->unsignedSmallInteger('layout_version');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            // An explicit default keeps MariaDB (explicit_defaults_for_timestamp=OFF,
            // the default before 10.10) from adding ON UPDATE CURRENT_TIMESTAMP to
            // the first NOT NULL TIMESTAMP, which would overwrite the phone's time
            // on every later update of the row. The app always sends the value.
            $table->timestamp('scanned_at')->useCurrent();
            $table->float('blur_score');
            $table->string('page_image_path')->nullable();
            $table->enum('state', ['active', 'superseded', 'pending_confirm']);
            $table->timestamps();
            $table->index(['submission_id', 'page_no', 'state'], 'idx_scans_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
