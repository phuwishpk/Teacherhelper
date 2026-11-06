<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\CiStatus;
use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaCatalog;
use App\Domain\Qa\TestIndex;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One case in full (DESIGN §39): what it checks and why it is automated or
 * manual; for an automated case the test files with every test in them and
 * the latest CI run; for a manual case or a usability task the steps and
 * every tester's result with their notes.
 */
class TestCaseDetail extends Page
{
    protected string $view = 'filament.qa.case';

    protected static ?string $slug = 'cases/{code}';

    protected static bool $shouldRegisterNavigation = false;

    public string $code = '';

    /** @var array<string, mixed> */
    public array $case = [];

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'case';
    }

    public function mount(string $code): void
    {
        $case = QaCatalog::find($code);
        abort_if($case === null, 404);
        $this->code = $code;
        $this->case = $case;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->case['code'].' '.$this->case['title'];
    }

    public function board(): QaBoard
    {
        return new QaBoard;
    }

    /** @return array<string, mixed>|null */
    public function ci(): ?array
    {
        return $this->case['kind'] === QaCatalog::AUTO ? CiStatus::latest() : null;
    }

    /** @return array<string, list<string>|null> ref => the tests in it (null: not readable here) */
    public function tests(): array
    {
        $out = [];
        foreach ($this->case['refs'] ?? [] as $ref) {
            $out[$ref] = TestIndex::forRef($ref);
        }

        return $out;
    }

    public function githubUrl(string $ref): string
    {
        return 'https://github.com/'.CiStatus::repo().'/blob/main/'.$ref;
    }
}
