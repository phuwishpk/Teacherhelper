<?php

namespace App\Filament\Resources\Students;

use App\Domain\Auth\Google\GoogleSignIn;
use App\Domain\Students\CredentialIssuer;
use App\Domain\Students\StudentCode;
use App\Domain\Students\StudentUsernames;
use App\Exceptions\ApiException;
use App\Filament\Resources\Students\Pages\DuplicateStudents;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Users\AccountDeletionActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * DESIGN §24.13: the students of the school in Filament, searchable by name
 * or student code, with "รวมบัญชีนักเรียน" (the row is the account kept),
 * "ยกเลิกการเชื่อม Google" (§24.9.5) and the page of likely duplicates.
 * Students are created by teachers in the app; an admin may also edit the
 * name, student code and username, reset the password, disable or enable
 * the account, "เข้าใช้แทน" (§7.5, §29.14) and delete or restore it (§31). Every active admin sees
 * every student (one admin level, §29.13.1).
 */
class StudentResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'students';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'นักเรียน';

    protected static ?string $pluralModelLabel = 'นักเรียน';

    protected static ?string $navigationLabel = 'นักเรียน';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อ')->searchable()->sortable(),
                TextColumn::make('student_code')->label('เลขประจำตัว')->searchable()->placeholder('—'),
                TextColumn::make('classrooms.name')->label('ห้อง')->badge()->placeholder('—'),
                IconColumn::make('google')
                    ->label('Google')
                    ->state(fn (User $record) => $record->googleIdentity !== null)
                    ->boolean(),
                TextColumn::make('school.name')->label('พื้นที่ของครู')->sortable(),
                TextColumn::make('status')
                    ->label('สถานะ')
                    ->badge()
                    ->state(fn (User $record) => $record->isMerged() ? 'merged' : ($record->isDeleted() ? 'deleted' : $record->status))
                    ->formatStateUsing(fn (string $state, User $record) => match ($state) {
                        'merged' => 'ถูกรวมเข้า '.($record->mergedInto?->name ?? '#'.$record->merged_into_id),
                        'deleted' => AccountDeletionActions::statusLabel($record),
                        User::STATUS_ACTIVE => 'ใช้งานได้',
                        default => 'ระงับ',
                    })
                    ->color(fn (string $state) => match ($state) {
                        User::STATUS_ACTIVE => 'success',
                        'merged', 'deleted' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('state')
                    ->label('สถานะ')
                    ->options(['active' => 'ใช้งานได้', 'merged' => 'ถูกรวมแล้ว', 'deleted' => 'ลบแล้ว (รอลบถาวร)'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('merged_into_id')->whereNull('deleted_at')->where('status', User::STATUS_ACTIVE),
                        'merged' => $query->whereNotNull('merged_into_id'),
                        'deleted' => $query->whereNotNull('deleted_at'),
                        default => $query,
                    }),
                SelectFilter::make('school_id')->label('พื้นที่ของครู')->relationship('school', 'name'),
            ])
            ->recordActions([
                Action::make('unlinkGoogle')
                    ->label('ยกเลิกการเชื่อม Google')
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->color('danger')
                    ->visible(fn (User $record) => $record->googleIdentity !== null)
                    ->authorize(fn (User $record) => Gate::allows('unlinkGoogle', $record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => "ยกเลิกการเชื่อม Google ของ {$record->name}")
                    ->modalDescription('นักเรียนจะเข้าสู่ระบบด้วย Google ไม่ได้จนกว่าจะเชื่อมใหม่ (ยังใช้บัตร QR และ PIN ได้) เครื่องที่เข้าสู่ระบบอยู่แล้วยังใช้ได้จนกว่าจะรีเซ็ต PIN')
                    ->modalSubmitActionLabel('ยกเลิกการเชื่อม')
                    ->action(function (User $record, GoogleSignIn $signIn) {
                        $signIn->unlink($record, self::admin(), 'admin');
                        Notification::make()->title('ยกเลิกการเชื่อม Google แล้ว')->success()->send();
                    }),
                Action::make('merge')
                    ->label('รวมบัญชี')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->visible(fn (User $record) => $record->isActive() && ! $record->isMerged())
                    ->authorize(fn (User $record) => Gate::allows('mergeStudents', [$record, $record]))
                    ->modalHeading(fn (User $record) => "รวมบัญชีอื่นเข้าบัญชีของ {$record->name}")
                    ->modalDescription('บัญชีในแถวนี้คือบัญชีที่เก็บไว้ เลือกบัญชีที่ซ้ำเพื่อรวมเข้ามา')
                    ->modalSubmitActionLabel('รวมบัญชี')
                    ->modalWidth('3xl')
                    ->schema(fn (User $record) => [
                        Select::make('merge_id')
                            ->label('บัญชีที่จะรวม (ถูกปิดหลังรวม)')
                            ->placeholder('ค้นชื่อหรือเลขประจำตัว')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => StudentMergeSupport::searchOptions($record, $search))
                            ->getOptionLabelUsing(fn ($value) => StudentMergeSupport::student($value)?->name)
                            ->required()
                            ->live(),
                        ...StudentMergeSupport::confirmSchema(
                            fn () => $record,
                            fn (Get $get) => StudentMergeSupport::student($get('merge_id')),
                        ),
                    ])
                    ->action(fn (User $record, array $data, Action $action) => StudentMergeSupport::merge($record, StudentMergeSupport::student($data['merge_id'] ?? null), $action)),
                ActionGroup::make([
                    self::editAction(),
                    self::resetPasswordAction(),
                    UserResource::impersonateAction(),
                    self::disableAction(),
                    self::enableAction(),
                    AccountDeletionActions::delete(),
                    AccountDeletionActions::restore(),
                ]),
            ]);
    }

    /** Name, student code and username: the checks of PATCH /students/{id} and of the usernames (§24.4, §29.10). */
    private static function editAction(): Action
    {
        return Action::make('editStudent')
            ->label('แก้ไข')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (User $record) => Gate::allows('adminManageStudent', $record))
            ->modalHeading(fn (User $record) => "แก้ไข {$record->name}")
            ->modalSubmitActionLabel('บันทึก')
            ->fillForm(fn (User $record) => [
                'name' => $record->name,
                'student_code' => $record->student_code,
                'username' => $record->username,
            ])
            ->schema(fn (User $record) => [
                TextInput::make('name')->label('ชื่อ')->required()->maxLength(255),
                TextInput::make('student_code')
                    ->label('เลขประจำตัว')
                    ->maxLength(40)
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        try {
                            $code = StudentCode::parse(filled($value) ? $value : null, 'student_code');
                        } catch (ApiException $e) {
                            $fail($e->getMessage());

                            return;
                        }
                        $holder = $code === null ? null : StudentCode::holder((int) $record->school_id, $code, $record->id);
                        if ($holder !== null) {
                            $fail("เลขประจำตัว {$code} เป็นของ {$holder->name} อยู่แล้ว");
                        }
                    }),
                TextInput::make('username')
                    ->label('ชื่อผู้ใช้')
                    ->helperText('ตัวอักษรอังกฤษพิมพ์เล็ก ตัวเลข จุด ขีด และขีดล่าง 3–40 ตัว ใช้เข้าสู่ระบบ')
                    ->required(fn () => $record->username !== null)
                    ->maxLength(40)
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        if (blank($value)) {
                            return;
                        }
                        $username = StudentUsernames::normalize((string) $value);
                        if (! StudentUsernames::valid($username)) {
                            $fail('ชื่อผู้ใช้ใช้ได้เฉพาะตัวอักษรอังกฤษพิมพ์เล็ก ตัวเลข จุด ขีด และขีดล่าง 3–40 ตัว');
                        } elseif (StudentUsernames::taken($username, $record->id)) {
                            $fail("ชื่อผู้ใช้ {$username} มีคนใช้แล้ว");
                        }
                    }),
            ])
            ->action(function (User $record, array $data, Action $action) {
                $code = StudentCode::parse(filled($data['student_code'] ?? null) ? $data['student_code'] : null, 'student_code');
                $username = filled($data['username'] ?? null) ? StudentUsernames::normalize((string) $data['username']) : $record->username;
                try {
                    DB::transaction(fn () => $record->forceFill([
                        'name' => trim((string) $data['name']),
                        'student_code' => $code,
                        'username' => $username,
                    ])->save());
                } catch (UniqueConstraintViolationException) {
                    // Another save took the code or the username between the check and this one.
                    Notification::make()->title('เลขประจำตัวหรือชื่อผู้ใช้นี้เพิ่งถูกใช้ ลองใหม่อีกครั้ง')->danger()->send();
                    $action->halt();
                }
                Notification::make()->title("บันทึก {$record->name} แล้ว")->success()->send();
            });
    }

    /** The teacher's reset (POST /students/{id}/pin, §29.10): back to the initial password, sessions revoked. */
    private static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('รีเซ็ตรหัสผ่าน')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->authorize(fn (User $record) => Gate::allows('adminManageStudent', $record))
            ->modalHeading(fn (User $record) => "รีเซ็ตรหัสผ่านของ {$record->name}")
            ->modalDescription('รหัสผ่านกลับเป็นรหัสเริ่มต้น นักเรียนต้องตั้งรหัสใหม่ตอนเข้าครั้งถัดไป และถูกออกจากระบบทุกเครื่อง')
            ->modalSubmitActionLabel('รีเซ็ต')
            ->schema([
                Toggle::make('keep_google')
                    ->label('คงการเชื่อม Google ไว้')
                    ->helperText('ปิดไว้ถ้าสงสัยว่ามีคนอื่นรู้รหัสผ่าน เพราะคนนั้นอาจเชื่อมบัญชี Google ของตัวเองไว้แล้ว')
                    ->default(false),
            ])
            ->action(function (User $record, array $data, CredentialIssuer $issuer, GoogleSignIn $google) {
                $password = $issuer->issuePin($record);
                if (! ($data['keep_google'] ?? false)) {
                    $google->unlink($record, self::admin(), 'pin_reset');
                }
                Notification::make()
                    ->title("รีเซ็ตรหัสผ่านของ {$record->name} แล้ว")
                    ->body('ชื่อผู้ใช้ '.($record->username ?? '—')." รหัสผ่านเริ่มต้น {$password}")
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    private static function disableAction(): Action
    {
        return Action::make('disable')
            ->label('ระงับ')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('disable')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "ระงับบัญชีของ {$record->name}")
            ->modalDescription('นักเรียนจะถูกออกจากระบบทุกเครื่องและเข้าใช้ไม่ได้จนกว่าจะเปิดใช้งานใหม่ ข้อมูลและคะแนนยังอยู่ครบ')
            ->modalSubmitActionLabel('ระงับ')
            ->action(function (User $record) {
                $record->forceFill(['status' => User::STATUS_DISABLED])->save();
                $record->tokens()->delete();
                Notification::make()->title("ระงับ {$record->name} แล้ว")->warning()->send();
            });
    }

    private static function enableAction(): Action
    {
        return Action::make('enable')
            ->label('เปิดใช้งาน')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(fn (User $record) => Gate::allows('enableStudent', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "เปิดใช้งานบัญชีของ {$record->name}")
            ->modalSubmitActionLabel('เปิดใช้งาน')
            ->action(function (User $record) {
                $record->forceFill(['status' => User::STATUS_ACTIVE])->save();
                Notification::make()->title("เปิดใช้งาน {$record->name} แล้ว")->success()->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', User::ROLE_STUDENT)
            ->with(['school', 'classrooms', 'mergedInto', 'googleIdentity']);
    }

    /** Students are created by teachers in the app; the admin edits through the "แก้ไข" action. */
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStudents::route('/'),
            'duplicates' => DuplicateStudents::route('/duplicates'),
        ];
    }

    public static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
