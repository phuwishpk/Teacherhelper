<?php

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\AccountPurge;
use App\Domain\Classrooms\ClassroomLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DESIGN §31.3: a new table cannot slip past the purge. Every foreign key to
 * users is in AccountPurge::USER_COLUMNS, and every RESTRICT / NO ACTION key
 * into the rows a classroom purge removes is deleted explicitly
 * (ClassroomLifecycle::PURGED_EXPLICITLY). Reads the real schema, so the
 * suite checks SQLite and MariaDB alike.
 */
class AccountPurgeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{table: string, column: string, foreign_table: string, on_delete: string}> */
    private function foreignKeys(): array
    {
        $keys = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                foreach ($fk['columns'] as $column) {
                    $keys[] = ['table' => $table, 'column' => $column, 'foreign_table' => $fk['foreign_table'], 'on_delete' => strtolower((string) $fk['on_delete'])];
                }
            }
        }

        return $keys;
    }

    public function test_every_foreign_key_to_users_has_a_purge_rule(): void
    {
        $columns = [];
        foreach ($this->foreignKeys() as $fk) {
            if ($fk['foreign_table'] === 'users') {
                $columns[] = $fk['table'].'.'.$fk['column'];
            }
        }
        sort($columns);

        $this->assertContains('submissions.student_id', $columns, 'the schema was read');
        $missing = array_values(array_diff($columns, array_keys(AccountPurge::USER_COLUMNS)));
        $this->assertSame([], $missing, 'add these columns to AccountPurge::USER_COLUMNS (and handle them in the purge)');
        $stale = array_values(array_diff(array_keys(AccountPurge::USER_COLUMNS), $columns));
        $this->assertSame([], $stale, 'AccountPurge::USER_COLUMNS names columns that are not foreign keys to users');
    }

    public function test_nothing_restricts_the_purge_of_a_classroom(): void
    {
        $keys = $this->foreignKeys();
        $explicit = ClassroomLifecycle::PURGED_EXPLICITLY;

        // The tables a classroom purge empties: classrooms, then whatever
        // cascades from them or is deleted explicitly, until nothing changes.
        $tree = ['classrooms' => true];
        do {
            $grew = false;
            foreach ($keys as $fk) {
                $name = $fk['table'].'.'.$fk['column'];
                if (isset($tree[$fk['foreign_table']]) && ! isset($tree[$fk['table']])
                    && ($fk['on_delete'] === 'cascade' || in_array($name, $explicit, true))) {
                    $tree[$fk['table']] = true;
                    $grew = true;
                }
            }
        } while ($grew);
        $this->assertArrayHasKey('responses', $tree, 'the tree reaches the answers');

        $blocking = [];
        foreach ($keys as $fk) {
            $name = $fk['table'].'.'.$fk['column'];
            if (isset($tree[$fk['foreign_table']])
                && ! in_array($fk['on_delete'], ['cascade', 'set null'], true)
                && ! in_array($name, $explicit, true)) {
                $blocking[] = "{$name} -> {$fk['foreign_table']} ({$fk['on_delete']})";
            }
        }
        sort($blocking);
        $this->assertSame([], $blocking, 'delete these in ClassroomLifecycle::purge and list them in PURGED_EXPLICITLY');

        $unused = [];
        foreach ($explicit as $name) {
            $found = false;
            foreach ($keys as $fk) {
                $found = $found || $name === $fk['table'].'.'.$fk['column'];
            }
            if (! $found) {
                $unused[] = $name;
            }
        }
        $this->assertSame([], $unused, 'PURGED_EXPLICITLY names columns that are not foreign keys');
    }
}
