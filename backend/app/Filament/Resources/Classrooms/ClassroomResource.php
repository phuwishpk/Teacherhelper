<?php

namespace App\Filament\Resources\Classrooms;

use App\Domain\Classrooms\ClassroomLifecycle;
use App\Exceptions\ApiException;
use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Models\Classroom;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * DESIGN §24.6, §24.8, §24.13: the admin of a school closes ("ห้องเก่า"),
 * reopens and deletes its classrooms with the same ClassroomLifecycle as the
 * app. Classrooms are created and edited by their homeroom teacher in the
 * app only. An admin of a school sees that school's classrooms; a system
 * admin (school_id null) sees all.
 */
class ClassroomResource extends Resource
{
    protected static ?string $model = Classroom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'ห้องเรียน';

    protected static ?string $pluralModelLabel = 'ห้องเรียน';

    protected static ?string $navigationLabel = 'ห้องเรียน';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    public const COUNT_LABELS = [
        'submissions' => 'งานที่ส่ง',
        'gradebook_entries' => 'คะแนนในสมุดคะแนน',
        'gradebook_publications' => 'การประกาศเกรด',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ห้อง')->searchable()->sortable(),
                TextColumn::make('academic_year')->label('ปีการศึกษา')->sortable(),
                TextColumn::make('teacher.name')->label('ครูประจำชั้น')->searchable(),
                TextColumn::make('school.name')->label('โรงเรียน')->sortable()
                    ->visible(fn () => self::admin()?->school_id === null),
                TextColumn::make('students_count')->label('นักเรียน')->counts('students')->sortable(),
                TextColumn::make('closed_at')
                    ->label('สถานะ')
                    ->badge()
                    ->state(fn (Classroom $record) => $record->isClosed() ? 'closed' : 'open')
                    ->formatStateUsing(fn (string $state) => $state === 'closed' ? 'ห้องเก่า' : 'เปิดอยู่')
                    ->color(fn (string $state) => $state === 'closed' ? 'gray' : 'success'),
            ])
            ->defaultSort('academic_year', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->label('สถานะ')
                    ->options(['open' => 'เปิดอยู่', 'closed' => 'ห้องเก่า'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('closed_at'),
                        'closed' => $query->whereNotNull('closed_at'),
                        default => $query,
                    }),
                SelectFilter::make('school_id')->label('โรงเรียน')->relationship('school', 'name')
                    ->visible(fn () => self::admin()?->school_id === null),
            ])
            ->recordActions([
                Action::make('close')
                    ->label('ปิดห้อง')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('warning')
                    ->visible(fn (Classroom $record) => ! $record->isClosed())
                    ->authorize('close')
                    ->requiresConfirmation()
                    ->modalHeading('ปิดห้อง (ย้ายไปห้องเก่า)')
                    ->modalDescription(fn (Classroom $record) => "ห้อง {$record->name} จะอ่านได้อย่างเดียว ดูและส่งออกผลได้เหมือนเดิม แต่สั่งงาน สแกน หรือแก้คะแนนไม่ได้ นักเรียนยังเข้าสู่ระบบด้วยรหัสห้องนี้ได้")
                    ->action(function (Classroom $record) {
                        app(ClassroomLifecycle::class)->close($record, self::admin());
                        Notification::make()->title("ปิดห้อง {$record->name} แล้ว")->success()->send();
                    }),
                Action::make('reopen')
                    ->label('เปิดห้องอีกครั้ง')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Classroom $record) => $record->isClosed())
                    ->authorize('reopen')
                    ->requiresConfirmation()
                    ->modalHeading('เปิดห้องอีกครั้ง')
                    ->modalDescription(fn (Classroom $record) => "ห้อง {$record->name} จะกลับไปอยู่ในรายการห้องเรียนและแก้ไขได้อีกครั้ง")
                    ->action(function (Classroom $record) {
                        app(ClassroomLifecycle::class)->reopen($record);
                        Notification::make()->title("เปิดห้อง {$record->name} อีกครั้งแล้ว")->success()->send();
                    }),
                Action::make('delete')
                    ->label('ลบห้อง')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalHeading('ลบห้องทั้งห้อง')
                    ->modalDescription(fn (Classroom $record) => "ลบห้อง {$record->name} พร้อมการบ้านที่ยังไม่มีงานส่ง รายชื่อ และการผูกรายวิชา บัญชีนักเรียนยังอยู่ ลบได้เฉพาะห้องที่ยังไม่มีงานส่ง คะแนน หรือการประกาศเกรด")
                    ->modalSubmitActionLabel('ลบห้อง')
                    ->action(function (Classroom $record) {
                        $name = $record->name;
                        try {
                            app(ClassroomLifecycle::class)->delete($record);
                        } catch (ApiException $e) {
                            Notification::make()
                                ->title('ลบห้องไม่ได้')
                                ->body(self::blockersText($e->extra['counts'] ?? []).' ใช้ "ปิดห้อง" แทน')
                                ->danger()
                                ->send();

                            return;
                        }
                        Notification::make()->title("ลบห้อง {$name} แล้ว")->success()->send();
                    }),
            ]);
    }

    /** "งานที่ส่ง 3, คะแนนในสมุดคะแนน 12" from the counts of classroom_has_data. */
    public static function blockersText(array $counts): string
    {
        $parts = [];
        foreach (self::COUNT_LABELS as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = "{$label} {$counts[$key]}";
            }
        }

        return 'ห้องนี้มี'.implode(', ', $parts);
    }

    public static function getEloquentQuery(): Builder
    {
        $schoolId = self::admin()?->school_id;

        return parent::getEloquentQuery()
            ->with(['school', 'teacher'])
            ->when($schoolId !== null, fn (Builder $q) => $q->where('school_id', $schoolId));
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::getAuthorizationResponse('adminViewAny');
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::getAuthorizationResponse('adminView', $record);
    }

    /** Classrooms are created and edited by their homeroom teacher in the app. */
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClassrooms::route('/'),
        ];
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
