<?php

namespace App\Filament\Resources\Users;

use App\Domain\Accounts\AccountDeletion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * "ลบบัญชี" and "กู้คืนบัญชี" (DESIGN §31) on the users page and the
 * students page, plus the status shown for a deleted account.
 */
final class AccountDeletionActions
{
    public static function delete(): Action
    {
        return Action::make('deleteAccount')
            ->label('ลบบัญชี')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize(fn (User $record) => Gate::allows('deleteAccount', $record))
            ->modalHeading(fn (User $record) => "ลบบัญชีของ {$record->name}")
            ->modalDescription(fn (User $record) => self::consequences($record))
            ->modalSubmitActionLabel('ลบบัญชี')
            ->schema([
                Checkbox::make('confirm')
                    ->label('เข้าใจแล้วว่าเมื่อครบ '.AccountDeletion::RESTORE_DAYS.' วัน ข้อมูลจะถูกลบถาวรและกู้คืนไม่ได้')
                    ->accepted()
                    ->required(),
            ])
            ->action(function (User $record, AccountDeletion $deletion) {
                $admin = auth()->user();
                if (! $admin instanceof User) {
                    return;
                }
                $deletion->delete($admin, $record);
                Notification::make()
                    ->title("ลบบัญชีของ {$record->name} แล้ว")
                    ->body('กู้คืนได้ถึง '.self::date($record).' จากเมนูของแถวนี้')
                    ->warning()
                    ->send();
            });
    }

    public static function restore(): Action
    {
        return Action::make('restoreAccount')
            ->label('กู้คืนบัญชี')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->authorize(fn (User $record) => Gate::allows('restoreAccount', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "กู้คืนบัญชีของ {$record->name}")
            ->modalDescription(fn (User $record) => $record->isTeacher()
                ? 'บัญชีกลับมาใช้งานได้ และห้องเรียนที่ถูกปิดตอนลบจะเปิดอีกครั้ง ครูต้องเข้าสู่ระบบใหม่'
                : 'บัญชีกลับมาใช้งานได้ นักเรียนต้องเข้าสู่ระบบใหม่')
            ->modalSubmitActionLabel('กู้คืน')
            ->action(function (User $record, AccountDeletion $deletion) {
                $admin = auth()->user();
                if (! $admin instanceof User) {
                    return;
                }
                $deletion->restore($admin, $record);
                Notification::make()->title("กู้คืนบัญชีของ {$record->name} แล้ว")->success()->send();
            });
    }

    /** The status badge of a deleted account. */
    public static function statusLabel(User $user): string
    {
        return 'ลบแล้ว (กู้คืนได้ถึง '.self::date($user).')';
    }

    private static function consequences(User $user): string
    {
        $until = now()->addDays(AccountDeletion::RESTORE_DAYS)->timezone('Asia/Bangkok')->format('d/m/Y');
        if ($user->isTeacher()) {
            $s = AccountDeletion::teacherSummary($user);

            return "ครูจะออกจากระบบทุกเครื่องและเข้าใช้ไม่ได้ทันที ห้องเรียนที่ครูเป็นเจ้าของ {$s['classrooms']} ห้อง "
                ."(นักเรียน {$s['students']} คน งานที่ส่งแล้ว {$s['submissions']} ชิ้น) จะถูกปิดเป็นห้องเก่าทันที "
                ."วันที่ {$until} บัญชีและห้องเหล่านี้พร้อมงาน คะแนน และไฟล์ทั้งหมดจะถูกลบถาวร บัญชีนักเรียนยังอยู่ "
                .'กู้คืนได้ก่อนวันนั้น';
        }

        return "นักเรียนจะออกจากระบบทุกเครื่องและเข้าใช้ไม่ได้ทันที วันที่ {$until} ชื่อ ชื่อผู้ใช้ รหัสผ่าน การเชื่อม Google "
            .'และไฟล์รูปงานจะถูกลบถาวร คะแนนยังอยู่ในห้องแต่ไม่มีชื่อ กู้คืนได้ก่อนวันนั้น';
    }

    private static function date(User $user): string
    {
        return AccountDeletion::purgeAt($user)?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '—';
    }
}
