# EduVision app (Flutter, Android)

แอปครูและนักเรียนของ EduVision โครงสร้างตาม `docs/DESIGN.md` §6

## รัน

```bash
flutter pub get
dart run build_runner build --delete-conflicting-outputs   # เมื่อแก้ตารางใน lib/core/db/app_database.dart
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000  # AVD
flutter test && flutter analyze
flutter build apk --debug
(cd android && ./gradlew :app:testDebugUnitTest)             # JVM test ของ RegionMath.kt (หลัง build ครั้งแรก)
```

## Build สำหรับใช้งานจริง (release)

```bash
flutter build apk --release --split-per-abi --dart-define=API_BASE_URL=https://<โดเมนของโรงเรียน>
```

- ใช้ `--split-per-abi` แล้วแจกไฟล์ `app-arm64-v8a-release.apk` (ประมาณ 56 MB) เพราะ OpenCV มี native library
  แยกตาม ABI ถ้า build รวมทุก ABI ไฟล์จะใหญ่เกือบ 2 เท่า (x86 ของ OpenCV ถูกตัดออกแล้วใน `build.gradle.kts`)
- release เปิด R8 อยู่ `android/app/proguard-rules.pro` keep `org.opencv.**` ไว้ เพราะ JNI ของ OpenCV
  หา class/field ตามชื่อ และ AAR ไม่มี consumer rule มาให้

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
- `/scan` สแกนใบงานด้วยกล้อง (Phase 1, ดูหัวข้อถัดไป)
- โครงสร้างตาม DESIGN §6.1: หน้าผลของนักเรียนอยู่ที่ `features/results/` (shell ของนักเรียนอยู่ที่ `features/student/`)
- ข้อความ UI เป็นภาษาไทยแบบเขียนตรงในโค้ด ยัง**ไม่**ตั้ง `flutter gen-l10n` (l10n.yaml/ARB) เลื่อนไปจนกว่าจะมีภาษาที่สอง

## สแกนใบงาน (DESIGN §5.3, §6.2–§6.4, §9.4)

ลำดับงาน: กล้อง (`package:camera`, ความละเอียดสูงสุด) → `detectPage` → ตรวจคุณภาพ → หา layout → `cropPage`
→ หน้ายืนยัน (ชื่อจาก roster ที่ cache ไว้ + ภาพทุกกรอบ) → `scan_queue` (`pending`) → เริ่มอัปโหลดทันที → กลับไปถ่ายแผ่นต่อไป

| ส่วน | ไฟล์ |
|---|---|
| นิยาม Pigeon | `pigeons/scan.dart` (อยู่นอก `lib/` เพราะ import `package:pigeon` ที่เป็น dev dependency) สร้างใหม่ด้วย `dart run pigeon --input pigeons/scan.dart && dart format lib/platform/pigeons` |
| โค้ดที่ generate | `lib/platform/pigeons/scan_api.g.dart`, `android/app/src/main/kotlin/com/eduvision/eduvision/scan/ScanApi.g.kt` (commit ไว้ ห้ามแก้เอง) |
| Kotlin | `scan/ScanPipelineImpl.kt` (OpenCV `org.opencv:opencv:4.14.0` จาก Maven Central: ArUco `DICT_4X4_50` ผ่าน `ArucoDetector`, warp, blur, crop, ฝนวงกลม, CNN input; ML Kit `barcode-scanning:17.3.0` อ่าน QR), `scan/RegionMath.kt` (คณิตศาสตร์ล้วน มี JVM test), `scan/LayoutPage.kt` |
| Dart | `lib/platform/scan_pipeline.dart` (interface + ตัวที่เรียก Pigeon), `features/scan/page_layout.dart` (model ของ layout + คณิตศาสตร์ชุดเดียวกับ `RegionMath.kt`), `scan_quality.dart` (เหตุผลให้ถ่ายใหม่ภาษาไทย), `scan_meta.dart` (สร้าง `meta` ของ §9.4), `scan_processor.dart` (ลำดับงานทั้งหมด ใช้ซ้ำได้กับรูปจาก Google Classroom §18.2), `scan_file_store.dart`, `scan_camera.dart`, `scan_screen.dart` |

ค่าที่ใช้ (ต้องตรงกันทั้ง Dart และ Kotlin):

- marker id 0/1/2/3 = มุมบนซ้าย/บนขวา/ล่างขวา/ล่างซ้าย ตำแหน่งจุดศูนย์กลางคือมุมของ `frame_mm`
  หา marker บนภาพที่ย่อเหลือด้านยาว 1600 px ถ้าเจอไม่ครบจะหาซ้ำบนภาพเต็ม
- warp กรอบ marker เป็น 200 DPI (A4 frame 178×265 mm → 1402×2087 px) ภาพ `page` ย่อเหลือด้านยาว 1600 px
- crop ขยายขอบ 2% **ของกรอบ** (0.02 ในหน่วย normalize) ทุกด้าน แล้ว clamp ให้อยู่ในกรอบ; WebP quality 80
- กรอบคำตอบสุดท้ายของ `lines` คืนมาเป็น crop แยก id `<region_id>_final` → field `crop_<region_id>_final` และ `final_file` ใน meta
- `blur_score` = variance of Laplacian บนกรอบที่ warp แล้ว เกณฑ์เริ่มต้น `defaultMinBlurScore = 60`
  (ต้องจูนจากภาพจริงใน Phase 1) ถ้าติดแค่เรื่องเบลอ ครูกด "ใช้ภาพนี้ต่อ" ได้
- `ink_ratio` = **สัดส่วนของพื้นที่คำตอบที่อยู่ห่างจากลายมือไม่เกิน 2 mm** (สเกลเดียวกับ DESIGN §9.4 และเกณฑ์ `> 0.02` ของ §11.8 กฎ D)
  1. พื้นที่: กรอบที่หดเข้า 1.2 mm (ตัดเส้นขอบที่พิมพ์) ถ้าเป็น `lines` ที่มีกรอบคำตอบสุดท้าย (show_work มีเลขบรรทัด) ตัด 8 mm ทางซ้ายที่เป็นเลขบรรทัดออกด้วย
  2. หมึก: พิกเซลที่เข้มกว่าเกณฑ์ (Otsu ของ crop ที่ขยาย 2% ไม่ใกล้สีกระดาษเกิน 25 ระดับ) ลบเส้นที่พิมพ์: เส้นนอนยาว ≥ ¼ ของความกว้างแต่ไม่เกิน 20 mm
     (หาหลังขยายแนวตั้ง 1 px จึงเจอเส้นที่เอียงหรือโค้งตามกระดาษ) และเส้นตั้งสูง ≥ 90% ของความสูง
  3. ทิ้งเส้นที่บางกว่า 2 px และจุดเล็กกว่า 0.25 mm² (ฝุ่น, noise ของ JPEG, เศษเส้นขอบที่โค้งเข้ามา)
  4. นับทุกพิกเซลที่ห่างจากหมึกที่เหลือไม่เกิน 2 mm (distance transform) หารด้วยพื้นที่
  
  ผลจากภาพสังเคราะห์ (port ขั้นตอนเดียวกันเป็น Python, ภาพถ่ายเอียงแบบ perspective + แสงไม่เท่ากัน + blur + noise + JPEG):

  | กรอบ | `ink_ratio` | วิธีเดิม (นับเฉพาะพิกเซลหมึก) |
  |---|---|---|
  | กรอบว่าง, บรรทัดว่าง (มีเลขบรรทัด), กรอบคำตอบว่าง, กรอบว่างที่มีฝุ่น | 0.000 (กระดาษโค้ง 1.5 mm ก็ยัง 0) | 0.000–0.006 |
  | จุดปากกา 0.7 mm ในกรอบ 70×16 mm | 0.017 | 0.0004 |
  | "x=5" ตัวเล็กในบรรทัดเปล่า 3 บรรทัด | 0.024 | 0.004 |
  | เลข "5" ตัวเล็ก / "1" / ดินสอ "7" / "-2" ในกรอบ 70×16 mm | 0.050 / 0.060 / 0.068 / 0.100 | 0.005 / 0.009 / 0.005 / 0.012 |
  | "125" ในกรอบ 70×16 mm | 0.236 | 0.040 |
  | show_work 2 บรรทัด / กรอบคำตอบ "5" | 0.118 / 0.098 | 0.018 / 0.016 |

  หน้ายืนยันแสดง "ว่าง" เมื่อ `ink_ratio ≤ 0.02` (`emptyInkRatio` เส้นเดียวกับ §11.8) ข้อจำกัด: เป็นสัดส่วนของพื้นที่
  คำตอบสั้นมากในบรรทัดเปล่าหลายบรรทัดจึงได้ค่าน้อย (5 บรรทัดขึ้นไปอาจต่ำกว่า 0.02) และดินสอที่จางมาก (เทาอ่อนกว่าราว 150/255)
  อาจไม่ถูกนับเป็นหมึกเพราะเกณฑ์ Otsu แบ่งระหว่างเส้นขอบสีดำกับกระดาษ ต้องตรวจกับภาพจริงของ KICKOFF 2b
- `mcq_fill` = สัดส่วนพิกเซลเข้มใน 70% ของรัศมี threshold ด้วย Otsu ของทั้งแถว (ไม่ให้ใกล้สีกระดาษเกิน 25 ระดับ)
- `cnnInput` (เฉพาะกรอบ numeric) = grayscale 32×128 byte ตาม `ml/train/preprocess.py` (tight crop + fit to canvas)
  ฝั่ง Dart ค่อย normalize `x = 1 - g/255` ตอนรันโมเดล (ขั้น digit reader) ตอนนี้ยังไม่แนบ `cnn` ใน meta
- ใบงานสำรอง (`student_id = 0`, §18.3) และบัตร login (`EVL1.`) ถูกปฏิเสธที่หน้าสแกนด้วยข้อความภาษาไทย
  ใบงานสำรองผ่านได้เฉพาะ `ScanProcessor.analyze(path, source: ScanSource.classroom(googleSubmissionId: ...))`
  (ไฟล์แนบจาก Google Classroom §18.2) ซึ่งเพิ่ม `source: "classroom"` และ `google_submission_id` ใน `meta` (§18.6)
  เก็บค่านี้ไว้ในแถว `needs_layout` ด้วย และไม่ถือว่าใบงานสำรองของคนละ submission เป็นหน้าซ้ำ
- layout: หาใน drift `cached_layouts` ก่อน ถ้าไม่มีและต่อเน็ตได้จะดึง `GET /assignments/{id}/layouts?version=` มา cache
  404 `code: layout_unknown` (การบ้านเป็นของครูคนนี้แต่ไม่มีเวอร์ชันนั้น) → "ไม่พบหน้า … ใน layout เวอร์ชัน …";
  403/404 อื่น → "ไม่พบการบ้าน #… ในบัญชีนี้"
  ถ้าต่อไม่ได้ ครูเลือก "เก็บไว้ในคิว" → แถว `needs_layout` เก็บภาพดิบ (field `raw` ใช้ในเครื่องเท่านั้น ไม่เคยถูกส่ง)
  แล้วประมวลผลต่อเมื่อเปิดหน้าสแกนหรือกด "ประมวลผลต่อ" ในหน้าคิว (ใช้ `client_scan_id` และ `scanned_at` เดิม)
- ไฟล์ของสแกนที่ยืนยันแล้วถูกย้ายจาก cache ไป `<app support>/scan_queue/<client_scan_id>/` เพื่อไม่ให้ Android ลบทิ้งตอนพื้นที่เต็ม
- ถ้าสแกนหน้าเดิม (การบ้าน, นักเรียน, หน้า) ที่ยังค้างในคิว หน้ายืนยันจะเตือนก่อน (server ใช้ภาพล่าสุดตามกติกาสแกนซ้ำ §9.4)

ทดสอบ: `test/scan/` (คณิตศาสตร์ของ region, เหตุผลให้ถ่ายใหม่, รูป meta, processor กับ drift ในหน่วยความจำ + pipeline ปลอม,
widget test ของหน้าสแกนด้วยกล้องปลอม) และ `android/app/src/test/.../RegionMathTest.kt` ซึ่งใช้ตัวเลขชุดเดียวกับ
`test/scan/page_layout_test.dart` ส่วนการทำงานของ OpenCV จริงต้องลองบนเครื่อง Android (Phase 1: พิมพ์ 20 แผ่นแล้ววัด crop ตรงกรอบ)

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
| `meta` ของ `POST /scans` | ตาม §9.4: `mcq` ส่ง `mcq_fill` (ไม่มี `ink_ratio`), `box`/`lines` ส่ง `ink_ratio` (นิยามด้านบน: ว่าง = 0, เขียนแล้ว > 0.02), `lines` ที่มีกรอบคำตอบสุดท้ายส่ง `final_file`; `scanned_at` เป็น UTC (`...Z`); `blur_score` ปัด 1 ตำแหน่ง; สแกนจาก Classroom เพิ่ม `source: "classroom"` และ `google_submission_id` (สแกนจากกล้องไม่ส่ง `source`) |
| `POST /scans` | field `meta` (JSON string) + ไฟล์ `page` และ crop ตามชื่อใน meta; 200/201 → done; 202 หรือ `state: pending_confirm` (รวม 200 ที่ส่ง body เดิมซ้ำ) → `conflict` รอครูยืนยันผ่าน `POST /scans/{scan_id}/confirm-replace` **แอปต้องการ `scan_id` ใน body ของ 202** (ถ้าไม่มีจะเก็บเป็น conflict ที่ยืนยันจากเครื่องไม่ได้ ไม่ลบไฟล์); 401/408/429/5xx/เน็ตหลุด → retry แบบ backoff; 4xx อื่น (422, 403, 404, 413, …) → failed ถาวร (แสดง `message (code)`) |
| list ทุกตัว | รับได้ทั้ง `[...]` และ `{data: [...], next_cursor}` |
