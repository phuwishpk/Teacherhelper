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
    ];
}
