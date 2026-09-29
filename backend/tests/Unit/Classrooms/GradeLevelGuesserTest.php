<?php

namespace Tests\Unit\Classrooms;

use App\Domain\Classrooms\GradeLevelGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DESIGN §19.2 / §19.12 GradeLevelGuesserTest: ป./ม., the full words, no match. */
class GradeLevelGuesserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: int|null}>
     */
    public static function cases(): array
    {
        return [
            'ป. with room' => ['คณิตศาสตร์ ป.4/2', null, 4],
            'ป without dot' => ['ภาษาไทย ป 1', null, 1],
            'ม. with room' => ['คณิตศาสตร์ ม.2/3', null, 8],
            'ม. with a space' => ['วิทย์ ม. 6', null, 12],
            'ประถมศึกษาปีที่' => ['ภาษาอังกฤษ ประถมศึกษาปีที่ 5', null, 5],
            'มัธยมศึกษาปีที่ in Thai digits' => ['สังคมศึกษา มัธยมศึกษาปีที่ ๓', null, 9],
            'in the section only' => ['คณิตศาสตร์เพิ่มเติม', 'ม.5/1', 11],
            'the name wins over the section' => ['ป.3', 'ม.1', 3],
            'the ม at the end of a word is not ม.' => ['สังคม 1', null, null],
            'no grade' => ['คณิตศาสตร์', 'ห้อง 2', null],
            'ม.10 is not a grade' => ['ม.10', null, null],
            'ม.ต้น has no number' => ['ชุมนุม ม.ต้น', '', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_guess(string $name, ?string $section, ?int $expected): void
    {
        $this->assertSame($expected, GradeLevelGuesser::guess($name, $section));
    }
}
