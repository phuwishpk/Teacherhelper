<?php

namespace App\Filament\Resources\GoogleEvents\Pages;

use App\Filament\Resources\GoogleEvents\GoogleEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageGoogleEvents extends ManageRecords
{
    protected static string $resource = GoogleEventResource::class;

    public function getSubheading(): ?string
    {
        return 'เก็บย้อนหลัง 30 วัน ข้อผิดพลาดที่เกิดในหน้าของ Google เอง (เช่น "Access blocked" หรือ 403 access_denied ของแอปที่ยังไม่ publish) ไม่ถูกส่งกลับมาที่ระบบ จึงไม่มีในรายการนี้';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
