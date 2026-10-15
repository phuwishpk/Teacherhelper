<?php

namespace App\Filament\Resources\Users;

use App\Domain\Auth\Impersonations;
use App\Domain\Web\WebApp;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §7.5: approve or disable teacher accounts. Students are listed here
 * too and may be disabled; their other admin actions are on the student page
 * (StudentResource). "ลบบัญชี" and "กู้คืนบัญชี" (DESIGN §31) are on both pages.
 *
 * One admin level (DESIGN §29.13.1): every active admin sees, approves,
 * disables and edits every account. Approval is the only gate of a
 * password sign-up (§29.1); teachers who sign up with Google are active at
 * once. Workspaces (`schools`) are made by the API, never picked here.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'ผู้ใช้';

    protected static ?string $pluralModelLabel = 'ผู้ใช้';

    protected static ?string $navigationLabel = 'ผู้ใช้และครู';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public const ROLE_LABELS = [
        User::ROLE_ADMIN => 'ผู้ดูแลระบบ',
        User::ROLE_TEACHER => 'ครู',
        User::ROLE_STUDENT => 'นักเรียน',
    ];

    public const STATUS_LABELS = [
        User::STATUS_PENDING => 'รออนุมัติ',
        User::STATUS_ACTIVE => 'ใช้งานได้',
        User::STATUS_DISABLED => 'ระงับ',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อ')
                    ->required()
                    ->maxLength(255),
                Select::make('role')
                    ->label('บทบาท')
                    ->options([
                        User::ROLE_ADMIN => self::ROLE_LABELS[User::ROLE_ADMIN],
                        User::ROLE_TEACHER => self::ROLE_LABELS[User::ROLE_TEACHER],
                    ])
                    ->default(User::ROLE_TEACHER)
                    ->required()
                    ->live()
                    ->disabled(fn (?User $record) => $record?->isStudent() ?? false),
                TextInput::make('email')
                    ->label('อีเมล')
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->required(fn (callable $get) => $get('role') !== User::ROLE_STUDENT),
                Select::make('status')
                    ->label('สถานะ')
                    ->options(self::STATUS_LABELS)
                    ->default(User::STATUS_PENDING)
                    ->required(),
                TextInput::make('password')
                    ->label('รหัสผ่าน')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->maxLength(255)
                    ->required(fn (string $operation, callable $get) => $operation === 'create' && $get('role') !== User::ROLE_STUDENT)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText('เว้นว่างเมื่อแก้ไขถ้าไม่ต้องการเปลี่ยนรหัสผ่าน'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อ')->searchable()->sortable(),
                TextColumn::make('email')->label('อีเมล')->searchable()->placeholder('—'),
                TextColumn::make('role')
                    ->label('บทบาท')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::ROLE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        User::ROLE_ADMIN => 'primary',
                        User::ROLE_TEACHER => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('สถานะ')
                    ->badge()
                    ->state(fn (User $record) => $record->isDeleted() ? 'deleted' : $record->status)
                    ->formatStateUsing(fn (string $state, User $record) => $state === 'deleted'
                        ? AccountDeletionActions::statusLabel($record)
                        : (self::STATUS_LABELS[$state] ?? $state))
                    ->color(fn (string $state) => match ($state) {
                        User::STATUS_ACTIVE => 'success',
                        User::STATUS_PENDING => 'warning',
                        'deleted' => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('google')
                    ->label('Google')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (User $record) => self::googleBadges($record))
                    ->color(fn (string $state) => str_contains($state, 'ต้องเชื่อมใหม่') ? 'danger' : 'success'),
                TextColumn::make('created_at')
                    ->label('สมัครเมื่อ')
                    ->dateTime('d/m/Y H:i', 'Asia/Bangkok')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction('view')
            ->filters([
                SelectFilter::make('role')->label('บทบาท')->options(self::ROLE_LABELS),
                SelectFilter::make('status')->label('สถานะ')->options(self::STATUS_LABELS),
                Filter::make('deleted')->label('บัญชีที่ลบแล้ว (รอลบถาวร)')->query(fn (Builder $query) => $query->whereNotNull('deleted_at')),
            ])
            ->recordActions([
                AccountInfolist::action(),
                Action::make('approve')
                    ->label('อนุมัติ')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('อนุมัติบัญชีครู')
                    ->modalDescription(fn (User $record) => "{$record->name} จะเข้าสู่ระบบในแอปได้ทันที")
                    ->authorize('approve')
                    ->action(function (User $record) {
                        $record->forceFill([
                            'status' => User::STATUS_ACTIVE,
                            'approved_by' => auth()->id(),
                        ])->save();

                        Notification::make()->title("อนุมัติ {$record->name} แล้ว")->success()->send();
                    }),
                Action::make('disable')
                    ->label('ระงับ')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => $record->isStudent() ? 'ระงับบัญชีนักเรียน' : 'ระงับบัญชีครู')
                    ->modalDescription(fn (User $record) => "{$record->name} จะถูกออกจากระบบทุกเครื่องและเข้าใช้ไม่ได้จนกว่าจะ".($record->isStudent() ? 'เปิดใช้งานใหม่ในหน้านักเรียน' : 'อนุมัติใหม่'))
                    ->authorize('disable')
                    ->action(function (User $record) {
                        $record->forceFill(['status' => User::STATUS_DISABLED])->save();
                        $record->tokens()->delete();

                        Notification::make()->title("ระงับ {$record->name} แล้ว")->warning()->send();
                    }),
                self::impersonateAction(),
                EditAction::make()->visible(fn (User $record) => ! $record->isDeleted()),
                AccountDeletionActions::delete(),
                AccountDeletionActions::restore(),
            ]);
    }

    /**
     * The menu badge: teachers waiting for approval. Since 2 Oct 2569 the
     * admin's approval is the only gate of teacher sign-up (no school code),
     * so the waiting count is shown where the admin looks first.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = User::query()
            ->where('role', User::ROLE_TEACHER)
            ->where('status', User::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'ครูรออนุมัติ';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['googleIdentity', 'googleAccount']);
    }

    /**
     * The Google column (DESIGN §32): signs in with Google, and the state of
     * the Classroom connection of a teacher.
     *
     * @return list<string>
     */
    public static function googleBadges(User $user): array
    {
        $badges = [];
        if ($user->googleIdentity !== null) {
            $badges[] = 'เข้าสู่ระบบ';
        }
        if ($user->googleAccount !== null) {
            $badges[] = $user->googleAccount->needsReconnect() ? 'Classroom ต้องเชื่อมใหม่' : 'Classroom';
        }

        return $badges;
    }

    /** "เข้าใช้แทน" (DESIGN §29.14), also on the student page. */
    public static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label('เข้าใช้แทน')
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('เข้าใช้แอปในฐานะผู้ใช้นี้')
            ->modalDescription(fn (User $record) => "เบราว์เซอร์จะเปิดเว็บแอปในฐานะ {$record->name} ด้วยสิทธิ์เต็มของผู้ใช้ มีแถบบอกตลอดเวลาและบันทึกลงบันทึกการใช้งาน (ลิงก์ใช้ได้ 1 นาที ครั้งเดียว) ใช้เมื่อผู้ใช้มีปัญหาเท่านั้น")
            ->modalSubmitActionLabel('เปิดแอป')
            ->authorize('impersonate')
            ->action(function (User $record, $livewire) {
                $admin = auth()->user();
                if (! $admin instanceof User) {
                    return;
                }
                $ticket = app(Impersonations::class)->issueTicket($admin, $record);
                $livewire->redirect(WebApp::url('impersonate?ticket='.$ticket), navigate: false);
            });
    }
}
