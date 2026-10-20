<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §37.6 (assistant step 2): an assistant message may carry a plan
 * card (questions to create) and its state: proposed, applied, or
 * replaced by a newer card of the same conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->json('plan')->nullable()->after('content');
            $table->string('plan_status', 10)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn(['plan', 'plan_status']);
        });
    }
};
