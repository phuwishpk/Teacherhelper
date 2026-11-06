<?php

namespace App\Filament\Resources\Users;

use App\Domain\Google\GoogleEventLabels;
use App\Domain\Google\GoogleScopes;
use App\Models\GoogleEvent;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The page of one account (DESIGN §32), opened from a row of the users page
 * or the students page: who it is, its Google sign-in link, its Google
 * Classroom connection (teachers) and the Google events of the last 30 days.
 */
final class AccountInfolist
{
    public static function action(): ViewAction
    {
        return ViewAction::make()
            ->label('ดูรายละเอียด')
            ->modalHeading(fn (User $record) => $record->name)
            ->modalWidth('4xl')
            ->schema(fn (Schema $schema) => self::schema($schema));
    }

    public static function schema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('บัญชี')
                ->columns(2)
                ->schema([
                    TextEntry::make('role')->label('บทบาท')->formatStateUsing(fn (string $state) => UserResource::ROLE_LABELS[$state] ?? $state),
                    TextEntry::make('status')
                        ->label('สถานะ')
                        ->badge()
                        ->state(fn (User $record) => match (true) {
                            $record->isMerged() => 'ถูกรวมเข้า '.($record->mergedInto?->name ?? '#'.$record->merged_into_id),
                            $record->isDeleted() => AccountDeletionActions::statusLabel($record),
                            default => UserResource::STATUS_LABELS[$record->status] ?? $record->status,
                        }),
                    TextEntry::make('email')->label('อีเมล')->placeholder('—')->copyable(),
                    TextEntry::make('username')->label('ชื่อผู้ใช้')->placeholder('—')->visible(fn (User $record) => $record->isStudent()),
                    TextEntry::make('student_code')->label('เลขประจำตัว')->placeholder('—')->visible(fn (User $record) => $record->isStudent()),
                    TextEntry::make('school.name')->label('พื้นที่ของครู')->placeholder('—'),
                    TextEntry::make('created_at')->label('สร้างเมื่อ')->dateTime('d/m/Y H:i', 'Asia/Bangkok'),
                    TextEntry::make('rooms')
                        ->label('ห้องเรียน')
                        ->badge()
                        ->placeholder('ไม่มี')
                        ->state(fn (User $record) => ($record->isStudent() ? $record->classrooms : $record->taughtClassrooms)->pluck('name')->all()),
                ]),
            Section::make('เข้าสู่ระบบด้วย Google')
                ->columns(2)
                ->schema([
                    TextEntry::make('google_signin')
                        ->label('การเชื่อม')
                        ->badge()
                        ->color(fn (User $record) => $record->googleIdentity !== null ? 'success' : 'gray')
                        ->state(fn (User $record) => $record->googleIdentity !== null ? 'เชื่อมแล้ว' : 'ยังไม่เชื่อม'),
                    TextEntry::make('googleIdentity.email')->label('บัญชี Google')->placeholder('—')->copyable(),
                    TextEntry::make('googleIdentity.linked_via')
                        ->label('เชื่อมด้วย')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => GoogleEventLabels::VIA[$state] ?? $state),
                    TextEntry::make('googleIdentity.linked_at')->label('เชื่อมเมื่อ')->placeholder('—')->dateTime('d/m/Y H:i', 'Asia/Bangkok'),
                    TextEntry::make('googleIdentity.last_login_at')->label('เข้าสู่ระบบด้วย Google ล่าสุด')->placeholder('—')->dateTime('d/m/Y H:i', 'Asia/Bangkok'),
                ]),
            Section::make('Google Classroom')
                ->visible(fn (User $record) => $record->isTeacher())
                ->columns(2)
                ->schema([
                    TextEntry::make('google_classroom')
                        ->label('สถานะ')
                        ->badge()
                        ->state(fn (User $record) => match (true) {
                            $record->googleAccount === null => 'ยังไม่เชื่อม',
                            $record->googleAccount->needsReconnect() => 'ต้องเชื่อมใหม่',
                            default => 'ใช้งานได้',
                        })
                        ->color(fn (string $state) => match ($state) {
                            'ใช้งานได้' => 'success',
                            'ต้องเชื่อมใหม่' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('googleAccount.email')->label('บัญชี Google')->placeholder('—')->copyable(),
                    TextEntry::make('googleAccount.connected_at')->label('เชื่อมเมื่อ')->placeholder('—')->dateTime('d/m/Y H:i', 'Asia/Bangkok'),
                    TextEntry::make('google_classroom_reason')
                        ->label('เหตุผล')
                        ->placeholder('—')
                        ->state(fn (User $record) => $record->googleAccount?->reconnectMessage()),
                    TextEntry::make('google_classroom_missing')
                        ->label('สิทธิ์ที่ยังไม่ได้')
                        ->badge()
                        ->color('danger')
                        ->placeholder('ครบ')
                        ->state(fn (User $record) => $record->googleAccount === null
                            ? []
                            : array_map(GoogleScopes::label(...), GoogleScopes::missing($record->googleAccount->scopeList())))
                        ->visible(fn (User $record) => $record->googleAccount !== null),
                ]),
            Section::make('เหตุการณ์ของ Google 30 วันล่าสุด')
                ->description('ปัญหาที่เกิดในหน้าของ Google เอง (เช่น "Access blocked") ไม่ถูกส่งกลับมาที่ระบบ จึงไม่อยู่ในรายการนี้')
                ->schema([
                    RepeatableEntry::make('recentGoogleEvents')
                        ->hiddenLabel()
                        ->placeholder('ไม่มี')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('created_at')->hiddenLabel()->dateTime('d/m/Y H:i', 'Asia/Bangkok'),
                            TextEntry::make('flow')->hiddenLabel()->badge()->color('gray')->formatStateUsing(fn (string $state) => GoogleEventLabels::FLOWS[$state] ?? $state),
                            TextEntry::make('result')
                                ->hiddenLabel()
                                ->columnSpan(2)
                                ->formatStateUsing(fn (GoogleEvent $record) => GoogleEventLabels::describe($record)['title'])
                                ->helperText(fn (GoogleEvent $record) => GoogleEventLabels::describe($record)['advice'])
                                ->color(fn (GoogleEvent $record) => GoogleEventLabels::isProblem($record) ? 'danger' : null),
                        ]),
                ]),
        ]);
    }
}
