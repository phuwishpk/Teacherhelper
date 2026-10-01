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
        'kind.in' => 'ชนิดต้องเป็น homework (การบ้าน) หรือ exam (ข้อสอบ)',
        'grading_method.in' => 'วิธีตรวจต้องเป็น app (ตรวจด้วยแอป) หรือ manual (ครูตรวจเอง)',
        'grading_method.prohibited_unless' => 'วิธีตรวจใช้กับข้อสอบเท่านั้น',
        'version_count.integer' => 'จำนวนชุดต้องเป็นตัวเลข',
        'version_count.min' => 'ต้องมีอย่างน้อย 1 ชุด',
        'version_count.max' => 'มีได้ไม่เกิน :max ชุด',
        'version_count.prohibited_unless' => 'จำนวนชุดใช้กับข้อสอบเท่านั้น',
        'duration_minutes.integer' => 'เวลาสอบต้องเป็นจำนวนนาที',
        'duration_minutes.min' => 'เวลาสอบต้องอย่างน้อย 1 นาที',
        'duration_minutes.max' => 'เวลาสอบต้องไม่เกิน :max นาที',
        'duration_minutes.prohibited_unless' => 'เวลาสอบใช้กับข้อสอบเท่านั้น',
        'show_key_to_students.boolean' => 'ค่าให้นักเรียนดูเฉลยต้องเป็นจริงหรือเท็จ',
        'show_key_to_students.prohibited_unless' => 'การให้นักเรียนดูเฉลยใช้กับข้อสอบเท่านั้น',
        'manual_full_marks.numeric' => 'คะแนนเต็มต้องเป็นตัวเลข',
        'manual_full_marks.gt' => 'คะแนนเต็มต้องมากกว่า 0',
        'manual_full_marks.max' => 'คะแนนเต็มต้องไม่เกิน :max',
        'manual_full_marks.decimal' => 'คะแนนเต็มมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
        'manual_full_marks.required_if' => 'ข้อสอบที่ครูตรวจเองต้องกำหนดคะแนนเต็ม',
        'manual_full_marks.prohibited_unless' => 'คะแนนเต็มแบบกรอกเองใช้กับข้อสอบเท่านั้น',
        'due_at.required_if' => 'ข้อสอบต้องกำหนดวันสอบ',
    ];

    /** Maximum exam duration in minutes printed on the booklet cover. */
    public const MAX_DURATION_MINUTES = 600;

    public const MAX_MANUAL_FULL_MARKS = 9999.99;
}
