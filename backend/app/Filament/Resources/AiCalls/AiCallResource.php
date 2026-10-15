<?php

namespace App\Filament\Resources\AiCalls;

use App\Filament\Resources\AiCalls\Pages\ManageAiCalls;
use App\Models\AiCall;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §7.5: cost and errors of the AI from `ai_calls` (§8.4). Read-only:
 * the rows are written by GeminiGateway and hold no prompt, image or key.
 * Token sums at the bottom of the (filtered) table give the cost picture.
 */
class AiCallResource extends Resource
{
    protected static ?string $model = AiCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $modelLabel = 'การเรียก AI';

    protected static ?string $pluralModelLabel = 'การเรียก AI (ai_calls)';

    protected static ?string $navigationLabel = 'การเรียก AI';

    protected static ?int $navigationSort = 50;

    public const PURPOSE_LABELS = [
        'extract' => 'สกัดข้อมูล',
        'rubric_draft' => 'ร่าง rubric',
        'explanation' => 'คำอธิบาย',
        'practice_gen' => 'สร้างแบบฝึก',
    ];

    public const STATUS_LABELS = [
        AiCall::STATUS_OK => 'สำเร็จ',
        AiCall::STATUS_ERROR => 'ผิดพลาด',
        AiCall::STATUS_INVALID_OUTPUT => 'output ไม่ผ่าน schema',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('เวลา')
                    ->dateTime('d/m/Y H:i:s', 'Asia/Bangkok')
                    ->sortable(),
                TextColumn::make('purpose')
                    ->label('งาน')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::PURPOSE_LABELS[$state] ?? $state),
                TextColumn::make('status')
                    ->label('ผล')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        AiCall::STATUS_OK => 'success',
                        AiCall::STATUS_INVALID_OUTPUT => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('model')->label('รุ่น')->fontFamily('mono')->toggleable(),
                TextColumn::make('prompt_version')->label('prompt')->fontFamily('mono')->toggleable(),
                TextColumn::make('key_source')
                    ->label('key')
                    ->formatStateUsing(fn (string $state) => $state === AiCall::KEY_SOURCE_TEACHER ? 'ครู' : 'server'),
                TextColumn::make('input_tokens')->label('token เข้า')->numeric()->summarize(Sum::make()->label('รวม')),
                TextColumn::make('output_tokens')->label('token ออก')->numeric()->summarize(Sum::make()->label('รวม')),
                TextColumn::make('latency_ms')->label('ms')->numeric()->toggleable(),
                TextColumn::make('response_id')->label('response')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('question_id')->label('question')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('skill_id')->label('skill')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error')
                    ->label('ข้อผิดพลาด')
                    ->placeholder('—')
                    ->limit(60)
                    ->tooltip(fn (?string $state) => $state)
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('purpose')->label('งาน')->options(self::PURPOSE_LABELS),
                SelectFilter::make('status')->label('ผล')->options(self::STATUS_LABELS),
                SelectFilter::make('key_source')->label('key')->options([AiCall::KEY_SOURCE_TEACHER => 'ครู', AiCall::KEY_SOURCE_SERVER => 'server']),
                Filter::make('today')
                    ->label('วันนี้')
                    ->query(fn (Builder $query) => $query->where('created_at', '>=', now('Asia/Bangkok')->startOfDay()->utc())),
            ])
            ->recordActions([])
            ->paginated([25, 50, 100]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiCalls::route('/'),
        ];
    }
}
