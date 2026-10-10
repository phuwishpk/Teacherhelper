<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.4: the eight learning areas of the core curriculum exist from
 * the first migrate (a course cannot be created without one), and a teacher
 * may add their own subject group. owner_user_id NULL = shared by everyone;
 * a user id = visible to that teacher only. `code` stays globally unique:
 * an own group gets a generated code (T<user id>-<n>).
 */
return new class extends Migration
{
    private const STANDARD = [
        'ท' => 'ภาษาไทย',
        'ค' => 'คณิตศาสตร์',
        'ว' => 'วิทยาศาสตร์และเทคโนโลยี',
        'ส' => 'สังคมศึกษา ศาสนา และวัฒนธรรม',
        'พ' => 'สุขศึกษาและพลศึกษา',
        'ศ' => 'ศิลปะ',
        'ง' => 'การงานอาชีพ',
        'ต' => 'ภาษาต่างประเทศ',
    ];

    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('name')->constrained('users')->restrictOnDelete();
        });

        $existing = DB::table('subjects')->pluck('code')->all();
        $now = now();
        foreach (self::STANDARD as $code => $name) {
            if (! in_array($code, $existing, true)) {
                DB::table('subjects')->insert(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite rebuilds `subjects` to drop the key; rows of other tables point at it meanwhile.
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
