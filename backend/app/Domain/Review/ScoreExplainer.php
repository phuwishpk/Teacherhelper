<?php

namespace App\Domain\Review;

use App\Domain\Grading\ReviewPriority;
use App\Domain\Grading\ScoreRounding;
use App\Models\Question;
use App\Models\Response;

/**
 * "ทำไมได้คะแนนนี้" (DESIGN §11.1, §13): Thai sentences for the review screen
 * built from responses.fuzzy_trace, the stored trace of fuzzy systems 1 and 2
 * (ResponseGrader / McqGrader / ScanGrader shapes). The raw trace is sent
 * next to it; this is only its reading. Unknown shapes give fewer lines,
 * never an error.
 */
final class ScoreExplainer
{
    /** Thai reading of each rule of rules/*.php (DESIGN §11.3–§11.5, §11.8). */
    public const RULES = [
        'R1' => 'คำตอบสุดท้ายถูก และขั้นตอนถูกเกือบทั้งหมด',
        'R2' => 'คำตอบสุดท้ายถูก ขั้นตอนถูกบางส่วน',
        'R3' => 'คำตอบสุดท้ายถูก แต่ขั้นตอนผิดเกือบทั้งหมด (อาจเดาหรือลอกมา)',
        'R4' => 'วิธีทำถูกเกือบทั้งหมด แต่คำตอบสุดท้ายผิด (พลาดตอนท้าย)',
        'R5' => 'คำตอบสุดท้ายผิด ขั้นตอนถูกบางส่วน',
        'R6' => 'คำตอบสุดท้ายผิด และขั้นตอนผิดเกือบทั้งหมด',
        'S1' => 'คำตอบไม่ตรงกับเฉลย',
        'S2' => 'คำตอบใกล้เคียงเฉลย',
        'S3' => 'คำตอบตรงกับเฉลย',
        'O1' => 'ได้แก่นของคำตอบ และเกณฑ์อื่นเกือบครบ',
        'O2' => 'ได้แก่นของคำตอบ และเกณฑ์อื่นบางส่วน',
        'O3' => 'ได้แก่นของคำตอบ แต่รายละเอียดน้อย',
        'O4' => 'รายละเอียดครบ แต่ขาดแก่นของคำตอบ',
        'O5' => 'ขาดแก่นของคำตอบ เกณฑ์อื่นได้บางส่วน',
        'O6' => 'ขาดแก่นของคำตอบ และเกณฑ์อื่นได้น้อย',
        'P1' => 'ผู้อ่านสองฝ่ายอ่านไม่ตรงกัน',
        'P2' => 'ลายมืออ่านยาก',
        'P3' => 'ระดับความเข้าใจอยู่ใกล้เส้นแบ่ง',
        'P4' => 'ไม่มีสัญญาณที่น่าสงสัย',
    ];

    /** Why an answer is `manual` (fuzzy_trace.manual_reason). */
    public const MANUAL_REASONS = [
        'ai_key_missing' => 'ยังไม่ได้ใส่ Gemini API key จึงยังไม่ได้ให้ AI ตรวจ',
        'ai_key_invalid' => 'Gemini API key ใช้ไม่ได้ (Google ปฏิเสธ key)',
        'ai_error' => 'AI ตรวจไม่สำเร็จหลังลองครบ 3 ครั้ง',
        'invalid_output' => 'AI ตอบผิดรูปแบบซ้ำหลายครั้ง',
        'crop_missing' => 'ไม่พบภาพคำตอบของข้อนี้',
        'rubric_missing' => 'ข้อนี้ยังไม่มี rubric ที่อนุมัติแล้ว',
        'not_gradable' => 'ข้อประเภทนี้ AI ไม่ตรวจ',
        'fuzzy_degenerate' => 'กฎการให้คะแนนไม่ทำงานกับข้อมูลที่อ่านได้',
        'answer_key_missing' => 'ข้อนี้ไม่มีเฉลยที่ใช้ตรวจได้',
        'layout_type_mismatch' => 'ประเภทของข้อเปลี่ยนหลังพิมพ์ใบงาน',
        'answer_not_found' => 'หาคำตอบข้อนี้ในภาพไม่เจอ',
        'page_file_missing' => 'ไม่พบไฟล์งานที่ส่งของข้อนี้',
    ];

    private const MATCH = [
        'exact' => 'ตรงกับเฉลย',
        'equivalent' => 'เทียบเท่าเฉลย',
        'partial' => 'ถูกบางส่วน',
        'different' => 'ไม่ตรงกับเฉลย',
        'missing' => 'ไม่มีคำตอบ',
    ];

    private const LEVELS = ['met' => 'ผ่าน', 'partially_met' => 'ผ่านบางส่วน', 'not_met' => 'ไม่ผ่าน'];

    private const UNDERSTANDING = ['good' => 'เข้าใจดี', 'partial' => 'เข้าใจบางส่วน', 'not_yet' => 'ยังไม่เข้าใจ'];

    private const BANDS = [
        ReviewPriority::BAND_CHECK => 'ต้องตรวจ',
        ReviewPriority::BAND_LOOK => 'ควรดู',
        ReviewPriority::BAND_CONFIDENT => 'มั่นใจ',
    ];

    private const STRICTNESS = ['lenient' => 'ผ่อนปรน', 'normal' => 'ปกติ', 'strict' => 'เข้มงวด'];

    /**
     * @return list<string>
     */
    public static function why(Response $response): array
    {
        $trace = $response->fuzzy_trace ?? [];
        $question = $response->question;
        $lines = [];

        if (in_array($response->grading_state, Response::IN_PROGRESS_STATES, true)) {
            return ['ข้อนี้ยังอยู่ในคิวให้ AI ตรวจ'];
        }

        if ($response->grading_state === Response::STATE_MANUAL) {
            $reason = $response->manualReason();
            $lines[] = 'ครูต้องตรวจข้อนี้เอง: '.(self::MANUAL_REASONS[$reason] ?? 'ระบบให้คะแนนอัตโนมัติไม่ได้');
        }

        if (ReviewFlags::suspicious($response)) {
            $lines[] = 'พบข้อความในคำตอบที่อาจพยายามสั่ง AI (น่าสงสัย) ครูต้องดูภาพและให้คะแนนเอง';
        }
        if (ReviewFlags::identityMismatch($response)) {
            $lines[] = 'QR บนใบงานเป็นของนักเรียนคนอื่น ไม่ตรงกับคนที่ส่งงานใน Google Classroom';
        }

        if (($trace['whole_page']['page_conflict'] ?? false) === true) {
            $lines[] = 'พบคำตอบข้อนี้มากกว่าหนึ่งหน้าและเขียนไม่ตรงกัน ครูควรดูภาพทุกหน้า';
        }

        if (($trace['system'] ?? null) === 'mcq') {
            array_push($lines, ...self::mcq($trace));
        } elseif ($response->grading_state === Response::STATE_SCORED) {
            array_push($lines, ...self::fuzzy($trace, $question));
        }

        if ($response->explanationError() !== null) {
            $lines[] = 'AI เขียนคำอธิบายไม่สำเร็จ ให้ AI เขียนใหม่หรือครูเขียนเอง';
        }

        if ($response->final_score !== null && $response->ai_score !== null && abs($response->final_score - $response->ai_score) > 0.001) {
            $lines[] = 'ครูปรับคะแนนจาก '.self::num($response->ai_score).' เป็น '.self::num($response->final_score);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return list<string>
     */
    private static function fuzzy(array $trace, ?Question $question): array
    {
        $lines = [];
        $system = $trace['system'] ?? $question?->type;
        $inputs = (array) ($trace['inputs'] ?? []);
        $signals = (array) ($trace['signals'] ?? []);

        if (($trace['blank'] ?? false) === true) {
            $lines[] = 'AI อ่านแล้วไม่พบคำตอบ จึงได้ 0 คะแนนโดยไม่ผ่านกฎ fuzzy';
        } else {
            if ($system === Question::TYPE_SHOW_WORK) {
                if (isset($signals['final_answer_match'])) {
                    $lines[] = 'คำตอบสุดท้าย: '.(self::MATCH[$signals['final_answer_match']] ?? $signals['final_answer_match']).self::input($inputs, 'F');
                }
                if (isset($signals['valid_steps'], $signals['steps'])) {
                    $lines[] = "ขั้นตอนที่ถูกต้อง {$signals['valid_steps']} จาก {$signals['steps']} ขั้น".self::input($inputs, 'S');
                }
            } elseif ($system === Question::TYPE_SHORT) {
                if (isset($signals['key_match'])) {
                    $lines[] = 'เทียบกับเฉลย: '.(self::MATCH[$signals['key_match']] ?? $signals['key_match']).self::input($inputs, 'M');
                }
            } elseif ($system === Question::TYPE_OPEN) {
                foreach ((array) ($signals['criteria'] ?? []) as $i => $criterion) {
                    $core = ($criterion['is_core'] ?? false) ? ' (แก่นของคำตอบ)' : '';
                    $level = self::LEVELS[$criterion['level'] ?? ''] ?? (string) ($criterion['level'] ?? '');
                    $lines[] = 'เกณฑ์ข้อ '.($i + 1)."{$core}: {$level}";
                }
                if (isset($inputs['K'], $inputs['R'])) {
                    $lines[] = 'แก่นของคำตอบ K = '.self::num($inputs['K']).' เกณฑ์อื่น R = '.self::num($inputs['R']);
                }
            }

            foreach (self::fired((array) ($trace['score']['rules'] ?? [])) as $rule) {
                $lines[] = "กฎ {$rule['name']} (น้ำหนัก ".self::num($rule['weight']).'): '.(self::RULES[$rule['name']] ?? ($rule['note'] ?? ''));
            }
        }

        if (isset($trace['score_ratio'], $trace['max_points'])) {
            $score = $trace['ai_score'] ?? ScoreRounding::score((float) $trace['score_ratio'], (float) $trace['max_points']);
            $lines[] = 'สัดส่วนคะแนน '.self::num($trace['score_ratio']).' ของ '.self::num($trace['max_points']).' คะแนนเต็ม ได้ '.self::num($score).' คะแนน (ปัดทีละ 0.5)';
        }
        if (isset($trace['u'])) {
            $lines[] = 'ความเข้าใจ u = '.self::num($trace['u']).': '.(self::UNDERSTANDING[$trace['understanding'] ?? ''] ?? '');
        }
        if (isset($trace['strictness'])) {
            $lines[] = 'ความเข้มของการตรวจ: '.(self::STRICTNESS[$trace['strictness']] ?? $trace['strictness']);
        }

        array_push($lines, ...self::priority((array) ($trace['priority'] ?? [])));

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return list<string>
     */
    private static function mcq(array $trace): array
    {
        $filled = array_values((array) ($trace['filled'] ?? []));
        $correct = (string) ($trace['correct'] ?? '');
        // Read by Gemini from a whole page (§19.4): marked in any way, not bubbled.
        $verb = ($trace['read_by'] ?? null) === 'gemini_page' ? 'เลือก' : 'ฝน';
        $lines = [match (true) {
            $filled === [] => "ไม่ได้{$verb}ตัวเลือกใด เฉลยคือ ".$correct,
            count($filled) > 1 => "{$verb}มากกว่าหนึ่งตัวเลือก (".implode(', ', $filled).') นับเป็นผิด เฉลยคือ '.$correct,
            $filled[0] === $correct => "{$verb}ตัวเลือก {$filled[0]} ตรงกับเฉลย",
            default => "{$verb}ตัวเลือก {$filled[0]} แต่เฉลยคือ {$correct}",
        }];
        $ambiguous = array_values((array) ($trace['ambiguous'] ?? []));
        if ($ambiguous !== []) {
            $lines[] = 'ตัวเลือก '.implode(', ', $ambiguous).' ฝนไม่ชัด ควรดูภาพ';
        }
        array_push($lines, ...self::priority((array) ($trace['review_priority'] ?? [])));

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $priority  PriorityResult::toArray() (+ signals)
     * @return list<string>
     */
    private static function priority(array $priority): array
    {
        if (! isset($priority['p'], $priority['band'])) {
            return [];
        }
        $reasons = [];
        foreach (self::fired((array) ($priority['trace']['rules'] ?? [])) as $rule) {
            if ($rule['name'] !== 'P4') {
                $reasons[] = self::RULES[$rule['name']] ?? $rule['name'];
            }
        }
        $line = 'ลำดับการตรวจ p = '.self::num($priority['p']).' ('.(self::BANDS[$priority['band']] ?? $priority['band']).')';
        $lines = [$reasons === [] ? $line : $line.': '.implode(', ', $reasons)];

        $signals = (array) ($priority['signals'] ?? []);
        if (($signals['numeric_box'] ?? false) === true) {
            $cnn = $signals['cnn_text'] ?? null;
            $gemini = (string) ($signals['gemini_text'] ?? '');
            $lines[] = $cnn === null
                ? 'ตัวอ่านตัวเลขบนมือถือ (CNN) ไม่ตอบ'
                : "CNN อ่านได้ \"{$cnn}\" Gemini อ่านได้ \"{$gemini}\"";
        }

        return $lines;
    }

    /**
     * Rules with a non-zero weight, strongest first.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array{name: string, weight: float, note?: string}>
     */
    private static function fired(array $rules): array
    {
        $fired = array_values(array_filter($rules, fn ($r) => is_array($r) && isset($r['name']) && (float) ($r['weight'] ?? 0) > 0.0));
        usort($fired, fn (array $a, array $b) => (float) $b['weight'] <=> (float) $a['weight']);

        return $fired;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function input(array $inputs, string $name): string
    {
        return isset($inputs[$name]) ? " ({$name} = ".self::num($inputs[$name]).')' : '';
    }

    private static function num(mixed $value): string
    {
        $v = round((float) $value, 2);

        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
