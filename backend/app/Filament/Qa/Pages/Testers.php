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
use Illuminate\Validation\Rule;

/**
 * "ผู้ทดสอบ" (DESIGN §39.6): the team. Any tester adds a new tester (name,
 * email, password); only the lead removes testers or makes someone lead.
 * Krucheck admins manage the same accounts at /admin.
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

    public function board(): QaBoard
    {
        return new QaBoard;
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
            QaCatalog::QUICK => count(QaCatalog::ofKind(QaCatalog::QUICK)),
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
        ], [], ['name' => 'ชื่อ', 'email' => 'อีเมล', 'password' => 'รหัสผ่าน']);

        QaTester::create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            // Only a lead makes another lead.
            'is_lead' => $this->me()->is_lead && $data['isLead'],
        ]);
        $this->reset(['name', 'email', 'password', 'isLead']);
        Notification::make()->title('เพิ่มผู้ทดสอบแล้ว ส่งอีเมลและรหัสผ่านให้เขาเอง')->success()->send();
    }

    public function toggleLead(int $id): void
    {
        $tester = QaTester::query()->find($id);
        if (! $this->me()->is_lead || $tester === null || $tester->id === $this->me()->id) {
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
