<?php

namespace App\Filament\Resources\Skills;

use App\Filament\Resources\Skills\Pages\ManageSkills;
use App\Models\Skill;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §2.3 / §7.5 / §20.2: the curriculum is imported from CSV (the
 * import lives in ManageSkills as a header action) and is read-only here.
 * A school's own rows (an admin's CSV, or indicators a teacher added,
 * "ครูเพิ่มเอง") can be renamed by an admin at any time (SkillPolicy).
 */
class SkillResource extends Resource
{
    protected static ?string $model = Skill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'ทักษะ';

    protected static ?string $pluralModelLabel = 'ทักษะ (ตัวชี้วัด)';

    protected static ?string $navigationLabel = 'ทักษะ (ตัวชี้วัด)';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'code';

    public const LEVEL_LABELS = [
        Skill::LEVEL_STRAND => 'สาระ',
        Skill::LEVEL_STANDARD => 'มาตรฐาน',
        Skill::LEVEL_INDICATOR => 'ตัวชี้วัด',
        Skill::LEVEL_SUB_INDICATOR => 'ทักษะย่อย',
    ];

    public const SOURCE_LABELS = [
        Skill::SOURCE_CURRICULUM => 'หลักสูตรแกนกลาง',
        Skill::SOURCE_SCHOOL_ADMIN => 'admin ของโรงเรียน',
        Skill::SOURCE_TEACHER => Skill::TEACHER_LABEL,
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('รหัส')->required()->maxLength(40)
                // The same rule as a teacher's own codes (TeacherSkills): no DB constraint
                // covers "curriculum or this school", so the form checks it.
                ->rules([fn (?Skill $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    $code = trim((string) $value);
                    $taken = Skill::query()
                        ->visibleToSchool($record?->school_id)
                        ->where('code', $code)
                        ->when($record !== null, fn (Builder $q) => $q->whereKeyNot($record->id))
                        ->pluck('code')
                        ->contains(fn ($found) => (string) $found === $code);
                    if ($taken) {
                        $fail("รหัส \"{$code}\" มีอยู่แล้วในหลักสูตรหรือในโรงเรียน");
                    }
                }]),
            Textarea::make('name')->label('ชื่อ')->required()->rows(3),
            Select::make('grade_level')
                ->label('ชั้น')
                ->options(array_combine(range(1, 12), array_map(fn (int $g) => self::gradeLabel($g), range(1, 12))))
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject.code')->label('วิชา')->badge()->sortable(),
                TextColumn::make('code')->label('รหัส')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label('ตัวชี้วัด')->searchable()->wrap()->limit(160),
                TextColumn::make('grade_level')->label('ชั้น')->formatStateUsing(fn (?int $state) => self::gradeLabel($state))->sortable(),
                TextColumn::make('level')->label('ระดับ')->formatStateUsing(fn (?string $state) => self::LEVEL_LABELS[$state] ?? '—')->sortable(),
                TextColumn::make('parent.code')->label('ภายใต้')->placeholder('—')->fontFamily('mono'),
                TextColumn::make('school.name')->label('ของโรงเรียน')->placeholder('หลักสูตรแกนกลาง'),
                TextColumn::make('source')->label('ที่มา')->badge()->formatStateUsing(fn (?string $state) => self::SOURCE_LABELS[$state] ?? '—'),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('subject_id')->label('วิชา')->relationship('subject', 'name'),
                SelectFilter::make('level')->label('ระดับ')->options(self::LEVEL_LABELS),
                SelectFilter::make('source')->label('ที่มา')->options(self::SOURCE_LABELS),
                SelectFilter::make('grade_level')
                    ->label('ชั้น')
                    ->options(array_combine(range(1, 12), array_map(fn (int $g) => self::gradeLabel($g), range(1, 12)))),
                TernaryFilter::make('school_id')
                    ->label('ที่มา')
                    ->placeholder('ทั้งหมด')
                    ->trueLabel('ทักษะย่อยของโรงเรียน')
                    ->falseLabel('หลักสูตรแกนกลาง')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('school_id'),
                        false: fn (Builder $q) => $q->whereNull('school_id'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['subject', 'parent', 'school']);
    }

    public static function gradeLabel(?int $grade): string
    {
        if ($grade === null) {
            return '—';
        }

        return $grade <= 6 ? 'ป.'.$grade : 'ม.'.($grade - 6);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSkills::route('/'),
        ];
    }
}
