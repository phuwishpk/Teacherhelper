<?php

namespace App\Filament\Resources\Users;

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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * DESIGN §7.5: approve or disable teacher accounts. Students are listed for
 * reference only (they are created by teachers through the API).
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
                Select::make('school_id')
                    ->label('โรงเรียน')
                    ->relationship('school', 'name')
                    ->searchable()
                    ->preload()
                    ->required(fn (callable $get) => $get('role') !== User::ROLE_ADMIN)
                    ->helperText('ผู้ดูแลระดับระบบไม่ต้องเลือกโรงเรียน'),
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
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        User::STATUS_ACTIVE => 'success',
                        User::STATUS_PENDING => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('school.name')->label('โรงเรียน')->placeholder('ระดับระบบ')->sortable(),
                TextColumn::make('created_at')
                    ->label('สมัครเมื่อ')
                    ->dateTime('d/m/Y H:i', 'Asia/Bangkok')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')->label('บทบาท')->options(self::ROLE_LABELS),
                SelectFilter::make('status')->label('สถานะ')->options(self::STATUS_LABELS),
                SelectFilter::make('school_id')->label('โรงเรียน')->relationship('school', 'name'),
            ])
            ->recordActions([
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
                    ->modalHeading('ระงับบัญชีครู')
                    ->modalDescription(fn (User $record) => "{$record->name} จะถูกออกจากระบบทุกเครื่องและเข้าใช้ไม่ได้จนกว่าจะอนุมัติใหม่")
                    ->authorize('disable')
                    ->action(function (User $record) {
                        $record->forceFill(['status' => User::STATUS_DISABLED])->save();
                        $record->tokens()->delete();

                        Notification::make()->title("ระงับ {$record->name} แล้ว")->warning()->send();
                    }),
                EditAction::make(),
            ]);
    }

    /**
     * The menu badge: teachers waiting for approval. Since 2 Oct 2569 the
     * admin's approval is the only gate of teacher sign-up (no school code),
     * so the waiting count is shown where the admin looks first. An admin of
     * a school counts that school's teachers; a system admin all.
     */
    public static function getNavigationBadge(): ?string
    {
        $admin = auth()->user();
        $count = User::query()
            ->where('role', User::ROLE_TEACHER)
            ->where('status', User::STATUS_PENDING)
            ->when($admin instanceof User && $admin->school_id !== null, fn (Builder $q) => $q->where('school_id', $admin->school_id))
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('school');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
