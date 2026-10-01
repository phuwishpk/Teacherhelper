<?php

namespace Tests\Feature\Database;

use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Schema details the feature tests would not notice.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredTimestamps(): array
    {
        return [
            'scans.scanned_at' => ['scans', 'scanned_at'],
            'student_credentials.qr_issued_at' => ['student_credentials', 'qr_issued_at'],
            'device_tokens.last_seen_at' => ['device_tokens', 'last_seen_at'],
            'google_accounts.connected_at' => ['google_accounts', 'connected_at'],
            'google_accounts.updated_at' => ['google_accounts', 'updated_at'],
            'classroom_google_links.linked_at' => ['classroom_google_links', 'linked_at'],
            'classroom_google_ignored_users.created_at' => ['classroom_google_ignored_users', 'created_at'],
            'assignment_google_links.posted_at' => ['assignment_google_links', 'posted_at'],
            'classroom_submission_imports.created_at' => ['classroom_submission_imports', 'created_at'],
            'classroom_submission_imports.updated_at' => ['classroom_submission_imports', 'updated_at'],
            'skill_observations.observed_at' => ['skill_observations', 'observed_at'],
            'mastery.updated_at' => ['mastery', 'updated_at'],
            'practice_attempts.created_at' => ['practice_attempts', 'created_at'],
            'gradebook_publications.published_at' => ['gradebook_publications', 'published_at'],
            'student_merges.created_at' => ['student_merges', 'created_at'],
        ];
    }

    /**
     * A NOT NULL TIMESTAMP without an explicit default gets
     * ON UPDATE CURRENT_TIMESTAMP on MariaDB with
     * explicit_defaults_for_timestamp=OFF (the default before 10.10), and
     * every later update of the row would then overwrite the stored time.
     */
    #[DataProvider('requiredTimestamps')]
    public function test_required_timestamps_declare_an_explicit_default(string $table, string $column): void
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        $this->assertNotNull($definition);
        $this->assertFalse($definition['nullable']);
        $this->assertMatchesRegularExpression('/current_timestamp/i', (string) $definition['default']);
    }

    public function test_ai_calls_keep_their_row_when_the_question_is_deleted(): void
    {
        $teacher = $this->makeTeacher();
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($teacher))->create();
        $drafted = Question::factory()->create(['assignment_id' => $assignment->id]);
        $other = Question::factory()->create(['assignment_id' => $assignment->id]);
        $calls = [$this->rubricDraftCall($drafted), $this->rubricDraftCall($other)];

        $this->asUser($teacher)->deleteJson("/api/v1/questions/{$drafted->id}")->assertNoContent();
        $this->asUser($teacher)->deleteJson("/api/v1/assignments/{$assignment->id}")->assertNoContent();

        foreach ($calls as $call) {
            $call->refresh();
            $this->assertNull($call->question_id);
            $this->assertSame('rubric_draft', $call->purpose);
        }
    }

    private function rubricDraftCall(Question $question): AiCall
    {
        return AiCall::create([
            'purpose' => 'rubric_draft',
            'question_id' => $question->id,
            'model' => 'gemini-test',
            'prompt_version' => 'rubric-v1',
            'key_source' => 'teacher',
            'status' => 'ok',
        ]);
    }
}
