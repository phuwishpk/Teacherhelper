<?php

namespace App\Filament\Resources\Skills\Pages;

use App\Domain\Skills\SkillCsvImporter;
use App\Domain\Skills\SkillImportException;
use App\Filament\Resources\Skills\SkillResource;
use App\Models\School;
use App\Models\Skill;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageSkills extends ManageRecords
{
    protected static string $resource = SkillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('นำเข้า CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('นำเข้าตัวชี้วัดจาก CSV')
                ->modalDescription('รูปแบบ: subject_code,skill_code,parent_code,grade_level,name (UTF-8 ไม่มี BOM) แถวที่มี skill_code เดิมจะถูกอัปเดต')
                ->modalSubmitActionLabel('นำเข้า')
                ->authorize(fn () => Gate::allows('import', Skill::class))
                ->schema([
                    FileUpload::make('csv')
                        ->label('ไฟล์ CSV')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(2048),
                    Select::make('school_id')
                        ->label('นำเข้าเป็นทักษะย่อยของโรงเรียน')
                        ->options(fn () => School::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('— ตัวชี้วัดหลักสูตรแกนกลาง (ทุกโรงเรียน) —')
                        ->nullable(),
                ])
                ->action(function (array $data, SkillCsvImporter $importer) {
                    /** @var TemporaryUploadedFile|string $file */
                    $file = $data['csv'];
                    $csv = $file instanceof TemporaryUploadedFile ? $file->get() : (string) $file;
                    $schoolId = filled($data['school_id'] ?? null) ? (int) $data['school_id'] : null;

                    try {
                        $result = $importer->importString((string) $csv, $schoolId);
                    } catch (SkillImportException $e) {
                        Notification::make()
                            ->title('นำเข้าไม่สำเร็จ ไม่มีข้อมูลถูกบันทึก')
                            ->body(implode("\n", array_slice($e->errors, 0, 10)))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("นำเข้าแล้ว {$result->rows()} รายการ")
                        ->body("สร้างใหม่ {$result->created} อัปเดต {$result->updated} วิชาใหม่ {$result->subjectsCreated}"
                            .($result->warnings === [] ? '' : "\n".implode("\n", $result->warnings)))
                        ->success()
                        ->send();
                }),
        ];
    }
}
