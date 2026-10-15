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
                ->modalDescription('รูปแบบ: subject_code,level,code,parent_code,grade_level,name (UTF-8 ไม่มี BOM ดู docs/curriculum) รูปแบบเดิมที่ไม่มี level ยังใช้ได้ แถวที่มี code เดิมจะถูกอัปเดต แถวที่ผิดจะถูกข้ามและแจ้งเลขบรรทัด')
                ->modalSubmitActionLabel('นำเข้า')
                ->authorize(fn () => Gate::allows('import', Skill::class))
                ->schema([
                    FileUpload::make('csv')
                        ->label('ไฟล์ CSV')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        // The whole core curriculum is a few MB of Thai text.
                        ->maxSize(20480),
                    Select::make('school_id')
                        ->label('นำเข้าเป็นทักษะย่อยของโรงเรียน')
                        ->options(fn () => School::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('— ตัวชี้วัดหลักสูตรแกนกลาง (ทุกโรงเรียน) —')
                        ->nullable(),
                ])
                ->action(function (array $data, SkillCsvImporter $importer) {
                    /** @var TemporaryUploadedFile|string $file */
                    $file = $data['csv'];
                    $schoolId = filled($data['school_id'] ?? null) ? (int) $data['school_id'] : null;
                    $path = $file instanceof TemporaryUploadedFile ? $file->getRealPath() : false;

                    try {
                        // Streamed from the upload: the full curriculum is tens of thousands of rows.
                        $result = is_string($path) && $path !== ''
                            ? $importer->importFile($path, $schoolId)
                            : $importer->importString((string) $file, $schoolId);
                    } catch (SkillImportException $e) {
                        Notification::make()
                            ->title('นำเข้าไม่สำเร็จ ไม่มีข้อมูลถูกบันทึก')
                            ->body(implode("\n", array_slice($e->errors, 0, 10)))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $body = "สร้างใหม่ {$result->created} อัปเดต {$result->updated} ไม่เปลี่ยน {$result->unchanged} วิชาใหม่ {$result->subjectsCreated}"
                        .($result->warnings === [] ? '' : "\n".implode("\n", $result->warnings));
                    if ($result->hasErrors()) {
                        $lines = $result->errorLines();
                        Notification::make()
                            ->title('นำเข้าแล้ว '.$result->rows().' รายการ มี '.count($result->errors).' แถวที่ผิด (ข้ามไป)')
                            ->body($body."\n".implode("\n", array_slice($lines, 0, 10)).(count($lines) > 10 ? "\n…" : ''))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("นำเข้าแล้ว {$result->rows()} รายการ")
                        ->body($body)
                        ->success()
                        ->send();
                }),
        ];
    }
}
