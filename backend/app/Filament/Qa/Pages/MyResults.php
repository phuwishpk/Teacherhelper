<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaCatalog;
use App\Models\QaResult;
use App\Models\QaTester;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "กรอกผล" (DESIGN §39): the signed-in tester's own result for every manual
 * case and usability task. Each card saves on its own; nobody writes
 * another tester's rows.
 */
class MyResults extends Page
{
    protected string $view = 'filament.qa.run';

    protected static ?string $slug = 'run';

    protected static ?string $title = 'กรอกผลการทดสอบ';

    protected static ?string $navigationLabel = 'กรอกผล';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 3;

    /** @var array<string, array{status: string|null, time_sec: int|string|null, error_count: int|string|null, note: string|null}> keyed by form key */
    public array $form = [];

    /** @var array<string, string> form key => when it was saved */
    public array $savedAt = [];

    public function mount(): void
    {
        $rows = QaResult::query()->where('qa_tester_id', $this->tester()->id)->get()->keyBy('case_code');
        foreach ([...QaCatalog::ofKind(QaCatalog::MANUAL), ...QaCatalog::ofKind(QaCatalog::USABILITY)] as $case) {
            $row = $rows->get($case['code']);
            $key = self::key($case['code']);
            $this->form[$key] = [
                'status' => $row?->status,
                'time_sec' => $row?->time_sec,
                'error_count' => $row?->error_count,
                'note' => $row?->note,
            ];
            if ($row !== null) {
                $this->savedAt[$key] = $row->updated_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? '';
            }
        }
    }

    /** Livewire keys stay plain: "IT-M01" -> "IT_M01". */
    public static function key(string $code): string
    {
        return str_replace('-', '_', $code);
    }

    public function save(string $code): void
    {
        $case = QaCatalog::find($code);
        if ($case === null || $case['kind'] === QaCatalog::AUTO) {
            return;
        }
        $key = self::key($code);
        $statuses = array_keys(QaCatalog::statusesFor($case['kind']));
        $this->validate([
            "form.{$key}.status" => ['nullable', 'in:'.implode(',', $statuses)],
            "form.{$key}.time_sec" => ['nullable', 'integer', 'min:0', 'max:86400'],
            "form.{$key}.error_count" => ['nullable', 'integer', 'min:0', 'max:999'],
            "form.{$key}.note" => ['nullable', 'string', 'max:2000'],
        ], [], [
            "form.{$key}.time_sec" => 'เวลา',
            "form.{$key}.error_count" => 'จำนวนครั้งที่ทำผิด',
            "form.{$key}.note" => 'บันทึก',
        ]);
        $entry = $this->form[$key];
        $usability = $case['kind'] === QaCatalog::USABILITY;
        $row = QaResult::query()->updateOrCreate(
            ['qa_tester_id' => $this->tester()->id, 'case_code' => $code],
            [
                'status' => $entry['status'] ?: null,
                'time_sec' => $usability && $entry['time_sec'] !== null && $entry['time_sec'] !== '' ? (int) $entry['time_sec'] : null,
                'error_count' => $usability && $entry['error_count'] !== null && $entry['error_count'] !== '' ? (int) $entry['error_count'] : null,
                'note' => filled($entry['note']) ? trim((string) $entry['note']) : null,
            ],
        );
        $this->savedAt[$key] = $row->updated_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? '';
        Notification::make()->title("บันทึก {$code} แล้ว")->success()->send();
    }

    /** @return list<array<string, mixed>> */
    public function cases(string $kind): array
    {
        return QaCatalog::ofKind($kind);
    }

    public function filled(string $kind): int
    {
        return count(array_filter(QaCatalog::ofKind($kind), fn (array $c) => filled($this->form[self::key($c['code'])]['status'] ?? null) && isset($this->savedAt[self::key($c['code'])])));
    }

    private function tester(): QaTester
    {
        $tester = Filament::auth()->user();
        abort_unless($tester instanceof QaTester, 403);

        return $tester;
    }
}
