<?php

namespace App\Filament\Resources\Schools;

use App\Filament\Resources\Schools\Pages\CreateSchool;
use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Models\School;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * DESIGN §7.5: schools, their teacher_join_code (what teachers type at
 * sign-up) and the consent/retention settings of §8.1.
 */
class SchoolResource extends Resource
{
    protected static ?string $model = School::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $modelLabel = 'โรงเรียน';

    protected static ?string $pluralModelLabel = 'โรงเรียน';

    protected static ?string $navigationLabel = 'โรงเรียน';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อโรงเรียน')
                    ->required()
                    ->maxLength(255),
                TextInput::make('teacher_join_code')
                    ->label('รหัสสมัครสำหรับครู (teacher_join_code)')
                    ->helperText('ครูกรอกรหัสนี้ตอนสมัครในแอป 8 ตัว ใช้ได้เฉพาะ A–Z และ 0–9 (ระบบแปลงเป็นตัวพิมพ์ใหญ่ให้)')
                    ->default(fn () => School::randomJoinCode())
                    ->required()
                    // Uppercase before validation: MariaDB's default collation makes
                    // the UNIQUE index case-insensitive, so "abcd2345" next to an
                    // existing "ABCD2345" must fail here with the Thai message, not
                    // on the index with a 500. The stored value is uppercased too.
                    ->mutateStateForValidationUsing(fn (?string $state) => self::normalizeJoinCode($state))
                    ->length(8)
                    ->rule('regex:/^[A-Z0-9]{8}$/')
                    ->validationMessages(['regex' => 'ใช้ได้เฉพาะ A–Z และ 0–9 จำนวน 8 ตัว'])
                    ->unique(ignoreRecord: true)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('teacher_join_code', self::normalizeJoinCode($state)))
                    ->dehydrateStateUsing(fn (?string $state) => self::normalizeJoinCode($state))
                    ->suffixAction(
                        Action::make('regenerate')
                            ->label('สุ่มใหม่')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->action(fn (Set $set) => $set('teacher_join_code', School::randomJoinCode())),
                    ),
                Toggle::make('allow_training_data')
                    ->label('ยินยอมให้ใช้ลายมือที่ครูตรวจแล้วไปเทรนโมเดล (allow_training_data)')
                    ->default(false),
                DatePicker::make('crop_retention_until')
                    ->label('เก็บภาพ crop ถึงวันที่ (crop_retention_until)')
                    ->helperText('ว่าง = ยังไม่กำหนด ภาพ crop จะถูกลบหลังวันนี้ (DESIGN §7.3)')
                    ->native(false)
                    ->displayFormat('d/m/Y'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อโรงเรียน')->searchable()->sortable(),
                TextColumn::make('teacher_join_code')
                    ->label('รหัสสมัครครู')
                    ->copyable()
                    ->copyMessage('คัดลอกแล้ว')
                    ->fontFamily('mono'),
                TextColumn::make('teachers_count')
                    ->label('ครู')
                    ->counts('teachers')
                    ->sortable(),
                TextColumn::make('classrooms_count')
                    ->label('ห้องเรียน')
                    ->counts('classrooms'),
                IconColumn::make('allow_training_data')->label('ยินยอมเทรน')->boolean(),
                TextColumn::make('crop_retention_until')
                    ->label('เก็บภาพถึง')
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** What is stored and compared: trimmed, uppercase (School::randomJoinCode() format). */
    public static function normalizeJoinCode(?string $state): string
    {
        return strtoupper(trim((string) $state));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchools::route('/'),
            'create' => CreateSchool::route('/create'),
            'edit' => EditSchool::route('/{record}/edit'),
        ];
    }
}
