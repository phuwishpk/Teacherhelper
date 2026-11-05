<?php

namespace App\Http\Controllers\Usability;

use App\Domain\Qa\QaCatalog;
use App\Domain\Qa\QaSignupCode;
use App\Domain\Qa\QaSus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sus\SusController;
use App\Models\QaResult;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The short usability set for outside participants (DESIGN §39.10), in the
 * look of /sus: sign up with a name, an occupation and the group's code
 * (QaSignupCode), rate the four tasks of the short set one by one, then
 * answer SUS once. The account has no usable email or password: closing
 * the browser ends it, and the participant signs up again.
 */
class UsabilityController extends Controller
{
    /** The participant's one SUS answer counts as the teacher side: three of the four tasks are a teacher's. */
    private const SUS_ROLE = 'teacher';

    /** Shown on the tasks: how a participant did it on their own. */
    public const STATUSES = ['success' => 'ทำได้เอง', 'assisted' => 'ทำได้ แต่มีคนช่วย', 'fail' => 'ทำไม่ได้'];

    public function show(): Response
    {
        $me = $this->participant();
        if ($me === null) {
            return $this->page('usability.signup', ['open' => QaSignupCode::isOpen()]);
        }

        return $this->page('usability.tasks', [
            'me' => $me,
            'tasks' => QaCatalog::ofKind(QaCatalog::QUICK),
            'results' => $me->results()->get()->keyBy('case_code'),
        ]);
    }

    public function signup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'occupation' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
        ], [
            'name.required' => 'กรอกชื่อ',
            'occupation.required' => 'กรอกอาชีพ',
            'code.required' => 'กรอกรหัสกลุ่ม',
        ]);
        if (! QaSignupCode::matches(trim($data['code']))) {
            throw ValidationException::withMessages(['code' => QaSignupCode::isOpen()
                ? 'รหัสกลุ่มไม่ถูกต้อง ถามรหัสจากผู้ดำเนินการทดสอบ'
                : 'ตอนนี้ยังไม่เปิดรับผู้ทดสอบ'])->redirectTo(route('usability.show'));
        }

        $participant = QaTester::query()->create([
            'name' => trim($data['name']),
            'occupation' => trim($data['occupation']),
            // Never typed by anyone: the account lives in this browser's session.
            'email' => 'participant-'.Str::uuid().'@usability.invalid',
            'password' => Str::random(40),
            'role' => QaTester::ROLE_PARTICIPANT,
        ]);
        Auth::guard('usability')->login($participant);
        $request->session()->regenerate();

        return redirect()->route('usability.show');
    }

    public function saveTask(Request $request, string $code): RedirectResponse
    {
        $me = $this->participant();
        if ($me === null) {
            return redirect()->route('usability.show');
        }
        abort_unless(in_array($code, array_column(QaCatalog::ofKind(QaCatalog::QUICK), 'code'), true), 404);

        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'ease' => ['required', 'integer', 'between:1,5'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'status.required' => 'เลือกว่าทำงานนี้ได้ไหม',
            'status.in' => 'เลือกว่าทำงานนี้ได้ไหม',
            'ease.required' => 'ให้คะแนนความง่าย 1 ถึง 5',
            'ease.between' => 'ให้คะแนนความง่าย 1 ถึง 5',
        ]);
        if ($validator->fails()) {
            // Back to the card of this task, which shows the error and what was typed.
            return redirect()->to(route('usability.show').'#'.$code)
                ->withErrors($validator)->withInput()->with('usability_task', $code);
        }
        $data = $validator->validated();

        QaResult::query()->updateOrCreate(
            ['qa_tester_id' => $me->id, 'case_code' => $code],
            [
                'status' => $data['status'],
                'ease' => (int) $data['ease'],
                'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
            ],
        );

        return redirect()->to(route('usability.show').'#'.$code)->with(['usability_saved' => $code, 'usability_task' => $code]);
    }

    public function showSus(): Response|RedirectResponse
    {
        $me = $this->participant();
        if ($me === null) {
            return redirect()->route('usability.show');
        }
        if (! $this->tasksDone($me)) {
            return redirect()->route('usability.show')->with('usability_tasks_first', true);
        }

        return $this->page('usability.sus', [
            'me' => $me,
            'sent' => QaSusResponse::query()->where('qa_tester_id', $me->id)->exists(),
        ]);
    }

    public function storeSus(Request $request): RedirectResponse
    {
        $me = $this->participant();
        if ($me === null) {
            return redirect()->route('usability.show');
        }
        if (! $this->tasksDone($me)) {
            return redirect()->route('usability.show')->with('usability_tasks_first', true);
        }
        if (QaSusResponse::query()->where('qa_tester_id', $me->id)->exists()) {
            return redirect()->route('usability.sus');
        }

        [$rules, $messages] = QaSus::formRules();
        $data = $request->validate($rules, $messages);
        QaSusResponse::query()->create([
            'qa_tester_id' => $me->id,
            'role' => self::SUS_ROLE,
            ...QaSus::responseFrom($data),
        ]);

        return redirect()->route('usability.sus');
    }

    private function participant(): ?QaTester
    {
        $who = Auth::guard('usability')->user();

        return $who instanceof QaTester && $who->isParticipant() ? $who : null;
    }

    private function tasksDone(QaTester $me): bool
    {
        $codes = array_column(QaCatalog::ofKind(QaCatalog::QUICK), 'code');

        return $me->results()->whereIn('case_code', $codes)->whereNotNull('status')->count() === count($codes);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data = []): Response
    {
        return response()->view($view, $data)->withHeaders([
            'Content-Security-Policy' => SusController::CSP,
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
