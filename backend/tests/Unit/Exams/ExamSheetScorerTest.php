<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\ExamSheetScorer;
use App\Domain\Exams\NumericAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DESIGN §22.3, §22.9: the golden fixtures in tests/fixtures/exam_scoring
 * are shared with the app (app/test/fixtures/exam_scoring), so the score on
 * the phone and the score of the server agree on every row of the scoring
 * table, the numeric canonical form and the version rules.
 */
class ExamSheetScorerTest extends TestCase
{
    private const DIR = __DIR__.'/../../fixtures/exam_scoring';

    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $out = [];
        foreach (['rows.json', 'digits.json'] as $file) {
            $data = json_decode((string) file_get_contents(self::DIR.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['cases'] as $case) {
                $out["{$file}: {$case['name']}"] = [$case];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function test_golden_case(array $case): void
    {
        $version = ExamSheetScorer::version($case['version_count'], $case['page'], $case['version_fill'], $case['page_one_version']);
        $expected = $case['expected'];
        $this->assertSame($expected['version_no'], $version['version_no']);
        $this->assertSame($expected['version_source'], $version['source']);
        $this->assertSame($expected['version_doubtful'], $version['doubtful']);
        if ($version['version_no'] === null) {
            return;
        }

        $key = [];
        foreach ($case['keys'][(string) $version['version_no']] as $item) {
            $key[$item['sheet_no']] = $item;
        }
        $result = ExamSheetScorer::scorePage($key, $case['rows'], $case['digits']);

        $this->assertEqualsWithDelta((float) $expected['score'], $result['score'], 1e-9);
        $this->assertEqualsWithDelta((float) $expected['max_score'], $result['max_score'], 1e-9);
        $this->assertSame(
            array_map(fn (array $i) => [$i['sheet_no'], $i['selected'], $i['value'], (float) $i['score'], $i['doubts']], $expected['items']),
            array_map(fn (array $i) => [$i['sheet_no'], $i['selected'], $i['value'], $i['score'], $i['doubts']], $result['items']),
        );
    }

    public function test_canonical_forms_match_the_shared_fixture(): void
    {
        $data = json_decode((string) file_get_contents(self::DIR.'/canonical.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['canonical'] as [$input, $canonical]) {
            $this->assertSame($canonical, NumericAnswer::canonical($input), "canonical({$input})");
        }
    }

    public function test_the_app_copy_of_the_fixtures_is_identical(): void
    {
        $app = __DIR__.'/../../../../app/test/fixtures/exam_scoring';
        if (! is_dir($app)) {
            $this->markTestSkipped('app/ is not next to backend/ in this checkout');
        }
        foreach (['rows.json', 'digits.json', 'canonical.json'] as $file) {
            $this->assertFileEquals(self::DIR.'/'.$file, $app.'/'.$file, "app/test/fixtures/exam_scoring/{$file} differs from the backend copy");
        }
    }
}
