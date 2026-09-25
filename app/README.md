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
  การหยิบแถวไปส่ง (`markUploading`) เป็น UPDATE แบบมีเงื่อนไข จึงมี isolate เดียวที่ได้ส่ง, แถวที่ค้าง `uploading` จะถูกหยิบใหม่หลัง 10 นาที
  (หน้าคิวมีปุ่ม "ลองใหม่"/"ลบ" ให้แถวที่ค้างนานกว่านั้น), กด "อัปโหลดตอนนี้" จะยกเลิก task ของ WorkManager ก่อนแล้วขอใหม่ถ้ายังมีงานค้าง,
  และ task ใน WorkManager จะขอ retry ตราบใดที่ยังมีสแกน `pending` หรือ `uploading` แม้จะยังไม่ถึงเวลา backoff
- ออกจากระบบ (ปุ่มในแอป): ลบ `scan_queue` พร้อมไฟล์ภาพ, `cached_rosters`, `cached_layouts` และยกเลิก task อัปโหลด
  (`model_cache` เก็บไว้) ถ้ายังมีสแกนที่ไม่ได้ส่งจะถามยืนยันก่อน ส่วนการหลุดเพราะ 401 จะ**ไม่**ลบ เพื่อให้ครูคนเดิมเข้าใหม่แล้วส่งต่อได้
  แอปจำ id ของเจ้าของข้อมูลในเครื่อง (secure storage `local_data_owner`) ถ้าคนอื่นเข้าสู่ระบบจะลบข้อมูลของคนก่อนก่อน

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
| PIN ล็อก | 423 `code: pin_locked` → ข้อความล็อก โดยใช้เวลาที่เหลือจาก `errors.pin[0]` ถ้ามี; 429 (limiter ต่อ IP ของ `throttle:student-auth`) → "มีการเข้าสู่ระบบถี่เกินไป" ไม่ใช่ข้อความล็อก PIN |
| `POST /classrooms/{id}/students` | body `{students: [{name, student_number}]}` ตอบ `201 {data: [{student_id, student_number, name, status, pin}]}` แอปแสดง PIN เริ่มต้นของทุกคนครั้งเดียว (คัดลอกได้) ก่อนออกจากหน้า |
| งานพิมพ์บัตร QR | `202 {data: {id, status, classroom_id, student_id, download_url, status_url, error}}` แอป poll ที่ `status_url` (หรือ `GET /login-card-prints/{id}` ถ้าไม่มี) จน `status = ready` แล้วดาวน์โหลด `download_url` (รับทั้ง URL เต็มและ `/api/v1/...`) |
| `POST /students/{id}/pin` | ตอบ `{pin}` (backend ส่ง `student_id` มาด้วย) แอปแสดง `pin` ครั้งเดียว |
| `GET /subjects` | `{data: [{id, code, name}]}` ใช้ตอนสร้างการบ้าน |
| `GET /assignments/{id}` | มี `questions[]` แต่ละข้อมี `skills[]` และ `rubric_criteria[]` |
| คำถาม | key ตาม §8.3 + `skill_ids[]`; คำตอบที่ยอมรับกรอกบรรทัดละคำตอบ (จุลภาคเป็นส่วนของคำตอบ เช่น `1,000`) |
| `PUT /questions/{id}/rubric` | `open`: `{criteria: [{position, description, points, is_core}]}` (เกณฑ์หลักไม่เกิน 1 ข้อ); `show_work`: `{criteria: [], reference_steps: [...]}` |
| ร่าง rubric | poll `GET /assignments/{id}` จนข้อนั้นเปลี่ยน: `show_work` ดู `answer_key.reference_steps`, `open` ดู `rubric_criteria` |
| `GET /assignments/{id}/layouts` | ไม่ส่ง `version` = ทุกเวอร์ชัน `[{version, pages[]}]` (รับแบบ object เดี่ยวด้วย) |
| `POST /scans` | field `meta` (JSON string) + ไฟล์ `page` และ crop ตามชื่อใน meta; 200/201 → done; 202 หรือ `state: pending_confirm` (รวม 200 ที่ส่ง body เดิมซ้ำ) → `conflict` รอครูยืนยันผ่าน `POST /scans/{scan_id}/confirm-replace` **แอปต้องการ `scan_id` ใน body ของ 202** (ถ้าไม่มีจะเก็บเป็น conflict ที่ยืนยันจากเครื่องไม่ได้ ไม่ลบไฟล์); 401/408/429/5xx/เน็ตหลุด → retry แบบ backoff; 4xx อื่น (422, 403, 404, 413, …) → failed ถาวร (แสดง `message (code)`) |
| list ทุกตัว | รับได้ทั้ง `[...]` และ `{data: [...], next_cursor}` |
