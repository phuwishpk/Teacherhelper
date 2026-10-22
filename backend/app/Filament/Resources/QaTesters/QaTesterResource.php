<?php

namespace App\Filament\Resources\QaTesters;

use App\Domain\Qa\QaCatalog;
use App\Filament\Resources\QaTesters\Pages\ManageQaTesters;
use App\Models\QaTester;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * "ผู้ทดสอบระบบ" (DESIGN §39): the accounts of the Krucheck Test Lab at /qa.
 * Separate from Krucheck users: they sign in at /qa only and see no school
 * data. Deleting one deletes their results and SUS answers.
 */
class QaTesterResource extends Resource
{
    protected static ?string $model = QaTester::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $modelLabel = 'ผู้ทดสอบระบบ';

    protected static ?string $pluralModelLabel = 'ผู้ทดสอบระบบ';

    protected static ?string $navigationLabel = 'ผู้ทดสอบระบบ (Test Lab)';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('ชื่อ')->required()->maxLength(100),
            TextInput::make('email')->label('อีเมล (ใช้เข้าสู่ระบบที่ /qa)')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label('รหัสผ่าน')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText('อย่างน้อย 8 ตัว ตอนแก้ไขเว้นว่างไว้ = ใช้รหัสเดิม'),
            Toggle::make('is_lead')
                ->label('หัวหน้าทดสอบ')
                ->helperText('อ่านผล CI จาก GitHub ใหม่ได้ทันทีในหน้าภาพรวม'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $manual = count(QaCatalog::ofKinds([QaCatalog::MANUAL, ...QaCatalog::USABILITY_KINDS]));

        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อ')->searchable()->sortable(),
                TextColumn::make('email')->label('อีเมล')->searchable(),
                IconColumn::make('is_lead')->label('หัวหน้า')->boolean(),
                TextColumn::make('results_count')
                    ->label('ผลที่กรอก')
                    ->counts(['results' => fn ($q) => $q->whereNotNull('status')])
                    ->formatStateUsing(fn ($state) => "{$state}/{$manual}"),
                TextColumn::make('sus.score')->label('SUS')->placeholder('–'),
                TextColumn::make('created_at')->label('สร้างเมื่อ')->dateTime('d/m/Y H:i', 'Asia/Bangkok'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('ผลการทดสอบและแบบประเมิน SUS ของคนนี้จะถูกลบด้วย'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageQaTesters::route('/'),
        ];
    }
}
