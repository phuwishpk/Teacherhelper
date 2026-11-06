<?php

namespace App\Filament\Resources\AssistantConversations\Pages;

use App\Filament\Resources\AssistantConversations\AssistantConversationResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAssistantConversations extends ManageRecords
{
    protected static string $resource = AssistantConversationResource::class;

    public function getSubheading(): ?string
    {
        return 'บทสนทนาของครูกับผู้ช่วย Krucheck อ่านอย่างเดียว ลบเองเมื่อไม่มีข้อความใหม่ 90 วัน ใช้เพื่อช่วยแก้ปัญหาเท่านั้น';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
