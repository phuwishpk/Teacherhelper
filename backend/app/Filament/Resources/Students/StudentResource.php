<?php

namespace App\Filament\Resources\Students;

use App\Filament\Resources\Students\Pages\DuplicateStudents;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * DESIGN §24.13: the students of the school in Filament, searchable by name
 * or student code, with "รวมบัญชีนักเรียน" (the row is the account kept)
 * and the page of likely duplicates. Students are created and edited by
 * their homeroom teachers in the app. An admin of a school sees that
 * school's students; a system admin (school_id null) sees all.
 */
class StudentResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'students';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'นักเรียน';

    protected static ?string $pluralModelLabel = 'นักเรียน';

    protected static ?string $navigationLabel = 'นักเรียน';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อ')->searchable()->sortable(),
                TextColumn::make('student_code')->label('เลขประจำตัว')->searchable()->placeholder('—'),
                TextColumn::make('classrooms.name')->label('ห้อง')->badge()->placeholder('—'),
                TextColumn::make('school.name')->label('โรงเรียน')->sortable()
                    ->visible(fn () => self::admin()?->school_id === null),
                TextColumn::make('status')
                    ->label('สถานะ')
                    ->badge()
                    ->state(fn (User $record) => $record->isMerged() ? 'merged' : $record->status)
                    ->formatStateUsing(fn (string $state, User $record) => match ($state) {
                        'merged' => 'ถูกรวมเข้า '.($record->mergedInto?->name ?? '#'.$record->merged_into_id),
                        User::STATUS_ACTIVE => 'ใช้งานได้',
                        default => 'ระงับ',
                    })
                    ->color(fn (string $state) => match ($state) {
                        User::STATUS_ACTIVE => 'success',
                        'merged' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('state')
                    ->label('สถานะ')
                    ->options(['active' => 'ใช้งานได้', 'merged' => 'ถูกรวมแล้ว'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('merged_into_id')->where('status', User::STATUS_ACTIVE),
                        'merged' => $query->whereNotNull('merged_into_id'),
                        default => $query,
                    }),
                SelectFilter::make('school_id')->label('โรงเรียน')->relationship('school', 'name')
                    ->visible(fn () => self::admin()?->school_id === null),
            ])
            ->recordActions([
                Action::make('merge')
                    ->label('รวมบัญชี')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->visible(fn (User $record) => $record->isActive() && ! $record->isMerged())
                    ->authorize(fn (User $record) => Gate::allows('mergeStudents', [$record, $record]))
                    ->modalHeading(fn (User $record) => "รวมบัญชีอื่นเข้าบัญชีของ {$record->name}")
                    ->modalDescription('บัญชีในแถวนี้คือบัญชีที่เก็บไว้ เลือกบัญชีที่ซ้ำเพื่อรวมเข้ามา')
                    ->modalSubmitActionLabel('รวมบัญชี')
                    ->modalWidth('3xl')
                    ->schema(fn (User $record) => [
                        Select::make('merge_id')
                            ->label('บัญชีที่จะรวม (ถูกปิดหลังรวม)')
                            ->placeholder('ค้นชื่อหรือเลขประจำตัว')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => StudentMergeSupport::searchOptions($record, $search))
                            ->getOptionLabelUsing(fn ($value) => StudentMergeSupport::student($value)?->name)
                            ->required()
                            ->live(),
                        ...StudentMergeSupport::confirmSchema(
                            fn () => $record,
                            fn (Get $get) => StudentMergeSupport::student($get('merge_id')),
                        ),
                    ])
                    ->action(fn (User $record, array $data, Action $action) => StudentMergeSupport::merge($record, StudentMergeSupport::student($data['merge_id'] ?? null), $action)),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $schoolId = self::admin()?->school_id;

        return parent::getEloquentQuery()
            ->where('role', User::ROLE_STUDENT)
            ->with(['school', 'classrooms', 'mergedInto'])
            ->when($schoolId !== null, fn (Builder $q) => $q->where('school_id', $schoolId));
    }

    /** Students are created and edited by their homeroom teachers in the app. */
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
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
            'index' => ManageStudents::route('/'),
            'duplicates' => DuplicateStudents::route('/duplicates'),
        ];
    }

    public static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
