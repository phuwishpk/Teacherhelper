# EduVision app (Flutter, Android)

แอปครูและนักเรียนของ EduVision โครงสร้างตาม `docs/DESIGN.md` §6

## รัน

```bash
flutter pub get
dart run build_runner build --delete-conflicting-outputs   # เมื่อแก้ตารางใน lib/core/db/app_database.dart
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000  # AVD
flutter test && flutter analyze
flutter build apk --debug
```

## สิ่งที่มีแล้ว (Phase 2)

- ครู: สมัคร (รอผู้ดูแลโรงเรียนอนุมัติ) / เข้าสู่ระบบ, ห้องเรียน (สร้าง แก้ไข เพิ่มนักเรียนแบบวางรายชื่อ
  ออกบัตร QR ใหม่ รีเซ็ต PIN พิมพ์บัตร QR ทั้งห้อง เตรียมสแกนออฟไลน์), การบ้าน (สร้าง แก้ไข คำถาม 4 ประเภทพร้อมเฉลย
  เลือกตัวชี้วัด ร่าง/แก้/อนุมัติ rubric สร้าง layout พิมพ์ใบงาน), คิวอัปโหลด
- นักเรียน: เข้าสู่ระบบด้วยบัตร QR (`mobile_scanner`) หรือรหัสห้อง + เลขที่ + PIN, หน้าผลการบ้าน (เฉพาะที่เผยแพร่แล้ว)
- ในเครื่อง: drift (`cached_layouts`, `cached_rosters`, `scan_queue`, `model_cache`) และ workmanager
  อัปโหลดสแกนแบบ multipart ตาม §9.4 พร้อม exponential backoff
- `/scan` เป็นหน้า placeholder รอขั้น A2 (กล้อง + Pigeon/Kotlin)

## ข้อตกลงกับ backend ที่แอปคาดไว้ (นอกเหนือจาก DESIGN §9)

| จุด | แอปส่ง / คาดว่าได้รับ |
|---|---|
| `POST /auth/student/qr` | `{qr_token}` คือส่วนหลัง `EVL1.` ของ QR บนบัตร |
| PIN ล็อก | status 423 หรือ 429 หรือ `code: pin_locked` → แอปแสดงข้อความล็อก 15 นาที |
| `POST /classrooms/{id}/students` | body `{students: [{name, student_number}]}` |
| `POST /classrooms/{id}/login-cards`, `POST /students/{id}/login-card` | ตอบ `{id, status, download_url?}` แอป poll ที่ `GET /login-card-prints/{id}` (หรือ `status_url` ถ้ามี) จน `status = ready` แล้วดาวน์โหลด `download_url` (รับทั้ง URL เต็มและ `/api/v1/...`) |
| `POST /students/{id}/pin` | ตอบ `{pin}` แอปแสดงครั้งเดียว |
| `GET /subjects` | รายวิชาสำหรับสร้างการบ้าน (ไม่มีใน §9 แต่จำเป็นเพราะ `assignments.subject_id`) |
| `GET /assignments/{id}` | มี `questions[]` แต่ละข้อมี `skills[]` และ `rubric_criteria[]` |
| คำถาม | key ตาม §8.3 + `skill_ids[]` |
| `PUT /questions/{id}/rubric` | `{criteria: [{position, description, points, is_core}], reference_steps?}` |
| `GET /assignments/{id}/layouts` | ไม่ส่ง `version` = ทุกเวอร์ชัน `[{version, pages[]}]` (รับแบบ object เดี่ยวด้วย) |
| `POST /scans` | field `meta` (JSON string) + ไฟล์ `page` และ crop ตามชื่อใน meta; 200/201 → done, 202 หรือ `state: pending_confirm` → รอครูยืนยันผ่าน `POST /scans/{id}/confirm-replace`, 422 → failed (แสดง `message (code)`) |
| list ทุกตัว | รับได้ทั้ง `[...]` และ `{data: [...], next_cursor}` |
