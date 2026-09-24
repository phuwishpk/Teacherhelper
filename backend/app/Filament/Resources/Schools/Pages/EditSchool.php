<?php

namespace App\Filament\Resources\Schools\Pages;

use App\Filament\Resources\Schools\SchoolResource;
use Filament\Resources\Pages\EditRecord;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    /** No delete: users, classrooms and skills reference schools with ON DELETE RESTRICT. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
