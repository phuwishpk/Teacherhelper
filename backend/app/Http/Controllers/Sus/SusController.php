<?php

namespace App\Http\Controllers\Sus;

use App\Domain\Qa\QaSus;
use App\Http\Controllers\Controller;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The SUS questionnaire for evaluators (DESIGN §39.7): pages of their own
 * at /sus, separate from the Test Lab and with their own look. Plain HTML
 * forms, no script: the CSP below allows inline styles and posting to this
 * site only. Administered as Brooke (1996) describes; one response per role
 * of the app, final once sent; the evaluator never sees a score.
 */
class SusController extends Controller
{
    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

    public function showLogin(): Response|RedirectResponse
    {
        if ($this->evaluator() !== null) {
            return redirect()->route('sus.form');
        }

        return $this->page('sus.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], [], ['email' => 'อีเมล', 'password' => 'รหัสผ่าน']);

        $ok = Auth::guard('sus')->attempt([
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'role' => QaTester::ROLE_EVALUATOR,
        ]);
        if (! $ok) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง หรือบัญชีนี้ไม่ใช่ผู้ประเมิน SUS']);
        }
        $request->session()->regenerate();

        return redirect()->route('sus.form');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('sus')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('sus.login');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $evaluator = $this->evaluator();
        if ($evaluator === null) {
            return redirect()->route('sus.login');
        }
        $sent = $this->sent($evaluator);
        $role = (string) $request->query('role', '');
        if (! array_key_exists($role, QaSus::ROLES)) {
            $role = collect(array_keys(QaSus::ROLES))->first(fn ($r) => ! isset($sent[$r])) ?? 'teacher';
        }

        return $this->page('sus.form', ['evaluator' => $evaluator, 'role' => $role, 'sent' => $sent]);
    }

    public function store(Request $request): RedirectResponse
    {
        $evaluator = $this->evaluator();
        if ($evaluator === null) {
            return redirect()->route('sus.login');
        }
        $role = (string) $request->input('role');
        abort_unless(array_key_exists($role, QaSus::ROLES), 422);
        if (isset($this->sent($evaluator)[$role])) {
            return redirect()->route('sus.form', ['role' => $role]);
        }

        $messages = ['adjective.required' => 'เลือกความเป็นมิตรต่อผู้ใช้โดยรวม (ข้อ 11)', 'platform.required' => 'เลือกอุปกรณ์ที่ใช้', 'experience.required' => 'เลือกประสบการณ์กับ Krucheck'];
        foreach (range(1, 10) as $n) {
            $messages["q{$n}.required"] = "ตอบข้อ {$n} (ไม่แน่ใจให้เลือก 3)";
        }
        $rules = [
            'platform' => ['required', Rule::in(array_keys(QaSus::PLATFORMS))],
            'experience' => ['required', Rule::in(array_keys(QaSus::EXPERIENCE))],
            'adjective' => ['required', 'integer', 'between:1,7'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (range(1, 10) as $n) {
            $rules["q{$n}"] = ['required', 'integer', 'between:1,5'];
        }
        $data = $request->validate($rules, $messages);

        $answers = array_map(fn (int $n) => (int) $data["q{$n}"], range(1, 10));
        QaSusResponse::query()->create([
            'qa_tester_id' => $evaluator->id,
            'role' => $role,
            'answers' => $answers,
            'score' => QaSus::score($answers),
            'adjective' => (int) $data['adjective'],
            'platform' => $data['platform'],
            'experience' => $data['experience'],
            'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
        ]);

        return redirect()->route('sus.form', ['role' => $role])->with('sus_sent', $role);
    }

    private function evaluator(): ?QaTester
    {
        $who = Auth::guard('sus')->user();

        return $who instanceof QaTester && $who->isEvaluator() ? $who : null;
    }

    /** @return array<string, string> role => when it was sent */
    private function sent(QaTester $evaluator): array
    {
        return QaSusResponse::query()->where('qa_tester_id', $evaluator->id)->get()
            ->mapWithKeys(fn (QaSusResponse $r) => [$r->role => $r->created_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? ''])
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data = []): Response
    {
        return response()->view($view, $data)->withHeaders([
            'Content-Security-Policy' => self::CSP,
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
