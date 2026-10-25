<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\CiStatus;
use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaCatalog;
use App\Models\QaTester;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * The Test Lab's home (DESIGN §39): the four headline numbers, who has
 * filled in what, the automated cases with their latest CI run, the manual
 * cases per tester, the usability tasks and the SUS scores.
 */
class QaOverview extends Page
{
    protected string $view = 'filament.qa.overview';

    protected static ?string $title = 'ภาพรวมการทดสอบ';

    protected static ?string $navigationLabel = 'ภาพรวม';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 1;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function board(): QaBoard
    {
        return new QaBoard;
    }

    /** @return array<string, mixed>|null */
    public function ci(): ?array
    {
        return CiStatus::latest();
    }

    public function deployedCommit(): ?string
    {
        return CiStatus::deployedCommit();
    }

    public function isLead(): bool
    {
        $tester = Filament::auth()->user();

        return $tester instanceof QaTester && $tester->is_lead;
    }

    /** Lead only: ask GitHub again now instead of waiting for the cache. */
    public function refreshCi(): void
    {
        if (! $this->isLead()) {
            return;
        }
        CiStatus::forget();
        Notification::make()->title('อ่านผล CI ใหม่จาก GitHub แล้ว')->success()->send();
    }

    /** @return list<array<string, mixed>> */
    public function cases(string $kind): array
    {
        return QaCatalog::ofKind($kind);
    }
}
