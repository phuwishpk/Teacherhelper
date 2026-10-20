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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The teachers' workspaces (DESIGN §29.13.1): one `schools` row per teacher,
 * created by the API and never chosen anywhere. The panel keeps the
 * consent/retention settings of §8.1 per workspace. The Google sign-in
 * settings of §24.9.2 are gone (no per-school domains or switches).
 *
 * teacher_join_code is NOT NULL UNIQUE from the days teachers typed it; the
 * form keeps it filled with a random default inside a collapsed section and
 * the table never shows it.
 */
class SchoolResource extends Resource
{
    protected static ?string $model = School::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $modelLabel = 'พื้นที่ของครู';

    protected static ?string $pluralModelLabel = 'พื้นที่ของครู';

    protected static ?string $navigationLabel = 'พื้นที่ของครู (ภายใน)';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อพื้นที่ (ชื่อโรงเรียนที่ครูกรอก หรือชื่อครู)')
                    ->required()
                    ->maxLength(255),
                Toggle::make('allow_training_data')
                    ->label('ยินยอมให้ใช้ลายมือที่ครูตรวจแล้วไปเทรนโมเดล (allow_training_data)')
                    ->default(false),
                Toggle::make('assistant_student_data')
                    ->label('ให้ครูถามผู้ช่วย Krucheck เรื่องคะแนนนักเรียนได้ (assistant_student_data)')
                    ->helperText('ส่งคะแนนแบบไม่ระบุตัวไปที่ Gemini ด้วย key ของครู ปิดแล้วผู้ช่วยตอบได้แค่เรื่องการใช้ระบบ (DESIGN §37.8)')
                    ->default(true),
                DatePicker::make('crop_retention_until')
                    ->label('เก็บภาพ crop ถึงวันที่ (crop_retention_until)')
                    ->helperText('ว่าง = ยังไม่กำหนด ภาพ crop จะถูกลบหลังวันนี้ (DESIGN §7.3)')
                    ->native(false)
                    ->displayFormat('d/m/Y'),
                Section::make('รหัสสมัครครูสำหรับแอปรุ่นเก่า (ไม่จำเป็น)')
                    ->description('ไม่มีใครใช้รหัสนี้แล้ว: พื้นที่ของครูถูกสร้างให้เองตอนสมัคร คอลัมน์ยังอยู่เพราะเป็น NOT NULL UNIQUE ระบบสุ่มให้ ไม่ต้องแก้')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('teacher_join_code')
                            ->label('รหัสสมัครครู (teacher_join_code)')
                            ->helperText('8 ตัว ใช้ได้เฉพาะ A–Z และ 0–9 (ระบบแปลงเป็นตัวพิมพ์ใหญ่ให้) ระบบสุ่มให้แล้ว ไม่ต้องแก้')
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
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อพื้นที่ (ชื่อโรงเรียนที่ครูกรอก หรือชื่อครู)')->searchable()->sortable(),
                TextColumn::make('teachers_count')
                    ->label('ครู')
                    ->counts('teachers')
                    ->sortable(),
                TextColumn::make('classrooms_count')
                    ->label('ห้องเรียน')
                    ->counts('classrooms'),
                IconColumn::make('allow_training_data')->label('ยินยอมเทรน')->boolean(),
                IconColumn::make('assistant_student_data')->label('ผู้ช่วยดูคะแนน')->boolean()->toggleable(),
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

    /**
     * The domains as stored: trimmed, lower case, without a leading `@`, no
     * duplicates; null when empty (= any domain).
     *
     * @param  array<int, mixed>|null  $state
     * @return list<string>|null
     */
    public static function normalizeDomains(?array $state): ?array
    {
        $domains = array_values(array_unique(array_filter(
            array_map(fn ($d) => ltrim(mb_strtolower(trim((string) $d)), '@'), $state ?? []),
            fn (string $d) => $d !== '',
        )));

        return $domains === [] ? null : $domains;
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
