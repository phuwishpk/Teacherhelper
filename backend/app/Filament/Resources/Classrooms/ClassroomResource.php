<?php

namespace App\Filament\Resources\Classrooms;

use App\Domain\Classrooms\ClassroomLifecycle;
use App\Domain\Classrooms\CourseRequests;
use App\Exceptions\ApiException;
use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\Course;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * DESIGN §24.6, §24.7, §24.8, §24.13: the admin of a school closes
 * ("ห้องเก่า"), reopens and deletes its classrooms with the same
 * ClassroomLifecycle as the app, binds a subject teacher's course directly
 * ("เพิ่มครูประจำวิชา", CourseRequests::assignByAdmin) and reads the
 * classroom's course requests. Classrooms are created and edited by their homeroom teacher in the
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
                TextColumn::make('subject_teachers')
                    ->label('ครูประจำวิชา')
                    ->state(fn (Classroom $record) => self::subjectTeachers($record))
                    ->listWithLineBreaks()
                    ->placeholder('-'),
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
                Action::make('assignCourse')
                    ->label('เพิ่มครูประจำวิชา')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn (Classroom $record) => ! $record->isClosed())
                    ->authorize('assignCourses')
                    ->modalHeading(fn (Classroom $record) => "เพิ่มครูประจำวิชาของห้อง {$record->name}")
                    ->modalDescription('เลือกรายวิชาของครูในโรงเรียน ครูเจ้าของรายวิชาจะสั่งงานและสอบในห้องนี้ได้ทันที เห็นเฉพาะผลของรายวิชาตัวเอง และแก้รายชื่อนักเรียนไม่ได้')
                    ->modalSubmitActionLabel('เพิ่ม')
                    ->schema(fn (Classroom $record) => [
                        Select::make('course_id')
                            ->label('รายวิชา')
                            ->options(self::courseOptions($record))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Classroom $record, array $data) {
                        $course = Course::query()->where('school_id', $record->school_id)->find((int) ($data['course_id'] ?? 0));
                        if ($course === null) {
                            Notification::make()->title('ไม่พบรายวิชานี้ในโรงเรียนของห้อง')->danger()->send();

                            return;
                        }
                        try {
                            app(CourseRequests::class)->assignByAdmin(self::admin(), $record, $course);
                        } catch (ApiException $e) {
                            Notification::make()->title('เพิ่มไม่ได้')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title("ผูกรายวิชา {$course->code} กับห้อง {$record->name} แล้ว")->success()->send();
                    }),
                Action::make('courseRequests')
                    ->label('คำขอผูกรายวิชา')
                    ->icon(Heroicon::OutlinedInbox)
                    ->authorize('adminView')
                    ->modalHeading(fn (Classroom $record) => "คำขอผูกรายวิชาของห้อง {$record->name}")
                    ->modalContent(fn (Classroom $record) => self::requestsHtml($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('ปิด'),
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

    public const STATUS_LABELS = [
        ClassroomCourseRequest::STATUS_PENDING => 'รอครูประจำชั้น',
        ClassroomCourseRequest::STATUS_APPROVED => 'อนุมัติแล้ว',
        ClassroomCourseRequest::STATUS_DECLINED => 'ไม่อนุมัติ',
        ClassroomCourseRequest::STATUS_CANCELLED => 'ยกเลิก',
    ];

    /**
     * "ค15101 คณิตศาสตร์ (ครูสมศรี)" for each course of another teacher bound to the classroom.
     *
     * @return list<string>
     */
    public static function subjectTeachers(Classroom $classroom): array
    {
        return $classroom->courses
            ->filter(fn (Course $c) => $c->created_by !== $classroom->teacher_id)
            ->sortBy('code')
            ->map(fn (Course $c) => "{$c->code} {$c->name} ({$c->creator?->name})")
            ->values()
            ->all();
    }

    /**
     * The school's courses not bound to the classroom yet, newest year first.
     *
     * @return array<int, string>
     */
    public static function courseOptions(Classroom $classroom): array
    {
        return Course::query()
            ->where('school_id', $classroom->school_id)
            ->whereDoesntHave('classrooms', fn (Builder $q) => $q->whereKey($classroom->id))
            ->with('creator:id,name')
            ->orderByDesc('academic_year')
            ->orderBy('code')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Course $c) => [$c->id => "{$c->code} {$c->name} · {$c->creator?->name} · ปี {$c->academic_year}"])
            ->all();
    }

    /** The classroom's course requests, newest first, as a small table for the modal. */
    public static function requestsHtml(Classroom $classroom): HtmlString
    {
        $rows = ClassroomCourseRequest::query()
            ->where('classroom_id', $classroom->id)
            ->with(['course', 'requester:id,name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        if ($rows->isEmpty()) {
            return new HtmlString('<p>ยังไม่มีคำขอ</p>');
        }
        $html = '<table class="w-full text-sm"><thead><tr><th class="text-start">รายวิชา</th><th class="text-start">ผู้ขอ</th><th class="text-start">สถานะ</th><th class="text-start">วันที่</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $origin = $row->origin === ClassroomCourseRequest::ORIGIN_ADMIN ? ' (admin กำหนด)' : '';
            $html .= '<tr><td>'.e(trim("{$row->course?->code} {$row->course?->name}")).'</td>'
                .'<td>'.e((string) $row->requester?->name).e($origin).'</td>'
                .'<td>'.e(self::STATUS_LABELS[$row->status] ?? $row->status).'</td>'
                .'<td>'.e((string) $row->created_at?->setTimezone('Asia/Bangkok')->format('d/m/Y H:i')).'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
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
            ->with(['school', 'teacher', 'courses.creator:id,name'])
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
