<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §29.14: the audit log, read-only. Today it holds "เข้าใช้แทน":
 * which admin opened the app as whom, and when the session ended.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'บันทึกการใช้งาน';

    protected static ?string $pluralModelLabel = 'บันทึกการใช้งาน';

    protected static ?string $navigationLabel = 'บันทึกการใช้งาน';

    protected static ?int $navigationSort = 45;

    public const ACTION_LABELS = [
        AuditLog::IMPERSONATE_START => 'เข้าใช้แทน',
        AuditLog::IMPERSONATE_STOP => 'ออกจากการเข้าใช้แทน',
        AuditLog::ACCOUNT_DELETE => 'ลบบัญชี',
        AuditLog::ACCOUNT_RESTORE => 'กู้คืนบัญชี',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i:s', 'Asia/Bangkok')->sortable(),
                TextColumn::make('actor.name')->label('ผู้ดูแลระบบ')->searchable(),
                TextColumn::make('action')
                    ->label('การกระทำ')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::ACTION_LABELS[$state] ?? $state),
                TextColumn::make('target.name')->label('ในฐานะ')->placeholder('—')->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')->label('การกระทำ')->options(self::ACTION_LABELS),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['actor:id,name', 'target:id,name']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
