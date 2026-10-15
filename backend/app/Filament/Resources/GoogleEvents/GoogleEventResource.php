<?php

namespace App\Filament\Resources\GoogleEvents;

use App\Domain\Google\GoogleEventLabels;
use App\Filament\Resources\GoogleEvents\Pages\ManageGoogleEvents;
use App\Models\GoogleEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §32: "ปัญหาการเชื่อม Google", read-only. Every sign-in, link or
 * Classroom connection with Google that did not simply succeed, with the
 * account (or the Google e-mail when it is linked to nobody), a Thai
 * explanation and what to do. Rows go after 30 days.
 */
class GoogleEventResource extends Resource
{
    protected static ?string $model = GoogleEvent::class;

    protected static ?string $slug = 'google-events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'ปัญหาการเชื่อม Google';

    protected static ?string $pluralModelLabel = 'ปัญหาการเชื่อม Google';

    protected static ?string $navigationLabel = 'ปัญหาการเชื่อม Google';

    protected static ?int $navigationSort = 44;

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::columns(withUser: true))
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('problems')
                    ->label('เฉพาะที่มีปัญหา')
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNotIn('result', GoogleEventLabels::OK_RESULTS)),
                Filter::make('unlinked')
                    ->label('บัญชี Google ที่ยังไม่ผูกกับใคร')
                    ->query(fn (Builder $query) => $query->whereNull('user_id')),
                SelectFilter::make('flow')->label('ขั้นตอน')->options(GoogleEventLabels::FLOWS),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * Shared with the account page (UserResource::infolist).
     *
     * @return list<TextColumn>
     */
    public static function columns(bool $withUser): array
    {
        return array_values(array_filter([
            TextColumn::make('created_at')->label('เวลา')->dateTime('d/m/Y H:i', 'Asia/Bangkok')->sortable(),
            $withUser ? TextColumn::make('user.name')->label('บัญชี')->placeholder('ยังไม่ผูกกับใคร')->searchable() : null,
            TextColumn::make('email')->label('อีเมล Google')->placeholder('—')->searchable()->copyable(),
            TextColumn::make('flow')
                ->label('ขั้นตอน')
                ->badge()
                ->color('gray')
                ->formatStateUsing(fn (string $state) => GoogleEventLabels::FLOWS[$state] ?? $state),
            TextColumn::make('result')
                ->label('เกิดอะไรขึ้น')
                ->formatStateUsing(fn (GoogleEvent $record) => GoogleEventLabels::describe($record)['title'])
                ->description(fn (GoogleEvent $record) => GoogleEventLabels::describe($record)['advice'])
                ->color(fn (GoogleEvent $record) => GoogleEventLabels::isProblem($record) ? 'danger' : 'gray')
                ->wrap(),
        ]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user:id,name');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGoogleEvents::route('/'),
        ];
    }
}
