<?php

namespace App\Domain\Qa;

/**
 * The System Usability Scale as the literature defines it (DESIGN §39.7).
 *
 * - Items: Brooke (1996) "SUS: A 'quick and dirty' usability scale", ten
 *   statements alternating positive (odd) and negative (even), each answered
 *   1 (strongly disagree) to 5 (strongly agree). The English originals are
 *   shown with the team's Thai translation, which is not a published,
 *   validated Thai version.
 * - Administration (Brooke): right after using the system and before any
 *   debriefing; answer every item quickly; an item a respondent cannot
 *   answer is marked at the centre (3).
 * - Score: odd items (answer − 1), even items (5 − answer), sum × 2.5,
 *   0–100. It is not a percentage.
 * - Interpretation: the mean of about 500 studies is 68 (Sauro, 2011); the
 *   curved grading scale of Sauro & Lewis (2016); the acceptability ranges
 *   of Bangor, Kortum & Miller (2008); the adjective rating item and its
 *   mean SUS scores of Bangor, Kortum & Miller (2009). A mean is reported
 *   with its 95% confidence interval (t distribution), as Sauro & Lewis
 *   recommend for small samples.
 */
final class QaSus
{
    public const BENCHMARK = 68;

    /** @var list<array{en: string, th: string}> */
    public const ITEMS = [
        ['en' => 'I think that I would like to use this system frequently.', 'th' => 'ฉันคิดว่าฉันอยากใช้ระบบนี้บ่อย ๆ'],
        ['en' => 'I found the system unnecessarily complex.', 'th' => 'ฉันพบว่าระบบนี้ซับซ้อนเกินความจำเป็น'],
        ['en' => 'I thought the system was easy to use.', 'th' => 'ฉันคิดว่าระบบนี้ใช้งานง่าย'],
        ['en' => 'I think that I would need the support of a technical person to be able to use this system.', 'th' => 'ฉันคิดว่าฉันต้องมีผู้ที่มีความรู้ทางเทคนิคคอยช่วย จึงจะใช้ระบบนี้ได้'],
        ['en' => 'I found the various functions in this system were well integrated.', 'th' => 'ฉันพบว่าฟังก์ชันต่าง ๆ ในระบบนี้ทำงานเข้ากันได้ดี'],
        ['en' => 'I thought there was too much inconsistency in this system.', 'th' => 'ฉันคิดว่าระบบนี้มีความไม่สอดคล้องกันมากเกินไป'],
        ['en' => 'I would imagine that most people would learn to use this system very quickly.', 'th' => 'ฉันคาดว่าคนส่วนใหญ่จะเรียนรู้การใช้ระบบนี้ได้อย่างรวดเร็วมาก'],
        ['en' => 'I found the system very cumbersome to use.', 'th' => 'ฉันพบว่าระบบนี้ใช้งานยุ่งยากมาก'],
        ['en' => 'I felt very confident using the system.', 'th' => 'ฉันรู้สึกมั่นใจมากเมื่อใช้ระบบนี้'],
        ['en' => 'I needed to learn a lot of things before I could get going with this system.', 'th' => 'ฉันต้องเรียนรู้หลายอย่างก่อนจึงจะเริ่มใช้ระบบนี้ได้'],
    ];

    /** Likert anchors, 1..5. */
    public const SCALE = [
        1 => 'ไม่เห็นด้วยอย่างยิ่ง (Strongly disagree)',
        5 => 'เห็นด้วยอย่างยิ่ง (Strongly agree)',
    ];

    /**
     * Bangor et al. (2009): "Overall, I would rate the user-friendliness of
     * this product as:" 1..7, with the mean SUS score each answer had.
     *
     * @var array<int, array{en: string, th: string, mean: float}>
     */
    public const ADJECTIVES = [
        1 => ['en' => 'Worst imaginable', 'th' => 'แย่ที่สุดเท่าที่นึกได้', 'mean' => 12.5],
        2 => ['en' => 'Awful', 'th' => 'แย่มาก', 'mean' => 20.3],
        3 => ['en' => 'Poor', 'th' => 'แย่', 'mean' => 35.7],
        4 => ['en' => 'OK', 'th' => 'พอใช้', 'mean' => 50.9],
        5 => ['en' => 'Good', 'th' => 'ดี', 'mean' => 71.4],
        6 => ['en' => 'Excellent', 'th' => 'ดีมาก', 'mean' => 85.5],
        7 => ['en' => 'Best imaginable', 'th' => 'ดีที่สุดเท่าที่นึกได้', 'mean' => 90.9],
    ];

    /** Sauro & Lewis (2016) curved grading scale: lowest score of each grade. */
    public const GRADES = [
        'A+' => 84.1, 'A' => 80.8, 'A-' => 78.9,
        'B+' => 77.2, 'B' => 74.1, 'B-' => 72.6,
        'C+' => 71.1, 'C' => 65.0, 'C-' => 62.7,
        'D' => 51.7, 'F' => 0.0,
    ];

    public const ROLES = ['teacher' => 'แอปฝั่งครู', 'student' => 'แอปฝั่งนักเรียน'];

    public const PLATFORMS = ['web' => 'เว็บ', 'android' => 'Android', 'ios' => 'iOS'];

    public const EXPERIENCE = ['first' => 'ใช้ครั้งแรกในการทดสอบนี้', 'some' => 'เคยใช้มาบ้าง', 'regular' => 'ใช้เป็นประจำ'];

    /** 0–100, or null unless there are exactly ten answers of 1–5. */
    public static function score(array $answers): ?float
    {
        if (count($answers) !== 10) {
            return null;
        }
        $sum = 0;
        foreach (array_values($answers) as $i => $a) {
            if (! is_int($a) || $a < 1 || $a > 5) {
                return null;
            }
            $sum += $i % 2 === 0 ? $a - 1 : 5 - $a;
        }

        return $sum * 2.5;
    }

    /** One item's contribution 0–4 (odd: answer − 1, even: 5 − answer); $index from 0. */
    public static function contribution(int $index, int $answer): int
    {
        return $index % 2 === 0 ? $answer - 1 : 5 - $answer;
    }

    public static function grade(float $score): string
    {
        foreach (self::GRADES as $grade => $min) {
            if ($score >= $min) {
                return $grade;
            }
        }

        return 'F';
    }

    /** Bangor et al. (2008): below 50 not acceptable, 50 to below 70 marginal, 70 and above acceptable. */
    public static function acceptability(float $score): string
    {
        return match (true) {
            $score >= 70 => 'ยอมรับได้ (Acceptable)',
            $score >= 50 => 'ก้ำกึ่ง (Marginal)',
            default => 'ยอมรับไม่ได้ (Not acceptable)',
        };
    }

    /** The adjective whose mean SUS (Bangor et al., 2009) is nearest to the score. */
    public static function adjectiveFor(float $score): string
    {
        $best = null;
        foreach (self::ADJECTIVES as $adj) {
            if ($best === null || abs($adj['mean'] - $score) < abs($best['mean'] - $score)) {
                $best = $adj;
            }
        }

        return $best['th'].' ('.$best['en'].')';
    }

    /**
     * @param  list<float>  $scores
     * @return array{n: int, mean: float|null, sd: float|null, median: float|null, min: float|null, max: float|null, ci_low: float|null, ci_high: float|null}
     */
    public static function stats(array $scores): array
    {
        $n = count($scores);
        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'sd' => null, 'median' => null, 'min' => null, 'max' => null, 'ci_low' => null, 'ci_high' => null];
        }
        sort($scores);
        $mean = array_sum($scores) / $n;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn ($s) => ($s - $mean) ** 2, $scores)) / ($n - 1)) : null;
        $median = $n % 2 === 1 ? $scores[intdiv($n, 2)] : ($scores[$n / 2 - 1] + $scores[$n / 2]) / 2;
        $half = $sd === null ? null : self::tCritical($n - 1) * $sd / sqrt($n);

        return [
            'n' => $n,
            'mean' => round($mean, 1),
            'sd' => $sd === null ? null : round($sd, 1),
            'median' => round($median, 1),
            'min' => $scores[0],
            'max' => $scores[$n - 1],
            'ci_low' => $half === null ? null : round(max(0, $mean - $half), 1),
            'ci_high' => $half === null ? null : round(min(100, $mean + $half), 1),
        ];
    }

    /** Two-sided 95% critical value of Student's t for $df degrees of freedom. */
    public static function tCritical(int $df): float
    {
        $table = [1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571, 6 => 2.447, 7 => 2.365, 8 => 2.306, 9 => 2.262, 10 => 2.228,
            11 => 2.201, 12 => 2.179, 13 => 2.160, 14 => 2.145, 15 => 2.131, 16 => 2.120, 17 => 2.110, 18 => 2.101, 19 => 2.093, 20 => 2.086,
            21 => 2.080, 22 => 2.074, 23 => 2.069, 24 => 2.064, 25 => 2.060, 26 => 2.056, 27 => 2.052, 28 => 2.048, 29 => 2.045, 30 => 2.042];

        return match (true) {
            $df < 1 => 0.0,
            $df <= 30 => $table[$df],
            $df <= 40 => 2.021,
            $df <= 60 => 2.000,
            $df <= 120 => 1.980,
            default => 1.960,
        };
    }
}
