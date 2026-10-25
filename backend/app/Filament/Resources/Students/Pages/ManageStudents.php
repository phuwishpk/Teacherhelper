<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageStudents extends ManageRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('duplicates')
                ->label('บัญชีที่อาจซ้ำ')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->url(StudentResource::getUrl('duplicates')),
        ];
    }
}
