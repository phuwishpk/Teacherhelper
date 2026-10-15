<?php

namespace App\Filament\Resources\Students;

use App\Domain\Students\SchoolStudents;
use App\Domain\Students\StudentMerger;
use App\Exceptions\ApiException;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * "รวมบัญชีนักเรียน" in Filament (DESIGN §24.5, §24.13): the comparison of
 * both accounts and the merge itself, with the same StudentMerger as the
 * app. Used by the student list (pick the other account) and the page of
 * likely duplicates (both accounts given).
 */
final class StudentMergeSupport
{
    public const REASON_LABELS = [
        'google_user' => 'บัญชี Google Classroom เดียวกัน',
        'email' => 'อีเมล Google เดียวกัน',
        'name' => 'ชื่อเดียวกัน',
    ];

    /**
     * The comparison view and the confirm box of a merge modal.
     *
     * @param  callable(Get): (User|null)  $keep
     * @param  callable(Get): (User|null)  $merge
     * @return array{0: View, 1: Checkbox}
     */
    public static function confirmSchema(callable $keep, callable $merge): array
    {
        return [
            View::make('filament.student-merge-preview')
                ->viewData(fn (Get $get) => self::previewData($keep($get), $merge($get))),
            Checkbox::make('confirm')
                ->label('ยืนยันรวมบัญชี ย้อนกลับไม่ได้')
                ->accepted()
                ->validationMessages(['accepted' => 'ติ๊กยืนยันก่อนรวมบัญชี']),
        ];
    }

    /**
     * @return array{preview: array<string, mixed>|null, error: string|null}
     */
    public static function previewData(?User $keep, ?User $merge): array
    {
        if ($keep === null || $merge === null) {
            return ['preview' => null, 'error' => null];
        }

        try {
            return ['preview' => app(StudentMerger::class)->preview($keep, $merge), 'error' => null];
        } catch (ApiException $e) {
            return ['preview' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Merges $merge into $keep, or keeps the modal open with a notification
     * that says why not (no right, invalid pair, conflicts).
     */
    public static function merge(?User $keep, ?User $merge, Action $action): void
    {
        $actor = auth()->user();
        if ($keep === null || $merge === null || ! $actor instanceof User) {
            Notification::make()->title('ไม่พบบัญชีนักเรียน')->danger()->send();
            $action->halt();
        }
        if (! Gate::forUser($actor)->allows('mergeStudents', [$keep, $merge])) {
            Notification::make()->title('ไม่มีสิทธิ์รวมบัญชีของโรงเรียนนี้')->danger()->send();
            $action->halt();
        }

        try {
            app(StudentMerger::class)->merge($keep, $merge, $actor);
        } catch (ApiException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->body(implode("\n", Arr::flatten($e->errors)))
                ->danger()
                ->persistent()
                ->send();
            $action->halt();
        }

        Notification::make()->title("รวมบัญชี {$merge->name} เข้า {$keep->name} แล้ว")->success()->send();
    }

    /**
     * Search results for the other account: active, unmerged students of the
     * same school (SchoolStudents::search), without $keep itself.
     *
     * @return array<int, string> id => label
     */
    public static function searchOptions(User $keep, string $search): array
    {
        if ($keep->school_id === null) {
            return [];
        }

        $options = [];
        foreach (app(SchoolStudents::class)->search($keep->school_id, $search) as $row) {
            if ($row['id'] !== $keep->id) {
                $options[$row['id']] = self::label($row);
            }
        }

        return $options;
    }

    /** "ด.ช.สมชาย ใจดี · 6601 · ป.5/1 (2569)" */
    public static function label(array $row): string
    {
        $parts = [$row['name']];
        if (($row['student_code'] ?? null) !== null) {
            $parts[] = $row['student_code'];
        }
        $rooms = array_map(fn (array $c) => "{$c['name']} ({$c['academic_year']})", $row['classrooms'] ?? []);
        if ($rooms !== []) {
            $parts[] = implode(', ', $rooms);
        }

        return implode(' · ', $parts);
    }

    public static function student(mixed $id): ?User
    {
        if (! is_numeric($id)) {
            return null;
        }

        return User::query()->whereKey((int) $id)->where('role', User::ROLE_STUDENT)->first();
    }
}
