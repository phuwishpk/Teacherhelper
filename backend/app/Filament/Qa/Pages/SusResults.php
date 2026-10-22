<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaSus;
use App\Models\QaSusResponse;
use App\Models\QaTester;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "ผล SUS" (DESIGN §39.7): the evaluators' SUS reported the standard way,
 * per role of the app and all together: n, mean ± SD, median, range, the
 * 95% confidence interval, the Sauro–Lewis grade, Bangor's acceptability and
 * adjective, the benchmark 68, the item means, the adjective-rating answers
 * and every response. Testers read it; only evaluators answer.
 */
class SusResults extends Page
{
    protected string $view = 'filament.qa.sus-results';

    protected static ?string $slug = 'sus';

    protected static ?string $title = 'ผลแบบประเมิน SUS';

    protected static ?string $navigationLabel = 'ผล SUS';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static ?int $navigationSort = 4;

    /** "all" or a key of QaSus::ROLES. */
    #[Url]
    public string $role = 'all';

    public function board(): QaBoard
    {
        return new QaBoard;
    }

    public function roleFilter(): ?string
    {
        return array_key_exists($this->role, QaSus::ROLES) ? $this->role : null;
    }

    /**
     * Per item: the mean answer (1–5) and the mean contribution (0–4).
     *
     * @param  iterable<QaSusResponse>  $responses
     * @return list<array{en: string, th: string, positive: bool, mean: float|null, contribution: float|null}>
     */
    public function items(iterable $responses): array
    {
        $rows = collect($responses);
        $out = [];
        foreach (QaSus::ITEMS as $i => $item) {
            $answers = $rows->map(fn (QaSusResponse $r) => (int) ($r->answers[$i] ?? 0))->filter(fn ($a) => $a >= 1);
            $out[] = [
                ...$item,
                'positive' => $i % 2 === 0,
                'mean' => $answers->isEmpty() ? null : round($answers->avg(), 2),
                'contribution' => $answers->isEmpty() ? null : round($answers->map(fn ($a) => QaSus::contribution($i, $a))->avg(), 2),
            ];
        }

        return $out;
    }

    /** @return array<int, string> evaluator id => name */
    public function evaluators(): array
    {
        return QaTester::query()->where('role', QaTester::ROLE_EVALUATOR)->orderBy('name')->pluck('name', 'id')->all();
    }
}
