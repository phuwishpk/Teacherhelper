<?php

namespace Tests\Feature\Exams;

use App\Models\ExamSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.20.1–§22.20.2: an `app` exam may have written sections (open,
 * show_work). They come last, never on a manual exam or a shared sheet, and
 * their questions start as blank drafts with eight lines and no rubric.
 */
class ExamWrittenSectionTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
    }

    public function test_written_types_are_known_and_choice_types_unchanged(): void
    {
        $this->assertSame(['open', 'show_work'], ExamSection::WRITTEN_TYPES);
        $this->assertSame(['mcq', 'true_false', 'numeric'], ExamSection::TYPES);
        $this->assertTrue((new ExamSection(['type' => 'open']))->isWritten());
        $this->assertTrue(ExamSection::isWrittenType('show_work'));
        $this->assertFalse((new ExamSection(['type' => 'mcq']))->isWritten());
        $this->assertSame(0, (new ExamSection(['type' => 'show_work']))->choiceCount());
        $this->assertSame(8, ExamSection::DEFAULT_ANSWER_LINES);
    }
}
