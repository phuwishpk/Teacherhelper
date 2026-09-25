<?php

namespace Tests\Unit\Gemini;

use App\Domain\Gemini\ExtractionValidator;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\ResponseSchemas;
use App\Domain\Gemini\SchemaValidator;
use PHPUnit\Framework\TestCase;

/** DESIGN §10.3 semantic checks after the schema, and the fake's answers fitting the schemas. */
class ExtractionValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function open(array $ids): array
    {
        return [
            'blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear', 'transcription' => 'x',
            'criteria' => array_map(fn (int $id) => ['criterion_id' => $id, 'level' => 'met'], $ids),
            'error_types' => ['concept', 'concept'],
        ];
    }

    public function test_open_needs_every_criterion_exactly_once(): void
    {
        $normalized = ExtractionValidator::normalize('open', self::open([3, 1, 2]), 3);
        $this->assertSame([1, 2, 3], array_column($normalized['criteria'], 'criterion_id'));
        $this->assertSame(['concept'], $normalized['error_types'], 'duplicates removed');

        foreach ([[1, 2], [1, 2, 3, 4], [1, 1, 2], [0, 1, 2]] as $ids) {
            try {
                ExtractionValidator::normalize('open', self::open($ids), 3);
                $this->fail('expected invalid_output for '.json_encode($ids));
            } catch (GeminiException $e) {
                $this->assertSame(GeminiException::INVALID_OUTPUT, $e->status);
            }
        }
    }

    public function test_a_blank_answer_is_tagged_no_answer_and_long_text_is_invalid(): void
    {
        $blank = ['blank' => true, 'suspicious_instruction' => false, 'legibility' => 'clear', 'answer_text' => '', 'key_match' => 'missing', 'error_types' => []];
        $this->assertSame(['no_answer'], ExtractionValidator::normalize('short', $blank)['error_types']);

        $this->expectException(GeminiException::class);
        ExtractionValidator::normalize('short', ['answer_text' => str_repeat('ก', 4001)] + $blank);
    }

    public function test_the_fake_answers_every_outcome_within_the_schemas(): void
    {
        $fake = new FakeGeminiClient;
        $hints = [
            'short' => ['accepted' => ['กรุงเทพฯ'], 'numeric' => null],
            'show_work' => ['accepted_final' => ['x = 5'], 'numeric' => ['value' => 5, 'abs_tol' => 0], 'reference_steps' => ['a', 'b', 'c'], 'answer_lines' => 4],
            'open' => ['criteria' => [['criterion_id' => 1, 'is_core' => true], ['criterion_id' => 2, 'is_core' => false]]],
        ];
        foreach ($hints as $type => $hint) {
            foreach (['[fake:correct]', '[fake:partial]', '[fake:wrong]', '[fake:blank]', '[fake:suspicious]', '[fake:hard]', ''] as $marker) {
                $request = new GeminiRequest('extract', $type, 'v1', 'sys', 'user', [new GeminiImage('img'.$marker)], ResponseSchemas::get('extract', $type), 0.0, $hint + ['question_text' => 'q '.$marker]);
                $reply = $fake->generate([$request], 'any-key-000000000000000')[0];
                $data = json_decode((string) $reply->text, true);
                $this->assertSame([], SchemaValidator::validate(ResponseSchemas::get('extract', $type), $data), "{$type} {$marker}");
                ExtractionValidator::normalize($type, $data, 2);
            }
        }

        // Same image, same answer: the default outcome is a hash of the image.
        $request = new GeminiRequest('extract', 'short', 'v1', 's', 'u', [new GeminiImage('same')], null, 0.0, $hints['short']);
        $this->assertSame($fake->generate([$request], 'k')[0]->text, $fake->generate([$request], 'k')[0]->text);
    }
}
