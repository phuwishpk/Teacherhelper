<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §37.10 (assistant step 6): the teacher's files attached to a
 * question ([{id, name, mime_type, page_count}], source_documents of that
 * teacher). The files themselves stay in source_documents (30 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
