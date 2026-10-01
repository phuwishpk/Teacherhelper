<?php

namespace Tests\Feature\Api;

use App\Models\DocumentExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /document-extractions/{id}: course and lesson-plan reads are shared
 * in the school (§19.9); a read of any other purpose is answered only to
 * the teacher who asked for it.
 */
class DocumentExtractionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_course_and_lesson_plan_reads_are_school_wide(): void
    {
        $teacher = $this->makeTeacher();
        $colleague = $this->makeTeacher($teacher->school);
        $read = fn (string $purpose) => DocumentExtraction::create([
            'school_id' => $teacher->school_id, 'input_hash' => hash('sha256', $purpose), 'purpose' => $purpose,
            'status' => DocumentExtraction::STATUS_DONE, 'result' => [], 'requested_by' => $teacher->id,
        ]);

        foreach (['course', 'lesson_plan'] as $purpose) {
            $this->asUser($colleague)->getJson('/api/v1/document-extractions/'.$read($purpose)->id)->assertOk();
        }
        $coursework = $read('coursework');
        $this->asUser($colleague)->getJson("/api/v1/document-extractions/{$coursework->id}")->assertNotFound();
        $this->asUser($teacher)->getJson("/api/v1/document-extractions/{$coursework->id}")->assertOk();
    }
}
