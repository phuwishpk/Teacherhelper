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

## Build สำหรับใช้งานจริง (release)

```bash
flutter build apk --release --dart-define=API_BASE_URL=https://<โดเมนของโรงเรียน>
```

- debug/profile build ถ้าไม่ใส่ `--dart-define` จะชี้ไปที่ `http://10.0.2.2:8000` (AVD → Mac) ให้อัตโนมัติ
- release build **ไม่มีค่า default**: ถ้าไม่ใส่ `API_BASE_URL` แอปจะเปิดหน้าแจ้งว่า build ผิด (`MisconfiguredApp` ใน `lib/main.dart`)
  แทนที่จะพยายามต่อ 10.0.2.2 แล้วขึ้น "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" (release ห้าม cleartext อยู่แล้ว ดู CLAUDE.md)
- ต้องเป็น `https://` เพราะ `android/app/src/main` ไม่มี network_security_config ที่อนุญาต cleartext

## ทำงานออฟไลน์

- เปิดแอปตอนไม่มีเน็ต: `SessionNotifier.restore()` ใช้ token ที่เก็บไว้ + ผลของ `GET /me` ครั้งล่าสุด (เก็บใน secure storage
  คู่กับ token) จึงยังเข้าหน้าสแกน/คิวอัปโหลด/รายชื่อที่ cache ไว้ได้ ออกจากระบบเฉพาะเมื่อ server ตอบ 401 และจะเรียก `/me`
  ใหม่เมื่อแอปกลับมา foreground
- คิวอัปโหลด: `done`/`conflict` เป็นสถานะ "ติดแน่น" (การอัปโหลดซ้ำจาก isolate อื่นที่ล้มเหลวทีหลังจะไม่เปลี่ยนกลับเป็น pending/failed),
  แถวที่ค้าง `uploading` จะถูกหยิบใหม่หลัง 10 นาที, กด "อัปโหลดตอนนี้" จะยกเลิก task ของ WorkManager ก่อนแล้วขอใหม่ถ้ายังมี pending,
  และ task ใน WorkManager จะขอ retry ตราบใดที่ยังมีสแกน `pending` แม้จะยังไม่ถึงเวลา backoff

## สิ่งที่มีแล้ว (Phase 2)

- ครู: สมัคร (รอผู้ดูแลโรงเรียนอนุมัติ) / เข้าสู่ระบบ, ห้องเรียน (สร้าง แก้ไข เพิ่มนักเรียนแบบวางรายชื่อ
  ออกบัตร QR ใหม่ รีเซ็ต PIN พิมพ์บัตร QR ทั้งห้อง เตรียมสแกนออฟไลน์), การบ้าน (สร้าง แก้ไข คำถาม 4 ประเภทพร้อมเฉลย
  เลือกตัวชี้วัด ร่าง/แก้/อนุมัติ rubric สร้าง layout พิมพ์ใบงาน), คิวอัปโหลด
- นักเรียน: เข้าสู่ระบบด้วยบัตร QR (`mobile_scanner`) หรือรหัสห้อง + เลขที่ + PIN, หน้าผลการบ้าน (เฉพาะที่เผยแพร่แล้ว)
- ในเครื่อง: drift (`cached_layouts`, `cached_rosters`, `scan_queue`, `model_cache`) และ workmanager
  อัปโหลดสแกนแบบ multipart ตาม §9.4 พร้อม exponential backoff
- `/scan` เป็นหน้า placeholder รอขั้น A2 (กล้อง + Pigeon/Kotlin)
- โครงสร้างตาม DESIGN §6.1: หน้าผลของนักเรียนอยู่ที่ `features/results/` (shell ของนักเรียนอยู่ที่ `features/student/`)
- ข้อความ UI เป็นภาษาไทยแบบเขียนตรงในโค้ด ยัง**ไม่**ตั้ง `flutter gen-l10n` (l10n.yaml/ARB) เลื่อนไปจนกว่าจะมีภาษาที่สอง

## ข้อตกลงกับ backend ที่แอปคาดไว้ (นอกเหนือจาก DESIGN §9)

รูป request/response ของ `POST /classrooms/{id}/students`, `GET /subjects`, งานพิมพ์บัตร QR (`/login-cards`,
`/students/{id}/login-card`, `GET /login-card-prints/{id}` และ `/file`) และ `POST /students/{id}/pin` อยู่ใน **DESIGN §9.2** แล้ว
และ backend (B1) ทำตามนั้น ตารางนี้เก็บเฉพาะรายละเอียดที่แอปพึ่งพาเพิ่มเติม ทดสอบรูปแบบ request/response ไว้ที่
`test/classrooms/classrooms_repository_test.dart` และ `test/assignments/assignments_repository_test.dart`

| จุด | แอปส่ง / คาดว่าได้รับ |
|---|---|
| `POST /auth/student/qr` | `{qr_token}` คือส่วนหลัง `EVL1.` ของ QR บนบัตร |
| PIN ล็อก | status 423 หรือ 429 หรือ `code: pin_locked` → แอปแสดงข้อความล็อก 15 นาที |
| `POST /classrooms/{id}/students` | body `{students: [{name, student_number}]}` ตอบ `201 {data: [{student_id, student_number, name, status, pin}]}` แอปแสดง PIN เริ่มต้นของทุกคนครั้งเดียว (คัดลอกได้) ก่อนออกจากหน้า |
| งานพิมพ์บัตร QR | `202 {data: {id, status, classroom_id, student_id, download_url, status_url, error}}` แอป poll ที่ `status_url` (หรือ `GET /login-card-prints/{id}` ถ้าไม่มี) จน `status = ready` แล้วดาวน์โหลด `download_url` (รับทั้ง URL เต็มและ `/api/v1/...`) |
| `POST /students/{id}/pin` | ตอบ `{pin}` (backend ส่ง `student_id` มาด้วย) แอปแสดง `pin` ครั้งเดียว |
| `GET /subjects` | `{data: [{id, code, name}]}` ใช้ตอนสร้างการบ้าน |
| `GET /assignments/{id}` | มี `questions[]` แต่ละข้อมี `skills[]` และ `rubric_criteria[]` |
| คำถาม | key ตาม §8.3 + `skill_ids[]` |
| `PUT /questions/{id}/rubric` | `{criteria: [{position, description, points, is_core}], reference_steps?}` |
| `GET /assignments/{id}/layouts` | ไม่ส่ง `version` = ทุกเวอร์ชัน `[{version, pages[]}]` (รับแบบ object เดี่ยวด้วย) |
| `POST /scans` | field `meta` (JSON string) + ไฟล์ `page` และ crop ตามชื่อใน meta; 200/201 → done, 202 หรือ `state: pending_confirm` → รอครูยืนยันผ่าน `POST /scans/{id}/confirm-replace`, 422 → failed (แสดง `message (code)`) |
| list ทุกตัว | รับได้ทั้ง `[...]` และ `{data: [...], next_cursor}` |
