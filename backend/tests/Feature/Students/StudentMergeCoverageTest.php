<?php

namespace Tests\Feature\Students;

use App\Domain\Students\StudentMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DESIGN §24.5 / §24.16: every foreign key to users.id in the schema is in
 * StudentMerger::COLUMNS (moved, deleted, or unrelated staff columns), so a
 * new table with a student column cannot be forgotten by the merge.
 */
class StudentMergeCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_foreign_key_to_users_is_handled_by_the_merge(): void
    {
        $columns = [];
        // Schema::getForeignKeys reads PRAGMA foreign_key_list on SQLite and
        // information_schema on MariaDB, so the suite checks both engines.
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if ($fk['foreign_table'] === 'users') {
                    foreach ($fk['columns'] as $column) {
                        $columns[] = $table.'.'.$column;
                    }
                }
            }
        }
        sort($columns);

        $this->assertContains('submissions.student_id', $columns, 'the schema was read');
        $missing = array_values(array_diff($columns, array_keys(StudentMerger::COLUMNS)));
        $this->assertSame([], $missing, 'add these columns to StudentMerger::COLUMNS (move, delete or unrelated)');
        $stale = array_values(array_diff(array_keys(StudentMerger::COLUMNS), $columns));
        $this->assertSame([], $stale, 'StudentMerger::COLUMNS names columns that are not foreign keys to users');
    }
}
