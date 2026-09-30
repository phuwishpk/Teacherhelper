# ชุด golden fixture สำหรับ calibration ของ Gemini

ใช้กับ `php artisan eduvision:calibrate-gemini` (DESIGN §21.10) เพื่อวัดว่าลดระดับ media resolution ของภาพแต่ละประเภทได้หรือไม่

## ไฟล์

- `manifest.json` รายการภาพ ประเภทข้อ เฉลย และ **label** (สิ่งที่เขียนอยู่บนภาพจริง)
- `synthetic/` ภาพสังเคราะห์ที่สร้างด้วย `php tools/calibration/make-synthetic-fixtures.php` (ตัวอักษรจากฟอนต์ที่สุ่มขนาด มุม และความเบลอ **ไม่ใช่ลายมือจริง**)

| kind | ภาพ | ระดับที่ใช้ตอนนี้ | ระดับเป้าหมาย |
|---|---|---|---|
| `short` | crop ช่องตอบสั้น | `GEMINI_MEDIA_SHORT=high` | `low` |
| `work` | crop พื้นที่แสดงวิธีทำ + กรอบคำตอบสุดท้าย | `GEMINI_MEDIA_WORK=high` | `medium` |
| `page` | ภาพทั้งหน้า | `GEMINI_MEDIA_PAGE=high` | `high` |
| `document` | เฉลยของครู | `GEMINI_MEDIA_DOCUMENT=medium` | `medium` |

## เพิ่มลายมือจริงของทีม

ใช้ลายมือของสมาชิกทีมเท่านั้น **ห้ามใช้ลายมือของนักเรียนจริง** (DESIGN §16.2) เพิ่มรายการใน `items` ของ `manifest.json` โดยตั้ง `"source": "team"` (สคริปต์สร้างภาพสังเคราะห์จะไม่ลบรายการเหล่านี้)

```json
{
  "id": "team-short-001",
  "kind": "short",
  "source": "team",
  "file": "team/short-001.webp",
  "question": {"type": "short", "prompt_text": "12 + 8 เท่ากับเท่าไร", "max_points": 1, "is_numeric": true,
               "answer_key": {"accepted": ["20"], "numeric": {"value": 20, "abs_tol": 0}}},
  "label": {"answer_text": "20", "key_match": ["exact", "equivalent"]},
  "cnn": {"text": "20", "confidence": 0.98}
}
```

- `label` ของ `short`: `answer_text` และ `key_match` ที่ยอมรับ, `show_work` (kind `work`): `final_answer_text`, `final_answer_match` และ `steps_valid` รายบรรทัด, `open`: `criteria_levels`, `document`: `answer` ต่อข้อ
- `page` และ `document` ใส่ `questions` เป็นรายการข้อพร้อม `position` และ `label` ของแต่ละข้อ
- `cnn` (ไม่บังคับ) คือค่าที่ CNN บนมือถืออ่านได้ ใช้วัดเกณฑ์ CNN skip (§21.3) โดยไม่เรียก Gemini

## รัน

```bash
cd backend
php artisan eduvision:calibrate-gemini --dry-run            # ดูแผนและจำนวน call
php artisan eduvision:calibrate-gemini --kind=short --level=low
```

ต้องมี `GEMINI_API_KEY` (หรือ `--teacher=<user id>`) และเสียค่า API จริง `--max-calls` (ค่าตั้งต้น 60) จำกัดจำนวน request รวมการส่งซ้ำ ผลอยู่ที่ `backend/storage/app/calibration/` command พิมพ์ค่า `.env` ที่แนะนำ **ผู้พัฒนาเปลี่ยนค่าเองหลังตรวจผล**

ผลของชุดสังเคราะห์อย่างเดียว**ยังไม่พอ**ให้ลดระดับ เพราะภาพอ่านง่ายกว่าลายมือเด็กจริงมาก ต้องเพิ่มลายมือของทีมให้ครบอย่างน้อย `GEMINI_CALIBRATION_MIN_SAMPLES` (40) ต่อประเภทแล้วรันใหม่
