<?php

namespace App\Filament\Resources\Skills;

use App\Filament\Resources\Skills\Pages\ManageSkills;
use App\Models\Skill;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §2.3 / §7.5: skills are a fixed list imported from CSV. This resource
 * is read-only (SkillPolicy denies create/update/delete); the import lives in
 * ManageSkills as a header action.
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject.code')->label('วิชา')->badge()->sortable(),
                TextColumn::make('code')->label('รหัส')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label('ตัวชี้วัด')->searchable()->wrap()->limit(160),
                TextColumn::make('grade_level')->label('ชั้น')->formatStateUsing(fn (?int $state) => self::gradeLabel($state))->sortable(),
                TextColumn::make('parent.code')->label('ภายใต้')->placeholder('—')->fontFamily('mono'),
                TextColumn::make('school.name')->label('ของโรงเรียน')->placeholder('หลักสูตรแกนกลาง'),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('subject_id')->label('วิชา')->relationship('subject', 'name'),
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
            ->recordActions([]);
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
