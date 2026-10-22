<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaCatalog;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "แบบประเมิน SUS" (DESIGN §39): ten 1–5 answers after the usability tasks;
 * the score is computed here and on save, one response per tester.
 */
class SusSurvey extends Page
{
    protected string $view = 'filament.qa.sus';

    protected static ?string $slug = 'sus';

    protected static ?string $title = 'แบบประเมิน SUS';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static ?int $navigationSort = 4;

    /** @var list<int|null> */
    public array $answers = [];

    public string $role = 'teacher';

    public string $comment = '';

    public ?string $savedAt = null;

    public function mount(): void
    {
        $own = QaSusResponse::query()->where('qa_tester_id', $this->tester()->id)->first();
        $this->answers = $own !== null ? array_map(intval(...), $own->answers) : array_fill(0, 10, null);
        $this->role = $own->role ?? 'teacher';
        $this->comment = (string) ($own->comment ?? '');
        $this->savedAt = $own?->updated_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i');
    }

    public function score(): ?float
    {
        return QaCatalog::susScore(array_map(fn ($a) => $a === null || $a === '' ? null : (int) $a, $this->answers));
    }

    public function save(): void
    {
        $this->validate([
            'answers' => ['required', 'array', 'size:10'],
            'answers.*' => ['required', 'integer', 'between:1,5'],
            'role' => ['required', 'in:teacher,student'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['answers.*.required' => 'ตอบให้ครบทุกข้อ']);
        $answers = array_map(intval(...), $this->answers);
        $row = QaSusResponse::query()->updateOrCreate(
            ['qa_tester_id' => $this->tester()->id],
            ['role' => $this->role, 'answers' => $answers, 'score' => QaCatalog::susScore($answers), 'comment' => trim($this->comment) ?: null],
        );
        $this->savedAt = $row->updated_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i');
        Notification::make()->title('บันทึกแบบประเมินแล้ว คะแนน '.$row->score)->success()->send();
    }

    private function tester(): QaTester
    {
        $tester = Filament::auth()->user();
        abort_unless($tester instanceof QaTester, 403);

        return $tester;
    }
}
