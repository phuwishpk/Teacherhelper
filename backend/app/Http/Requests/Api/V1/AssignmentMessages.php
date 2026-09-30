<?php

namespace App\Http\Requests\Api\V1;

/** Thai validation messages shared by the assignment requests. */
final class AssignmentMessages
{
    public const MESSAGES = [
        'title.required' => 'กรุณากรอกชื่อการบ้าน',
        'title.max' => 'ชื่อการบ้านยาวเกิน 255 ตัวอักษร',
        'strictness.in' => 'ระดับความเข้มงวดต้องเป็น lenient, normal หรือ strict',
        'due_at.date' => 'วันกำหนดส่งไม่ถูกต้อง',
        'status.required' => 'กรุณาระบุสถานะ',
        'mode.required' => 'กรุณาเลือกโหมดของการบ้าน',
        'mode.in' => 'โหมดต้องเป็น worksheet (ใบงานของแอป) หรือ freeform (ไม่ใช้ใบงานของแอป)',
        'accept_late.boolean' => 'ค่ารับงานส่งช้าต้องเป็นจริงหรือเท็จ',
        'score_only.boolean' => 'ค่าเฉพาะคะแนนต้องเป็นจริงหรือเท็จ',
    ];
}
