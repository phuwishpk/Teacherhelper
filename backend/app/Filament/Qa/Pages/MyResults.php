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
use Livewire\Attributes\Url;

/**
 * "กรอกผล" (DESIGN §39.6): the signed-in tester's own result for every
 * manual integration case and usability task, one section at a time
 * (manual integration, the end-to-end processes, or one chapter of use
 * cases). Every card shows what the other testers recorded, and a use case
 * task carries its alternative paths as separate checks. Each card saves on
 * its own; nobody writes another tester's rows.
 */
class MyResults extends Page
{
    protected string $view = 'filament.qa.run';

    protected static ?string $slug = 'run';

    protected static ?string $title = 'กรอกผลการทดสอบ';

    protected static ?string $navigationLabel = 'กรอกผล';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 3;

    public const MANUAL_SECTION = 'manual';

    /** "manual" or a usability group (QaCatalog::usabilityGroups). */
    #[Url]
    public string $section = self::MANUAL_SECTION;

    /** Only the cards this tester has not recorded yet. */
    #[Url]
    public bool $open = false;

    /** @var array<string, array{status: string|null, time_sec: int|string|null, error_count: int|string|null, note: string|null, checks: list<string>}> keyed by form key */
    public array $form = [];

    /** @var array<string, string> form key => when it was saved */
    public array $savedAt = [];

    public function mount(): void
    {
        if ($this->section !== self::MANUAL_SECTION && ! array_key_exists($this->section, QaCatalog::usabilityGroups())) {
            $this->section = self::MANUAL_SECTION;
        }
        $rows = QaResult::query()->where('qa_tester_id', $this->tester()->id)->get()->keyBy('case_code');
        foreach (QaCatalog::ofKinds([QaCatalog::MANUAL, ...QaCatalog::USABILITY_KINDS]) as $case) {
            $row = $rows->get($case['code']);
            $key = self::key($case['code']);
            $this->form[$key] = [
                'status' => $row?->status,
                'time_sec' => $row?->time_sec,
                'error_count' => $row?->error_count,
                'note' => $row?->note,
                'checks' => array_map(fn (array $alt) => (string) ($row?->checks[$alt['label']] ?? ''), $case['alts'] ?? []),
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

    /** @return array<string, string> section value => label with counts */
    public function sections(): array
    {
        $mine = fn (array $cases) => count(array_filter($cases, fn (array $c) => isset($this->savedAt[self::key($c['code'])]) && filled($this->form[self::key($c['code'])]['status'] ?? null)));
        $manual = QaCatalog::ofKind(QaCatalog::MANUAL);
        $out = [self::MANUAL_SECTION => 'Integration ทำมือ ('.$mine($manual).'/'.count($manual).')'];
        foreach (QaCatalog::usabilityGroups() as $group => $count) {
            $out[$group] = 'Usability: '.$group.' ('.$mine(QaCatalog::inGroup($group)).'/'.$count.')';
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the cards of the chosen section */
    public function visibleCases(): array
    {
        $cases = $this->section === self::MANUAL_SECTION ? QaCatalog::ofKind(QaCatalog::MANUAL) : QaCatalog::inGroup($this->section);
        if ($this->open) {
            $cases = array_values(array_filter($cases, fn (array $c) => blank($this->form[self::key($c['code'])]['status'] ?? null) || ! isset($this->savedAt[self::key($c['code'])])));
        }

        return $cases;
    }

    /**
     * What the other testers recorded for the visible cases.
     *
     * @return array<string, list<array{name: string, label: string, tone: string}>> code => entries
     */
    public function teamStatuses(): array
    {
        $codes = array_column($this->visibleCases(), 'code');
        $names = QaTester::query()->pluck('name', 'id');
        $out = [];
        foreach (QaResult::query()->whereIn('case_code', $codes)->where('qa_tester_id', '!=', $this->tester()->id)->whereNotNull('status')->orderBy('qa_tester_id')->get() as $row) {
            $case = QaCatalog::find($row->case_code);
            [$label, $tone] = QaCatalog::statusesFor($case['kind'])[$row->status] ?? [$row->status, 'gray'];
            $out[$row->case_code][] = ['name' => (string) $names->get($row->qa_tester_id, 'ผู้ทดสอบ'), 'label' => $label, 'tone' => $tone];
        }

        return $out;
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
            "form.{$key}.checks" => ['array'],
            "form.{$key}.checks.*" => ['nullable', 'in:pass,fail'],
        ], [], [
            "form.{$key}.time_sec" => 'เวลา',
            "form.{$key}.error_count" => 'จำนวนครั้งที่ทำผิด',
            "form.{$key}.note" => 'บันทึก',
        ]);
        $entry = $this->form[$key];
        $usability = QaCatalog::isUsability($case['kind']);
        $checks = [];
        foreach ($case['alts'] ?? [] as $i => $alt) {
            $value = $entry['checks'][$i] ?? '';
            if (in_array($value, ['pass', 'fail'], true)) {
                $checks[$alt['label']] = $value;
            }
        }
        $row = QaResult::query()->updateOrCreate(
            ['qa_tester_id' => $this->tester()->id, 'case_code' => $code],
            [
                'status' => $entry['status'] ?: null,
                'time_sec' => $usability && filled($entry['time_sec']) ? (int) $entry['time_sec'] : null,
                'error_count' => $usability && filled($entry['error_count']) ? (int) $entry['error_count'] : null,
                'note' => filled($entry['note']) ? trim((string) $entry['note']) : null,
                'checks' => $checks === [] ? null : $checks,
            ],
        );
        $this->savedAt[$key] = $row->updated_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') ?? '';
        Notification::make()->title("บันทึก {$code} แล้ว")->success()->send();
    }

    private function tester(): QaTester
    {
        $tester = Filament::auth()->user();
        abort_unless($tester instanceof QaTester, 403);

        return $tester;
    }
}
