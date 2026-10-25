<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\ManualChecklist;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "เช็คลิสต์ทดสอบด้วยมือ" (DESIGN §39.7): the manual web test from sign-up
 * to grades, with its test files to download and boxes to tick (kept in the
 * tester's browser only; results still go to /qa/run).
 */
class ManualTestChecklist extends Page
{
    protected string $view = 'filament.qa.checklist';

    protected static ?string $slug = 'checklist';

    protected static ?string $title = 'เช็คลิสต์ทดสอบด้วยมือ';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?int $navigationSort = 5;

    public static function fileUrl(string $name): string
    {
        return route('filament.qa.checklist.file', ['file' => $name]);
    }

    public function html(): string
    {
        return ManualChecklist::html(fn (string $name) => self::fileUrl($name));
    }

    /** @return list<array{name: string, size: int, url: string}> */
    public function files(): array
    {
        return array_map(fn (array $f) => $f + ['url' => self::fileUrl($f['name'])], ManualChecklist::files());
    }

    /** Changes when README.md changes, so old ticks do not land on renumbered steps. */
    public function storageKey(): string
    {
        return 'qa-checklist-'.substr(md5((string) @file_get_contents(ManualChecklist::readme())), 0, 8);
    }
}
