<?php

namespace App\Filament\Resources\QaTesters\Pages;

use App\Filament\Resources\QaTesters\QaTesterResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageQaTesters extends ManageRecords
{
    protected static string $resource = QaTesterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openLab')
                ->label('เปิด Test Lab')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(url('/qa'), shouldOpenInNewTab: true),
            CreateAction::make()->label('เพิ่มผู้ทดสอบ'),
        ];
    }
}
