<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaCatalog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "สถานะทีม" (DESIGN §39.6): every tester's status on every case of one
 * section side by side, so the team sees who did what and what nobody has
 * tried yet; plus each tester's progress per kind.
 */
class TeamBoard extends Page
{
    protected string $view = 'filament.qa.team';

    protected static ?string $slug = 'team';

    protected static ?string $title = 'สถานะการทดสอบของทีม';

    protected static ?string $navigationLabel = 'สถานะทีม';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 2;

    #[Url]
    public string $section = MyResults::MANUAL_SECTION;

    /** Only cases nobody has a status for yet. */
    #[Url]
    public bool $untried = false;

    public function mount(): void
    {
        if ($this->section !== MyResults::MANUAL_SECTION && ! array_key_exists($this->section, QaCatalog::usabilityGroups())) {
            $this->section = MyResults::MANUAL_SECTION;
        }
    }

    public function board(): QaBoard
    {
        return new QaBoard;
    }

    /** @return array<string, string> */
    public function sections(): array
    {
        $out = [MyResults::MANUAL_SECTION => 'Integration ทำมือ ('.count(QaCatalog::ofKind(QaCatalog::MANUAL)).')'];
        foreach (QaCatalog::usabilityGroups() as $group => $count) {
            $out[$group] = "Usability: {$group} ({$count})";
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function cases(QaBoard $board): array
    {
        $cases = $this->section === MyResults::MANUAL_SECTION ? QaCatalog::ofKind(QaCatalog::MANUAL) : QaCatalog::inGroup($this->section);
        if ($this->untried) {
            $cases = array_values(array_filter($cases, fn (array $c) => $board->resultsFor($c['code'])->isEmpty()));
        }

        return $cases;
    }
}
