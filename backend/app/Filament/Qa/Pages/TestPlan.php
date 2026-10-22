<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaCatalog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * The test plan (DESIGN §39): why a case is automated or manual, and every
 * case with a link to its details.
 */
class TestPlan extends Page
{
    protected string $view = 'filament.qa.plan';

    protected static ?string $slug = 'plan';

    protected static ?string $title = 'แผนทดสอบ';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 5;

    #[Url]
    public string $kind = 'all';

    /** @return list<array<string, mixed>> */
    public function cases(): array
    {
        return $this->kind === 'all' ? QaCatalog::cases() : QaCatalog::ofKind($this->kind);
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $out = [];
        foreach (array_keys(QaCatalog::KIND_LABELS) as $kind) {
            $out[$kind] = count(QaCatalog::ofKind($kind));
        }

        return $out;
    }

    /** @return array{steps: int, alts: int} the size of the function-level usability set */
    public function functionSize(): array
    {
        $functions = QaCatalog::ofKind(QaCatalog::FUNCTION);

        return [
            'steps' => array_sum(array_map(fn (array $c) => count($c['steps']), $functions)),
            'alts' => array_sum(array_map(fn (array $c) => count($c['alts']), $functions)),
        ];
    }
}
