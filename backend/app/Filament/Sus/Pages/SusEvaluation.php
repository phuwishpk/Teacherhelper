<?php

namespace App\Filament\Sus\Pages;

use App\Domain\Qa\QaSus;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The SUS questionnaire (DESIGN §39.7), administered as Brooke describes:
 * right after using the system, every item answered, the first reaction
 * rather than a long thought, the centre (3) when unsure. One response per
 * evaluator per role of the app; once sent it is final, so the answer stays
 * the immediate one. The evaluator does not see a score (no feedback that
 * could colour a second response).
 */
class SusEvaluation extends Page
{
    protected string $view = 'filament.sus.evaluation';

    protected static ?string $title = 'แบบประเมินความง่ายในการใช้งาน (SUS)';

    protected static ?string $navigationLabel = 'แบบประเมิน';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public string $role = '';

    /** @var list<int|string|null> */
    public array $answers = [];

    public ?int $adjective = null;

    public string $platform = '';

    public string $experience = '';

    public string $comment = '';

    /** @var array<string, string> role => when it was sent */
    public array $sent = [];

    public bool $justSent = false;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title;
    }

    public function mount(): void
    {
        $this->loadSent();
        $this->role = collect(array_keys(QaSus::ROLES))->first(fn ($r) => ! isset($this->sent[$r])) ?? 'teacher';
        $this->answers = array_fill(0, 10, null);
    }

    public function updatedRole(): void
    {
        $this->justSent = false;
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        abort_unless(array_key_exists($this->role, QaSus::ROLES), 422);
        if (isset($this->sent[$this->role])) {
            return;
        }
        $this->validate([
            'answers' => ['required', 'array', 'size:10'],
            'answers.*' => ['required', 'integer', 'between:1,5'],
            'adjective' => ['required', 'integer', 'between:1,7'],
            'platform' => ['required', 'in:'.implode(',', array_keys(QaSus::PLATFORMS))],
            'experience' => ['required', 'in:'.implode(',', array_keys(QaSus::EXPERIENCE))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'answers.*.required' => 'ตอบให้ครบทุกข้อ ข้อที่ไม่แน่ใจให้เลือก 3',
            'adjective.required' => 'เลือกระดับความเป็นมิตรต่อผู้ใช้โดยรวม',
            'platform.required' => 'เลือกอุปกรณ์ที่ใช้',
            'experience.required' => 'เลือกประสบการณ์การใช้งาน',
        ]);
        $answers = array_map(intval(...), $this->answers);
        QaSusResponse::query()->create([
            'qa_tester_id' => $this->evaluator()->id,
            'role' => $this->role,
            'answers' => $answers,
            'score' => QaSus::score($answers),
            'adjective' => $this->adjective,
            'platform' => $this->platform,
            'experience' => $this->experience,
            'comment' => trim($this->comment) ?: null,
        ]);
        $this->loadSent();
        $this->justSent = true;
        $this->answers = array_fill(0, 10, null);
        $this->reset(['adjective', 'platform', 'experience', 'comment']);
    }

    private function loadSent(): void
    {
        $this->sent = QaSusResponse::query()
            ->where('qa_tester_id', $this->evaluator()->id)
            ->get()
            ->mapWithKeys(fn (QaSusResponse $r) => [$r->role => $r->created_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? ''])
            ->all();
    }

    private function evaluator(): QaTester
    {
        $who = Filament::auth()->user();
        abort_unless($who instanceof QaTester && $who->isEvaluator(), 403);

        return $who;
    }
}
