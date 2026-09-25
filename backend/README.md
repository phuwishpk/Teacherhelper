# EduVision backend (Laravel 13)

API และ web admin ของ EduVision รันบน shared Plesk hosting (ไม่มี SSH, Docker, daemon หรือ Redis) ดู `docs/DESIGN.md` §7–§9 และ `docs/KICKOFF.md` ส่วนที่ 3 ก่อนแก้โค้ด

สถานะ: **M0 "walking skeleton"** = สมัคร/login ครู, `GET /me`, logout, `GET /health` + heartbeat ของ queue และ Filament boot ได้ที่ `/admin/login` (ยังไม่มี resource)

## ส่วนประกอบ

| ส่วน | เวอร์ชัน | หมายเหตุ |
|---|---|---|
| Laravel | 13.x (`php ^8.3`) | `composer.json` ล็อก `config.platform.php = 8.3.0` ให้ตรง PHP บน Plesk |
| Sanctum | 4.3.x | token ของแอป (ครู: ability `teacher` อายุ 30 วัน) |
| Filament | 4.13.x | panel `admin` ที่ `/admin` เข้าได้เฉพาะ `role=admin` และ `status=active` |
| Queue / Cache / Session | driver `database` ทั้งหมด | ไม่มี Redis บน hosting |
| MariaDB | 11 (container ในเครื่อง) | บน hosting ใช้เวอร์ชันที่ probe รายงาน |

## เริ่มงานในเครื่อง

ต้องมี PHP 8.3+ (เครื่องพัฒนาใช้ 8.5 จาก Homebrew), Composer 2 และ OrbStack/Docker

```bash
# 1. MariaDB (ครั้งเดียว) port 3307 กันชนกับ MySQL อื่น
docker run -d --name eduvision-mariadb --restart unless-stopped \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=eduvision \
  -e MARIADB_USER=eduvision -e MARIADB_PASSWORD=eduvision \
  -p 3307:3306 -v eduvision-mariadb:/var/lib/mysql mariadb:11

# 2. โปรเจกต์
cd backend
composer install
cp -n .env.example .env && php artisan key:generate
php artisan migrate --seed          # สร้าง schools, users, cache, jobs, personal_access_tokens + โรงเรียนสาธิต 1 แห่ง

# 3. รัน
php artisan serve                                  # AVD: http://10.0.2.2:8000
php artisan serve --host=0.0.0.0 --port=8000       # มือถือจริงใน Wi-Fi เดียวกัน: http://<IP ของ Mac>:8000
php artisan eduvision:queue-work                   # รัน worker หนึ่งรอบ (แทน cron ของ Plesk)
php artisan test                                   # ใช้ SQLite in-memory ไม่แตะ MariaDB
```

รหัสโรงเรียนสำหรับสมัครครูอ่านจาก `SEED_TEACHER_JOIN_CODE` ใน `.env` (ค่าเริ่มต้นในเครื่อง `DEMO2569`) **บน hosting ต้องตั้งเป็นรหัสสุ่ม 8 ตัว** เพราะ repo เป็น public และใน M0 รหัสนี้เป็นด่านเดียวที่กันคนแปลกหน้าสมัครเป็นครู

## API (M0)

base path `/api/v1` ส่ง/รับ JSON แบบ `snake_case` error ทุกตัว (4xx ทุกชั้น รวมถึงกรณีที่ client ไม่ส่ง `Accept: application/json`) มีรูปแบบ `{message, errors, code}` โดย `code` ที่แอปต้องจัดการเองมาจาก `ApiException` (ตาราง endpoint ด้านล่าง) ส่วน error ที่ framework สร้างเองใช้ code กลาง: `validation_failed` (422), `unauthenticated` (401), `forbidden` (403), `not_found` (404), `method_not_allowed` (405), `too_many_requests` (429 พร้อม header `Retry-After`) และ `http_<status>` สำหรับสถานะอื่น (ดู `app/Exceptions/ApiErrorResponse.php`) มีเพียง 500 ที่ยังเป็น body มาตรฐานของ Laravel

| Method | Path | Body / Header | ตอบ |
|---|---|---|---|
| GET | `/health` | | `200 {status: ok\|degraded, db: ok\|error, queue_last_run_at}` (503 เมื่อต่อ DB ไม่ได้) |
| POST | `/auth/teacher/register` | `{school_code, name, email, password}` | `201 {user}` รหัสผิด → `422 code: school_code_invalid` |
| POST | `/auth/teacher/login` | `{email, password, device_name?}` | `200 {token, user}` ผิด → `422 code: invalid_credentials` บัญชีไม่ active → `403 code: account_not_active` |
| GET | `/me` | `Authorization: Bearer <token>` | `200 {data: user}` |
| POST | `/auth/logout` | `Authorization: Bearer <token>` | `204` ยกเลิกเฉพาะ token ที่ใช้เรียก |

`user` = `{id, name, email, role, status, school: {id, name} | null}`

**stub ของ M0** (แก้ใน Phase 2, ดู `TODO(phase-2)` ใน `TeacherAuthController`): บัญชีที่สมัครใหม่เป็น `active` ทันที ยังไม่ผ่านการอนุมัติของ admin ใน Filament

ตัวอย่างด้วย curl (zsh)

```bash
H=(-H 'Content-Type: application/json' -H 'Accept: application/json')
curl -s "${H[@]}" -X POST localhost:8000/api/v1/auth/teacher/register \
  -d '{"school_code":"DEMO2569","name":"ครูทดสอบ","email":"t1@example.com","password":"secret1234"}'
TOKEN=$(curl -s "${H[@]}" -X POST localhost:8000/api/v1/auth/teacher/login \
  -d '{"email":"t1@example.com","password":"secret1234","device_name":"curl"}' | jq -r .token)
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' localhost:8000/api/v1/me
```

## การบ้าน, rubric และใบงาน (DESIGN §5, §9.3)

ต้องตั้ง `QR_SIGNING_KEY` ใน `.env` ก่อนพิมพ์ใบงานครั้งแรก (สร้างด้วย `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`) และห้ามเปลี่ยนภายหลัง เพราะใบงานที่พิมพ์ไปแล้วจะตรวจ QR ไม่ผ่าน

| Method | Path | หมายเหตุ |
|---|---|---|
| GET / POST | `/assignments` | list กรองด้วย `?classroom_id=&status=` (cursor) สร้างด้วย `{classroom_id, subject_id, title, strictness?, due_at?}` |
| GET / PATCH / DELETE | `/assignments/{id}` | GET รวม `questions[]` (พร้อม `skills`, `rubric_criteria`) PATCH `status` ได้แค่ `closed`/`draft` ลบได้เฉพาะ draft ที่ยังไม่เคยพิมพ์ (`409 assignment_not_draft` / `assignment_printed`) |
| POST | `/assignments/{id}/questions` | ตรวจ `answer_key` ตามประเภท (§8.3) `position` แทรกได้ |
| PATCH / DELETE | `/questions/{id}` | ส่งเฉพาะ field ที่แก้ แก้สิ่งที่พิมพ์บนกระดาษ → การบ้านกลับเป็น `draft` |
| POST | `/questions/{id}/rubric/draft` | `202` queue `DraftRubricJob` (Gemini ปลอมเป็นค่าเริ่มต้น) |
| PUT | `/questions/{id}/rubric` | `{criteria[], reference_steps?}` เกณฑ์หลัก 1 ข้อพอดี คะแนนรวม = คะแนนเต็ม → `approved` |
| POST | `/assignments/{id}/layout` | `201` เวอร์ชันใหม่ / `200` ถ้าหน้ากระดาษไม่เปลี่ยน การบ้านเป็น `ready` (`422 rubric_not_approved`, `assignment_empty`, `question_too_tall`) |
| GET | `/assignments/{id}/layouts?version=` | มี version → `{data: layout}` (`404 layout_unknown`) ไม่มี → `{data: [layout...]}` ใหม่สุดก่อน |
| POST | `/assignments/{id}/worksheets` | `202 {data: print}` (`409 assignment_not_ready`, `422 classroom_empty`, `503 qr_key_missing` เมื่อ server ยังไม่ได้ตั้ง `QR_SIGNING_KEY`) |
| GET | `/worksheet-prints/{id}` และ `/file` | `{id, status, assignment_id, layout_version, download_url, status_url, error, created_at}` / PDF (`409 print_not_ready`) เข้าได้เฉพาะครูที่สอนห้องนั้นอยู่ตอนนี้ |

ใบงาน: `LayoutBuilder` วัดความสูงโจทย์จากการวาดจริงของ mPDF (ฟอนต์ Sarabun ใน `resources/fonts`, ตัดคำไทยด้วยพจนานุกรม) แล้ว `WorksheetPdfRenderer` วาดตาม layout เดิมทุกคนและตรวจซ้ำว่าตรงกับ layout ที่เก็บไว้ `RenderWorksheetsJob` วาดครั้งละ `WORKSHEET_BATCH_SIZE` คน (ค่าเริ่มต้น 10) แล้ว `MergeWorksheetsJob` รวมไฟล์ ทั้งสองเขียนเวลาลง log (`worksheets.render_batch`, `worksheets.merge`) ไว้วัดบน hosting ตาม §5.5 ในเครื่อง 40 คนใช้ราว 0.5–1.2 วินาทีรวมทุก job

## สแกนใบงาน (DESIGN §9.4, §7.3)

| Method | Path | ใคร | หมายเหตุ |
|---|---|---|---|
| POST | `/scans` | ครู | multipart: `meta` (JSON ตาม §9.4), `page` (WebP) และ crop WebP หนึ่งไฟล์ต่อชื่อที่อ้างใน `meta.regions[].file` / `final_file` ตอบ `201 {scan_id, submission_id, state: "active"}`, `202 {…, state: "pending_confirm"}` (submission เผยแพร่แล้ว) หรือ `200` body เดิมเมื่อ `client_scan_id` ซ้ำ (state ปัจจุบันของ scan) ถูกปฏิเสธ: `422 qr_invalid` (ลายเซ็นผิด/ไม่พบการบ้าน), `422 layout_unknown`, `422 page_mismatch` (หน้าไม่อยู่ใน layout หรือชุดช่องคำตอบไม่ตรงกับหน้านั้น), `422 student_unknown` (ไม่อยู่ในห้อง หรือใบงานสำรอง `student_id = 0` จากกล้อง §18.3), `422 validation_failed` (meta/ไฟล์), `403` (ไม่ใช่ครูของห้อง), `413 too_many_files` (PHP `max_file_uploads` ต่ำไป), `503 qr_key_missing` |
| POST | `/scans/{id}/confirm-replace` | ครู | ยืนยันใช้สแกนใหม่แทนหน้าที่เผยแพร่แล้ว → `200 {…, state: "active"}` เรียกซ้ำได้ `409 scan_superseded` / `scan_files_missing` |
| GET | `/scans/{id}/page` | ครู | ภาพหน้าเต็ม `image/webp` (`410 image_purged` หลังเผยแพร่และ purge แล้ว) |
| GET | `/responses/{id}/crop?part=main\|final` | ครู / นักเรียนเจ้าของ (หลังเผยแพร่) | ภาพ crop ของข้อ `final` = กรอบคำตอบสุดท้ายของ show_work (`410 image_purged` หลัง `crop_retention_until`) |

- **ขั้นตอน** (`App\Domain\Scans\ScanIngestor`): ตรวจ QR ด้วย `QrSigner` → สิทธิ์ครู → layout เวอร์ชันตาม QR → หน้าและชุดช่องคำตอบต้องตรงกับ layout → นักเรียนอยู่ในห้อง → ไฟล์ WebP ไม่เกิน `SCAN_MAX_PAGE_KB` / `SCAN_MAX_CROP_KB` จากนั้นทำใน transaction ที่ล็อกแถว submission
- **กติกาสแกนซ้ำ** (key = การบ้าน, นักเรียน, หน้า): ยังไม่เผยแพร่ → สแกนใหม่ `active` ของเก่า `superseded` และ response ของหน้านั้น (แถวเดิม, id เดิม) ถูกล้างผลตรวจแล้วตรวจใหม่ เผยแพร่แล้ว → `pending_confirm` เก็บ crop + `regions.json` ไว้ที่ `scans/{school}/{assignment}/pending/{scan}/` จนครูยืนยัน แล้ว submission กลับไป `grading` (ล้าง `published_at`) ทุกคะแนนที่ถูกแทนบันทึก `score_events.action = rescan`
- **ตรวจตอนรับ**: ปรนัยให้คะแนนทันทีจากค่าการฝน (§11.6, `App\Domain\Grading\McqGrader`) พร้อม `review_priority` ตาม §11.8 (`ReviewPriority`) และ `score_events` `ai_scored` (actor `system`) ข้ออื่นเป็น `queued` แล้ว dispatch `GradeScanJob` ลง queue `grading` (**ตอนนี้เป็น stub** ขั้น B4 เติม Gemini + fuzzy) ข้อที่ชนิดคำถามถูกแก้หลังพิมพ์จนไม่ตรงกับช่องบนกระดาษเป็น `manual` (`fuzzy_trace.manual_reason`)
- **สถานะ submission** (`SubmissionStatus::refresh`): `awaiting_scan` → `grading` (มีข้อ queued/extracted/failed) → `needs_review` → `reviewed` (ครูตรวจครบ) → `published` (ตั้งโดยการเผยแพร่เท่านั้น)
- ลบคำถามที่มีคำตอบสแกนแล้วไม่ได้ (`409 question_has_responses`)
- **PHP บน server** ต้องรับ request ได้: `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 16M, `max_file_uploads` ≥ 100 (หน้าหนึ่งมีได้ถึง 1 + 2 × จำนวนข้อ ไฟล์ที่เกิน PHP ทิ้งเงียบๆ)

ทดสอบในเครื่องด้วย curl ต้องรัน server ที่ตั้ง ini เหล่านี้ เช่น `cd public && php -d max_file_uploads=100 -d upload_max_filesize=8M -d post_max_size=16M -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`

## Queue และ heartbeat

Plesk ไม่มี process ค้าง จึงใช้ Scheduled Task ทุก 1 นาทีเรียก

```
php artisan eduvision:queue-work
```

command นี้ (1) dispatch `QueueHeartbeatJob` ลง table `jobs` แล้ว (2) รัน `queue:work --queue=grading,default,pdf --stop-when-empty --max-time=50` (option ตาม DESIGN §7.2) job เขียนเวลาไว้ใน cache key `queue.last_run_at` ซึ่ง `GET /health` อ่านออกมา ถ้าค่าเก่ากว่า `HEARTBEAT_MAX_AGE_MINUTES` (ค่าเริ่มต้น 3 นาที) `status` จะเป็น `degraded` แปลว่า cron + artisan + database queue ไม่ครบวงจร

## ลบไฟล์ตามนโยบาย (DESIGN §7.2, §7.3)

Scheduled Task ทุกวัน 02:00 เรียก

```
php artisan eduvision:purge-images
```

- PDF ใบงาน (มีชื่อนักเรียน) ที่สร้างมาเกิน 30 วัน: แถวใน `worksheet_prints` ยังอยู่ แต่เปลี่ยนเป็น `failed` พร้อม `error` "ไฟล์ใบงานถูกลบแล้วเพราะเก็บไว้ครบ 30 วัน กรุณาสั่งพิมพ์ใหม่" และไฟล์ใต้ `worksheets/` ที่ไม่มีแถวชี้ถึงแต่เก่ากว่า 30 วันก็ถูกลบด้วย
- ภาพหน้าเต็ม `scans/{school}/{assignment}/{scan}.webp` ของ submission ที่เผยแพร่แล้ว (scan `active`/`superseded`; สแกน `pending_confirm` เก็บไว้จนครูตัดสินใจ) → `scans.page_image_path = NULL`
- ภาพ crop `crops/{school}/{assignment}/{response}[_final].webp` ของสแกนที่ทำจนถึง `schools.crop_retention_until` เมื่อวันนั้นผ่านไปแล้ว (รวมที่พักไฟล์ของสแกนที่รอยืนยัน) → `crop_path`/`final_crop_path = NULL` คะแนนและค่าที่อ่านได้ยังอยู่
- โฟลเดอร์ `pending/{scan}` ที่ค้างของสแกนที่ไม่ได้รอยืนยันแล้ว (เก่ากว่า 24 ชั่วโมง)

## โครงสร้างโค้ด (ตาม DESIGN §7.1)

```
app/Console/Commands/QueueWorkCommand.php    eduvision:queue-work
app/Console/Commands/PurgeImagesCommand.php  eduvision:purge-images (ลบไฟล์ตาม §7.3)
app/Exceptions/ApiException.php              error ที่มี code ให้แอปจัดการ (ไม่ถูกเขียนลง log)
app/Exceptions/ApiErrorResponse.php          รูปแบบ {message, errors, code} + code กลางของ error จาก framework
app/Http/Controllers/Api/V1/                 HealthController, TeacherAuthController, MeController
app/Http/Requests/Api/V1/                    validation ของแต่ละ endpoint
app/Http/Resources/UserResource.php
app/Jobs/QueueHeartbeatJob.php
app/Models/{School,User}.php
app/Domain/Worksheets/                       LayoutBuilder, WorksheetPdfRenderer, QrSigner, PdfMerger, LayoutService, WorksheetPrintService
app/Domain/Assignments/                      QuestionData (ตรวจ answer_key), QuestionEditor, QuestionPositions, RubricService
app/Domain/Gemini/                           GeminiClient (interface), FakeGeminiClient, RubricDraft
app/Domain/Scans/                            ScanIngestor (POST /scans, confirm-replace), ScanMeta, LayoutPageMatcher, ResponseWriter, SubmissionStatus, ScanFiles, ScanRetention
app/Domain/Grading/                          McqGrader (§11.6), ReviewPriority (§11.8), Understanding, ScoreRounding (§11.7)
app/Jobs/{DraftRubricJob,RenderWorksheetsJob,MergeWorksheetsJob,GradeScanJob}.php
resources/fonts/                             Sarabun (OFL) ที่เพิ่ม glyph U+200B ดู README ในโฟลเดอร์
app/Providers/Filament/AdminPanelProvider.php
config/eduvision.php                         ค่าที่อ่านจาก .env (ห้ามใช้ env() นอก config เพราะ production ใช้ config:cache)
database/migrations/                         0001_..._schools → users → cache → jobs → personal_access_tokens
database/seeders/SchoolSeeder.php
resources/worksheet/aruco/                   ArUco marker PNG + manifest สำหรับใบงาน (สร้างจาก ml/tools/gen_aruco.py)
tests/Feature/                               Api/HealthTest, Api/TeacherAuthTest, Api/ErrorFormatTest, Console/QueueWorkCommandTest, AdminPanelTest
```

## Deploy บน Plesk

ทำตาม `docs/KICKOFF.md` B7 หลัง `tools/hosting-probe.php` ผ่าน สรุปสั้น: Plesk Git pull ทั้ง monorepo, document root = `<deployment path>/backend/public`, `.env` อยู่ที่ `backend/.env` (นอก document root), Composer extension รัน `composer install`, Scheduled Task ทุกนาที `artisan eduvision:queue-work`, Scheduled Task ทุกวัน 02:00 `artisan eduvision:purge-images` และ migration ผ่าน Scheduled Task "Run now" `artisan migrate --force`

โฟลเดอร์ `public/css/filament`, `public/js/filament` และ `public/fonts/filament` ถูก commit ไว้จงใจ (ลบบรรทัด ignore ของ skeleton ออกจาก `.gitignore` แล้ว) เพื่อให้ไปถึง Plesk ผ่าน Git โดยไม่ต้องพึ่ง script `filament:upgrade` หลัง `composer install` หรือรัน `filament:assets` บน server เมื่ออัปเกรด Filament ให้รัน `php artisan filament:assets` แล้ว commit ไฟล์ที่เปลี่ยนด้วย

## ก่อน commit

```bash
vendor/bin/pint --test && php artisan test
```

ห้าม commit `.env`, key ใดๆ หรือข้อมูลนักเรียนจริง (repo เป็น public; pre-commit hook กัน Google API key ไว้ชั้นหนึ่ง)
