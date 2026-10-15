<?php

namespace App\Filament\Resources\Schools\Pages;

use App\Domain\Auth\Google\GoogleSignIn;
use App\Filament\Resources\Schools\SchoolResource;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    /**
     * No delete: users, classrooms and skills reference schools with ON
     * DELETE RESTRICT. "ลบการเชื่อม Google ของนักเรียนทั้งหมด" removes every
     * student's Google sign-in link of this school at once (DESIGN §24.9.2).
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('unlinkStudentGoogle')
                ->label('ลบการเชื่อม Google ของนักเรียนทั้งหมด')
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->authorize(fn (School $record) => Gate::allows('unlinkStudentGoogle', $record))
                ->requiresConfirmation()
                ->modalHeading('ลบการเชื่อม Google ของนักเรียนทั้งหมด')
                ->modalDescription(fn (School $record) => 'นักเรียน '.self::linkedStudents($record).' คนของโรงเรียนนี้จะเข้าสู่ระบบด้วย Google ไม่ได้จนกว่าจะเชื่อมใหม่ (ยังใช้บัตร QR และ PIN ได้) ระบบลบรหัสบัญชี ชื่อ อีเมล และรูปที่เก็บจาก Google ทันที ย้อนกลับไม่ได้')
                ->modalSubmitActionLabel('ลบการเชื่อมทั้งหมด')
                ->action(function (School $record, GoogleSignIn $signIn) {
                    $actor = auth()->user();
                    $count = $actor instanceof User ? $signIn->unlinkSchoolStudents($record, $actor) : 0;
                    Notification::make()->title("ลบการเชื่อม Google ของนักเรียนแล้ว {$count} คน")->success()->send();
                }),
        ];
    }

    private static function linkedStudents(School $school): int
    {
        return UserGoogleIdentity::query()
            ->whereIn('user_id', User::query()->select('id')->where('school_id', $school->id)->where('role', User::ROLE_STUDENT))
            ->count();
    }
}
