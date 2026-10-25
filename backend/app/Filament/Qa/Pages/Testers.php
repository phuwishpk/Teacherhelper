<?php

namespace App\Filament\Qa\Pages;

use App\Domain\Qa\QaBoard;
use App\Domain\Qa\QaCatalog;
use App\Models\QaTester;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * "ผู้ทดสอบ" (DESIGN §39.6, §39.7): the testers and the SUS evaluators.
 * Any tester adds a tester or an evaluator (name, email, password, role);
 * only the lead removes people or makes a tester lead. Krucheck admins
 * manage the same accounts at /admin.
 */
class Testers extends Page
{
    protected string $view = 'filament.qa.testers';

    protected static ?string $slug = 'testers';

    protected static ?string $title = 'ผู้ทดสอบ';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 6;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $isLead = false;

    /** QaTester::ROLE_TESTER or QaTester::ROLE_EVALUATOR. */
    public string $role = QaTester::ROLE_TESTER;

    public function board(): QaBoard
    {
        return new QaBoard;
    }

    /** @return Collection<int, QaTester> */
    public function evaluators()
    {
        return QaTester::query()->where('role', QaTester::ROLE_EVALUATOR)->withCount('susResponses')->orderBy('name')->get();
    }

    public function me(): QaTester
    {
        $tester = Filament::auth()->user();
        abort_unless($tester instanceof QaTester, 403);

        return $tester;
    }

    /** @return array<string, int> kind => number of cases a tester can record */
    public function totals(): array
    {
        return [
            QaCatalog::MANUAL => count(QaCatalog::ofKind(QaCatalog::MANUAL)),
            QaCatalog::PROCESS => count(QaCatalog::ofKind(QaCatalog::PROCESS)),
            QaCatalog::FUNCTION => count(QaCatalog::ofKind(QaCatalog::FUNCTION)),
        ];
    }

    public function addTester(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('qa_testers', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'isLead' => ['boolean'],
            'role' => ['required', Rule::in(array_keys(QaTester::ROLES))],
        ], [], ['name' => 'ชื่อ', 'email' => 'อีเมล', 'password' => 'รหัสผ่าน']);
        $evaluator = $data['role'] === QaTester::ROLE_EVALUATOR;

        QaTester::create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'role' => $data['role'],
            // Only a lead makes another lead, and an evaluator is never one.
            'is_lead' => ! $evaluator && $this->me()->is_lead && $data['isLead'],
        ]);
        $this->reset(['name', 'email', 'password', 'isLead', 'role']);
        Notification::make()
            ->title($evaluator ? 'เพิ่มผู้ประเมิน SUS แล้ว เขาเข้าที่ '.url('/sus') : 'เพิ่มผู้ทดสอบแล้ว เขาเข้าที่ '.url('/qa'))
            ->body('ส่งอีเมลและรหัสผ่านให้เขาเอง')
            ->success()
            ->send();
    }

    public function toggleLead(int $id): void
    {
        $tester = QaTester::query()->find($id);
        if (! $this->me()->is_lead || $tester === null || $tester->id === $this->me()->id || $tester->isEvaluator()) {
            return;
        }
        $tester->forceFill(['is_lead' => ! $tester->is_lead])->save();
    }

    public function removeTester(int $id): void
    {
        $tester = QaTester::query()->find($id);
        if (! $this->me()->is_lead || $tester === null || $tester->id === $this->me()->id) {
            return;
        }
        $tester->delete();
        Notification::make()->title("ลบ {$tester->name} และผลของเขาแล้ว")->success()->send();
    }
}
