<?php

namespace App\Filament\Resources\AiCalls\Pages;

use App\Filament\Resources\AiCalls\AiCallResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAiCalls extends ManageRecords
{
    protected static string $resource = AiCallResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
