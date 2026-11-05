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
 * page: per task the success, time, errors and ease; per person what they
 * did and, for participants who signed up at /usability (§39.10), who they
 * are and their own SUS; the mean SUS (§39.9) against 68; and what people
 * said.
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
     * The people the report is about: participants who signed up at
     * /usability, then testers who recorded someone's attempt, each with a
     * result on a task of the set (a skip is no attempt). A participant's
     * SUS is theirs; an evaluator's at /sus is tied to nobody's tasks, so
     * it only counts in the mean.
     *
     * @return list<QaTester>
     */
    public function participants(QaBoard $board): array
    {
        $codes = array_column($this->tasks(), 'code');
        $people = QaTester::query()->where('role', QaTester::ROLE_PARTICIPANT)->orderBy('created_at')->get()
            ->concat($board->testers());

        return $people->filter(function (QaTester $t) use ($board, $codes) {
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
