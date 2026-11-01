<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaCatalog;
use App\Models\QaTester;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "รายงาน Usability" (DESIGN §39.8): the short set of four tasks for at
 * least five outside testers, as the course rubric asks, on one printable
 * page: per task the success, time and errors; per tester what they did;
 * the evaluators' mean SUS (§39.9) against 68; and what people said.
 */
class UsabilityReport extends Page
{
    protected string $view = 'filament.qa.report';

    protected static ?string $slug = 'report';

    protected static ?string $title = 'รายงาน Usability';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?int $navigationSort = 3;

    public function board(): QaBoard
    {
        return new QaBoard;
    }

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        return QaCatalog::ofKind(QaCatalog::QUICK);
    }

    /**
     * Testers with a result on a task of the set (a skip is no attempt): the
     * people the report is about. SUS answers belong to evaluator accounts,
     * which are not tied to a tester, so SUS is reported as a whole.
     *
     * @return list<QaTester>
     */
    public function participants(QaBoard $board): array
    {
        $codes = array_column($this->tasks(), 'code');

        return $board->testers()->filter(function (QaTester $t) use ($board, $codes) {
            foreach ($codes as $code) {
                $status = $board->statusOf($t->id, $code);
                if ($status !== null && $status !== 'skip') {
                    return true;
                }
            }

            return false;
        })->values()->all();
    }
}
