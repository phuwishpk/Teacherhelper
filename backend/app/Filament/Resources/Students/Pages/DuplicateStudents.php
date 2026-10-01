<?php

namespace App\Filament\Resources\Students\Pages;

use App\Domain\Students\SchoolStudents;
use App\Filament\Resources\Students\StudentMergeSupport;
use App\Filament\Resources\Students\StudentResource;
use App\Models\School;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * "คู่ที่น่าจะซ้ำ" in Filament (DESIGN §24.4, §24.15): pairs of active
 * accounts of one school that look like one child (the same Google
 * Classroom account or email, or the same name), each with "keep this one"
 * on both sides. Nothing is merged without the admin.
 */
class DuplicateStudents extends Page
{
    protected static string $resource = StudentResource::class;

    protected string $view = 'filament.duplicate-students';

    protected static ?string $title = 'บัญชีนักเรียนที่อาจซ้ำ';

    protected static ?string $breadcrumb = 'บัญชีที่อาจซ้ำ';

    /** The school whose pairs are listed (fixed for an admin of a school). */
    public ?int $schoolId = null;

    public function mount(): void
    {
        StudentResource::authorizeViewAny();
        $this->schoolId = StudentResource::admin()?->school_id
            ?? School::query()->orderBy('name')->value('id');
    }

    /** @return array<int, string> the schools a system admin may pick */
    public function getSchoolOptions(): array
    {
        if (StudentResource::admin()?->school_id !== null) {
            return [];
        }

        return School::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return list<array{a: array<string, mixed>, b: array<string, mixed>, reasons: list<string>}> */
    public function getPairs(): array
    {
        $schoolId = $this->allowedSchoolId();

        return $schoolId === null ? [] : app(SchoolStudents::class)->duplicateCandidates($schoolId);
    }

    /** Merge arguments.merge into arguments.keep after the comparison. */
    public function mergeAction(): Action
    {
        return Action::make('merge')
            ->label('เก็บบัญชีนี้')
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->size('sm')
            ->modalHeading(fn (array $arguments) => 'รวม '.(StudentMergeSupport::student($arguments['merge'] ?? null)?->name ?? '').' เข้า '.(StudentMergeSupport::student($arguments['keep'] ?? null)?->name ?? ''))
            ->modalSubmitActionLabel('รวมบัญชี')
            ->modalWidth('3xl')
            ->authorize(fn (array $arguments) => $this->mayMerge($arguments))
            ->schema(fn (array $arguments) => StudentMergeSupport::confirmSchema(
                fn () => StudentMergeSupport::student($arguments['keep'] ?? null),
                fn () => StudentMergeSupport::student($arguments['merge'] ?? null),
            ))
            ->action(fn (array $arguments, Action $action) => StudentMergeSupport::merge(
                StudentMergeSupport::student($arguments['keep'] ?? null),
                StudentMergeSupport::student($arguments['merge'] ?? null),
                $action,
            ));
    }

    public function reasonLabel(string $reason): string
    {
        return StudentMergeSupport::REASON_LABELS[$reason] ?? $reason;
    }

    /** @param  array<string, mixed>  $arguments */
    private function mayMerge(array $arguments): bool
    {
        $keep = StudentMergeSupport::student($arguments['keep'] ?? null);
        $merge = StudentMergeSupport::student($arguments['merge'] ?? null);
        if ($keep === null || $merge === null) {
            // The modal is mounted with arguments; without them there is nothing to merge.
            return $arguments === [];
        }

        return Gate::allows('mergeStudents', [$keep, $merge]);
    }

    /** $schoolId if this admin may see it: their own school, or any for a system admin. */
    private function allowedSchoolId(): ?int
    {
        $admin = StudentResource::admin();
        if ($admin === null || ! $admin->isAdmin()) {
            return null;
        }

        return $admin->school_id ?? $this->schoolId;
    }
}
