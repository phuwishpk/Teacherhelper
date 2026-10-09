# Krucheck backend (Laravel 13)

API และ web admin ของ Krucheck รันบน shared Plesk hosting (ไม่มี SSH, Docker, daemon หรือ Redis) ดู `docs/DESIGN.md` §7–§9 และ `docs/KICKOFF.md` ส่วนที่ 3 ก่อนแก้โค้ด

สถานะ: M0 → Phase 6 แล้ว (auth, ห้องเรียน/นักเรียน, การบ้าน/ใบงาน, สแกน, Gemini + fuzzy, ตรวจทาน/เผยแพร่/คำขอตรวจใหม่, FCM, Google Classroom, คลังแบบฝึก/mastery/analytics และโมเดลบนมือถือ) และ B7 (security suite, CI, คู่มือ deploy `docs/HOSTING.md`) ดูหัวข้อด้านล่างต่อส่วน

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
# ตั้ง ADMIN_EMAIL / ADMIN_PASSWORD ใน .env ก่อน seed ไม่งั้นไม่มีบัญชี /admin ไว้อนุมัติครู (seeder ขึ้น WARN)
php artisan migrate --seed          # สร้าง schools, users, cache, jobs, personal_access_tokens + โรงเรียนสาธิต 1 แห่ง + admin

# 3. รัน
php artisan serve                                  # AVD: http://10.0.2.2:8000
php artisan serve --host=0.0.0.0 --port=8000       # มือถือจริงใน Wi-Fi เดียวกัน: http://<IP ของ Mac>:8000
php artisan eduvision:queue-work                   # รัน worker หนึ่งรอบ (แทน cron ของ Plesk)
php artisan test                                   # ใช้ SQLite in-memory ไม่แตะ MariaDB
```

**Gemini ในเครื่อง**: `GEMINI_FAKE=true` ใช้ `FakeGeminiClient` (ไม่ออกเน็ต ไม่เสียเงิน ผลจำลองจาก hash ของภาพ) ส่วน `GEMINI_FAKE=false` + `GEMINI_API_KEY` เรียก Gemini จริงด้วย key แบบ paid (§10.1) สลับด้วยการ**แก้ `.env` แล้วรีสตาร์ท server และ worker** เป็นหลัก ถ้าจะ override ด้วยตัวแปรใน shell (`GEMINI_FAKE=true php artisan ...`) ต้องใช้ `php artisan serve --no-reload` เสมอ: โหมดปกติ `serve` ลบตัวแปรทุกตัวที่ PHP อ่านจาก `.env` ออกจาก environment ของ server ลูกแล้วให้มันอ่าน `.env` ใหม่ ถ้า PHP ตั้ง `variables_order` ที่มี `E` (เช่น `EGPCS`) ค่าที่ export ไว้จะหายไปด้วย และ server อาจเรียก Gemini จริงด้วย key ใน `.env` ตอนร่าง rubric / สร้างคำอธิบายใหม่ / สร้างแบบฝึก (ทดสอบแล้ว: `GPCS` ของ Homebrew ส่งค่าถึง ส่วน `EGPCS` ไม่ถึง) worker (`eduvision:queue-work`) และคำสั่ง artisan อื่นใช้ค่าจาก shell เสมอ `tools/smoke.sh` ทำแบบนี้อยู่แล้ว

prompt ของ Gemini อยู่ที่ `resources/prompts/{purpose}.{type}.v{n}.md` ระบบใช้เวอร์ชันสูงสุดและบันทึกลง `ai_calls.prompt_version` (แก้ prompt = เพิ่มไฟล์เวอร์ชันใหม่ ไม่แก้ไฟล์เดิม) ตอนนี้ `extract.show_work.v2` (ระบุกฎ error carried forward: บรรทัดที่คิดต่อจากบรรทัดผิดก่อนหน้าได้ถูกต้องนับว่า valid เฉพาะบรรทัดแรกที่ผิดเป็น invalid) และ `explanation.general.v2` (น้ำเสียงกลางแบบเดียว ไม่ใช้ ครับ/ค่ะ/คะ) ที่เหลือ v1 เคสเทียบของ show_work อยู่ใน `tests/Unit/Grading/ShowWorkCalibrationTest.php`

ครูสมัครโดย**ไม่ต้องใช้รหัสโรงเรียน**แล้ว (2 ต.ค. 2569, DESIGN §17 #70): แอปเรียก `GET /auth/schools` แล้วส่ง `school_id` (มีโรงเรียนเดียวไม่ต้องส่ง) บัญชีใหม่เป็น `pending` จนกว่า admin อนุมัติ ซึ่งเป็นด่านเดียว `teacher_join_code` ของโรงเรียนที่ seed (`SEED_TEACHER_JOIN_CODE` ใน `.env` ค่าเริ่มต้นในเครื่อง `DEMO2569`) ยังใช้ได้กับแอปรุ่นเก่าที่ส่ง `school_code` บน hosting ยังควรตั้งเป็นรหัสสุ่ม 8 ตัวเพราะ repo เป็น public

## API (M0)

base path `/api/v1` ส่ง/รับ JSON แบบ `snake_case` error ทุกตัว (4xx ทุกชั้น รวมถึงกรณีที่ client ไม่ส่ง `Accept: application/json`) มีรูปแบบ `{message, errors, code}` โดย `code` ที่แอปต้องจัดการเองมาจาก `ApiException` (ตาราง endpoint ด้านล่าง) ส่วน error ที่ framework สร้างเองใช้ code กลาง: `validation_failed` (422), `unauthenticated` (401), `forbidden` (403), `not_found` (404), `method_not_allowed` (405), `too_many_requests` (429 พร้อม header `Retry-After`) และ `http_<status>` สำหรับสถานะอื่น (ดู `app/Exceptions/ApiErrorResponse.php`) มีเพียง 500 ที่ยังเป็น body มาตรฐานของ Laravel

| Method | Path | Body / Header | ตอบ |
|---|---|---|---|
| GET | `/health` | | `200 {status: ok\|degraded, db: ok\|error, queue_last_run_at}` (503 เมื่อต่อ DB ไม่ได้) |
| GET | `/auth/schools` | | `200 {data: [{id, name}]}` รายชื่อโรงเรียนของฟอร์มสมัคร (ไม่มีรหัส) |
| POST | `/auth/teacher/register` | `{school_id?, name, email, password}` | `201 {user}` (`pending`) ไม่ส่ง `school_id` ใช้โรงเรียนเดียวของระบบ ถ้ามีหลายโรงเรียน → `422 code: school_required` (`school_code` ของแอปรุ่นเก่ายังรับ รหัสผิด → `422 code: school_code_invalid`) |
| POST | `/auth/teacher/login` | `{email, password, device_name?}` | `200 {token, user}` ผิด → `422 code: invalid_credentials` บัญชีไม่ active → `403 code: account_not_active` |
| GET | `/me` | `Authorization: Bearer <token>` | `200 {data: user}` |
| POST | `/auth/logout` | `Authorization: Bearer <token>` | `204` ยกเลิกเฉพาะ token ที่ใช้เรียก |

`user` = `{id, name, email, role, status, school: {id, name} | null}`

บัญชีครูที่สมัครใหม่เป็น `pending` (login ได้ `403 account_not_active`) จนกว่า admin อนุมัติใน Filament (`/admin` → ผู้ใช้และครู)

ตัวอย่างด้วย curl (zsh)

```bash
H=(-H 'Content-Type: application/json' -H 'Accept: application/json')
curl -s "${H[@]}" -X POST localhost:8000/api/v1/auth/teacher/register \
  -d '{"name":"ครูทดสอบ","email":"t1@example.com","password":"secret1234"}'   # โรงเรียนเดียวในเครื่อง ไม่ต้องส่ง school_id
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
| POST | `/scans` | ครู | multipart: `meta` (JSON ตาม §9.4), `page` (WebP) และ crop WebP หนึ่งไฟล์ต่อชื่อที่อ้างใน `meta.regions[].file` / `final_file` ตอบ `201 {scan_id, submission_id, state: "active"}`, `202 {…, state: "pending_confirm"}` (submission เผยแพร่แล้ว) หรือ `200` body เดิมเมื่อ `client_scan_id` ซ้ำ (state ปัจจุบันของ scan) ถูกปฏิเสธ: `422 qr_invalid` (ลายเซ็นผิด/ไม่พบการบ้าน), `422 layout_unknown`, `422 page_mismatch` (หน้าไม่อยู่ใน layout หรือชุดช่องคำตอบไม่ตรงกับหน้านั้น), `422 student_unknown` (ไม่อยู่ในห้อง หรือใบงานสำรอง `student_id = 0` จากกล้อง §18.3), `422 google_submission_unknown` (`meta.source = classroom` แต่ `meta.google_submission_id` ไม่ใช่งานที่ sync แล้วของการบ้านนี้ ดูส่วน Google Classroom), `422 validation_failed` (meta/ไฟล์), `403` (ไม่ใช่ครูของห้อง), `503 too_many_files` (PHP `max_file_uploads` ต่ำไป แอปส่งใหม่เองหลังผู้ดูแลแก้ค่า), `503 qr_key_missing` |
| POST | `/scans/{id}/confirm-replace` | ครู | ยืนยันใช้สแกนใหม่แทนหน้าที่เผยแพร่แล้ว → `200 {…, state: "active"}` เรียกซ้ำได้ `409 scan_superseded` (มีสแกนใหม่กว่า) / `scan_files_missing` (ไฟล์ที่พักไว้หายหรือหมดอายุ) |
| GET | `/scans/{id}/page` | ครู | ภาพหน้าเต็ม `image/webp` (`410 image_purged` หลังเผยแพร่และ purge แล้ว) |
| GET | `/responses/{id}/crop?part=main\|final` | ครู / นักเรียนเจ้าของ (หลังเผยแพร่) | ภาพ crop ของข้อ `final` = กรอบคำตอบสุดท้ายของ show_work (`410 image_purged` หลัง `crop_retention_until`) |

- **ขั้นตอน** (`App\Domain\Scans\ScanIngestor`): ตรวจ QR ด้วย `QrSigner` → สิทธิ์ครู → layout เวอร์ชันตาม QR → หน้าและชุดช่องคำตอบต้องตรงกับ layout → นักเรียนอยู่ในห้อง → ไฟล์ WebP ไม่เกิน `SCAN_MAX_PAGE_KB` / `SCAN_MAX_CROP_KB` จากนั้นทำใน transaction ที่ล็อกแถว submission
- **กติกาสแกนซ้ำ** (key = การบ้าน, นักเรียน, หน้า): ยังไม่เผยแพร่ → สแกนใหม่ `active` ของเก่า `superseded` และ response ของหน้านั้น (แถวเดิม, id เดิม) ถูกล้างผลตรวจแล้วตรวจใหม่ เผยแพร่แล้ว → `pending_confirm` เก็บ crop + `regions.json` ไว้ที่ `scans/{school}/{assignment}/pending/{scan}/` จนครูยืนยัน แล้ว submission กลับไป `grading` (ล้าง `published_at`) ทุกคะแนนที่ถูกแทนบันทึก `score_events.action = rescan` สแกนใหม่เขียน crop ทับ path เดิมของ response โดยย้ายไฟล์เดิมไปพักที่ `crops/{school}/{assignment}/replaced/{scan}/` ก่อน (`CropSwap`) ถ้า transaction ล้มจะคืนไฟล์เดิม ถ้าสำเร็จจะลบไฟล์ที่พักไว้
- **ตรวจตอนรับ**: ปรนัยให้คะแนนทันทีจากค่าการฝน (§11.6, `App\Domain\Grading\McqGrader`) พร้อม `review_priority` ตาม §11.8 (`ReviewPriority`) และ `score_events` `ai_scored` (actor `system`) ข้ออื่นเป็น `queued` แล้ว dispatch `GradeScanJob` ลง queue `grading` (Gemini `extract` → fuzzy §11 → คำอธิบาย) ข้อที่ชนิดคำถามถูกแก้หลังพิมพ์จนไม่ตรงกับช่องบนกระดาษเป็น `manual` (`fuzzy_trace.manual_reason`)
- **สถานะ submission** (`SubmissionStatus::refresh`): `awaiting_scan` → `grading` (มีข้อ queued/extracted/failed) → `needs_review` → `reviewed` (ครูตรวจครบ) → `published` (ตั้งโดยการเผยแพร่เท่านั้น)
- ลบคำถามที่มีคำตอบสแกนแล้วไม่ได้ (`409 question_has_responses`)
- **PHP บน server** ต้องรับ request ได้: `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 16M, `max_file_uploads` ≥ 100 (หน้าหนึ่งมีได้ถึง 1 + 2 × จำนวนข้อ ไฟล์ที่เกิน PHP ทิ้งเงียบๆ)

ทดสอบในเครื่องด้วย curl ต้องรัน server ที่ตั้ง ini เหล่านี้ เช่น `cd public && php -d max_file_uploads=100 -d upload_max_filesize=8M -d post_max_size=16M -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`

## ตรวจทาน เผยแพร่ และขอตรวจใหม่ (DESIGN §9.5, §9.7, §13)

| Method | Path | ใคร | หมายเหตุ |
|---|---|---|---|
| GET | `/assignments/{id}/review-queue?band=check\|look\|confident&cursor=&per_page=` | ครู | ทุกข้อของการบ้าน เรียง `manual` ก่อน → ข้อที่ติดป้าย (`suspicious`, `identity_mismatch`) → `review_priority` มากไปน้อย (ข้อที่ยังตรวจไม่เสร็จอยู่ท้าย) แต่ละแถวมี `manual_reason` (เช่น `ai_key_missing`), `flags`, `tab`, `bulk_approvable` ส่วน `meta` มี `missing_ai_key_count` (แบนเนอร์ §13), `counts` ต่อแท็บ, `bulk_approvable_count`, `submissions` (ความคืบหน้าต่อนักเรียน) และ `pending_confirm_scans` แบ่งหน้าด้วย `meta.next_cursor` (keyset ตามลำดับคิว) |
| GET | `/responses/{id}` | ครู | extraction, `fuzzy_trace` ดิบ, `why` (ประโยคภาษาไทยที่อ่านจาก trace), คำอธิบาย, `crop_url` / `final_crop_url`, คำขอตรวจใหม่ และประวัติ `score_events` (`question.rubric_criteria[].criterion_id` คือเลขที่ `extraction.criteria[].criterion_id` อ้าง) |
| PATCH | `/responses/{id}` | ครู | `{final_score, final_understanding, final_error_types?, explanation?, reason?}` ทำเครื่องหมายว่าตรวจทานแล้ว คะแนนต่างจาก `ai_score` ต้องมี `reason` (`422 errors.reason`) คะแนน 0 ถึงเต็มทีละ 0.25 ทุกการเปลี่ยนคะแนน/ระดับความเข้าใจบันทึก `score_events` `override` แก้หลังเผยแพร่ไม่ได้ (`409 submission_published`) ข้อที่ AI ยังตรวจไม่เสร็จ `409 response_grading` |
| POST | `/responses/{id}/regenerate-explanation` | ครู | เรียก Gemini ทันที (ส่งแค่ข้อความที่ถอดได้ + ประเภทข้อผิดพลาดของครู) ข้อที่ได้เต็มหรือเว้นว่างใช้ template `422 explanation_unavailable` / `ai_key_missing` / `ai_key_invalid`, `502 ai_unavailable` จำกัด 20 ครั้ง/นาที |
| POST | `/assignments/{id}/approve-confident` | ครู | `{data: {approved}}` อนุมัติเฉพาะข้อ `confident` ที่ AI ตรวจแล้ว ไม่ติดป้าย ยังไม่ตรวจทาน ไม่มีคำขอตรวจใหม่ค้าง และยังไม่เผยแพร่ บันทึก `bulk_approve` |
| POST | `/submissions/{id}/publish` | ครู | ทุกข้อต้องตรวจทานแล้ว (`409 submission_not_reviewed`) ตั้ง `total_score`, `published_at/by` แล้วยิง event `SubmissionPublished` เรียกซ้ำได้ |
| POST | `/assignments/{id}/publish` | ครู | `{data: {published, already_published, skipped}}` เผยแพร่ทุก submission ที่ตรวจทานครบ |
| GET | `/appeals?status=open\|accepted\|rejected&assignment_id=` | ครู | คำขอของห้องตัวเอง พร้อมชื่อการบ้าน ข้อ คะแนนตอนนี้ และเลขที่นักเรียน |
| PATCH | `/appeals/{id}` | ครู | `{status: accepted\|rejected, teacher_note?, final_score?, final_understanding?}` คะแนนใหม่ได้เฉพาะ `accepted` บันทึก `appeal_accepted` / `appeal_rejected` คำนวณ `total_score` ใหม่ ตอบแล้วตอบซ้ำไม่ได้ (`409 appeal_resolved`) |
| GET | `/student/results` | นักเรียน | เฉพาะของตัวเองที่เผยแพร่แล้ว `{submission_id, title, subject_name, total_score, max_score, published_at}` |
| GET | `/student/results/{submission_id}` | นักเรียน | รายข้อ: คะแนน, ระดับความเข้าใจ, ประเภทข้อผิดพลาด, คำอธิบาย, `crop_url`, คำขอตรวจใหม่, `can_appeal` (ไม่มีค่าของ AI) อย่างอื่น `404` |
| POST | `/student/responses/{id}/appeal` | นักเรียน | `{reason?}` ข้อละครั้ง (`409 appeal_exists`) |
| GET | `/student/mastery` | นักเรียน | ดูส่วน "ITS / EDM" ด้านล่าง |

โค้ดอยู่ใน `app/Domain/Review/` (`ReviewQueue`, `ResponseReviewer`, `Publisher`, `Appeals`, `ScoreExplainer`, `ReviewFlags`, `ScoreRules`) ป้าย `suspicious` / `identity_mismatch` ไม่ใช่คอลัมน์ อ่านจาก `fuzzy_trace` / `extraction`

`PATCH /responses/{id}` รับ `answer_text?` (ไม่เกิน 32 ตัว) = สิ่งที่ครูอ่านได้จากกรอบตัวเลข ใช้เป็น label ของ `training_samples` เท่านั้น (ดู "ข้อมูลเทรน" ด้านล่าง)

## ITS / EDM: แบบฝึกซ่อม, mastery และ analytics (DESIGN §8.5, §9.6, §9.7, §14)

**Mastery (§14.2)** — `skill_observations` เขียนเฉพาะผลที่**เผยแพร่แล้ว**: listener `RecordMasteryObservations` รับ `SubmissionPublished` (และ `AppealResolved` ที่คะแนนเปลี่ยน) แล้ว `MasteryCalculator::recordSubmission()` เขียนหนึ่งแถวต่อ (ข้อ, ทักษะ) ด้วย `score_ratio = final_score / max_points`, `observed_at = published_at` เผยแพร่ซ้ำ (หลังสแกนใหม่/คำขอตรวจใหม่) **แทนที่**แถวเดิม ไม่ซ้ำ สแกนใหม่ที่ครูยืนยัน (`confirm-replace`) ทำให้ผลไม่เผยแพร่ชั่วคราว → event `SubmissionReopened` ลบแถวของ submission นั้นจนกว่าจะเผยแพร่อีกครั้ง (ทั้งสาม event เป็น `ShouldDispatchAfterCommit` listener จึงทำงานหลัง commit) จากนั้นคำนวณ `mastery` ใหม่ทั้งชุดของ (นักเรียน, ทักษะ) นั้นด้วย EWMA `m1 = s1`, `mt = α·st + (1 − α)·mt−1` (α = 0.30 การบ้าน, 0.15 แบบฝึก) เรียงตาม `observed_at` ปัดครึ่งขึ้นที่ 3 ตำแหน่งแบบเดียวกันทุก PHP (`round3`) ระดับที่แสดง: `good` ≥ 0.75, `partial` ≥ 0.4, `not_yet` และ `too_little` เมื่อ `n_obs < 2` ข้อที่ไม่ได้ผูกทักษะ (`question_skill`) ไม่มีผลต่อ mastery

**คลังแบบฝึก (§14.1)** — ใช้ร่วมกันทั้งโรงเรียน แยกตามทักษะ รูปแบบ `answer_key` เหมือน `questions` (§8.3): `numeric` `{accepted, numeric: {value, abs_tol}}`, `short` `{accepted}`, `mcq` `options: [{key, text}]` + `{correct: "B"}` ครูกด "สร้างข้อใหม่" → `GeneratePracticeItemsJob` (queue `default`) เรียก Gemini `practice_gen` (§10.6, `PracticeGenerator` + `PracticeDraft` ตรวจ output: ประเภท, เฉลยไม่ว่าง, mcq ต้องมี 2–6 ตัวเลือกและเฉลยเป็นหนึ่งในนั้น, ข้อตัวเลขต้องมีค่าตัวเลข; ไม่ผ่านลองใหม่ 1 ครั้งแล้ว `invalid_output`) ด้วย key ของครู (`GeminiKeyResolver`) แล้วเก็บเป็น `draft` (`source = ai`) การ**อนุมัติ**ทำได้เฉพาะครูที่สอนวิชาของทักษะนั้น = มีการบ้านในวิชานั้นในห้องที่ตัวเองสอน (`403 subject_not_taught`) ส่วนแก้ไข/เลิกใช้/เขียนเองทำได้ทุกครูในโรงเรียน ยกเว้น**เนื้อหา**ของข้อที่ยัง `approved` อยู่ (โจทย์ ตัวเลือก เฉลย คำอธิบาย) ซึ่งแก้ได้เฉพาะครูที่สอนวิชานั้นและถือเป็นการอนุมัติใหม่ (`approved_by/at` เปลี่ยนเป็นผู้แก้) ครูท่านอื่นต้องเปลี่ยนสถานะเป็น `draft` ก่อนแล้วค่อยแก้ ส่งเนื้อหาเดิมกลับมา (ลำดับ key ต่างกันก็ได้) ไม่นับเป็นการแก้ `GEMINI_FAKE=true` ตอบข้อจำลอง 3 ประเภทวนไป (marker ในชื่อทักษะ: `[fake:invalid]`, `[fake:error]`, `[fake:practice-bad-key]`)

**นักเรียน** — `GET /student/practice` เลือกทักษะที่ `mastery.value < 0.75` เรียงจากต่ำสุด ทักษะละไม่เกิน 3 ข้อจากคลังที่ `approved` ของโรงเรียนตัวเอง ไม่ซ้ำข้อที่ทำในรอบ 7 วัน พร้อมลิงก์ทบทวน (`learning_resources`) นักเรียน**ไม่ได้รับ** `answer_key`/`explanation` `POST /student/practice/{item_id}/attempts {answer}` ตรวจทันทีแบบ deterministic ด้วย `AnswerMatcher` (§11.4: ตัวเลข = ตรง/ใกล้เคียง ≤ 0.6, ข้อความ = flexible, mcq = ตัวอักษรหรือข้อความของตัวเลือก) ตอบ `{score_ratio, correct, explanation (เฉพาะไม่เต็ม), mastery}` เขียน `practice_attempts` + observation `practice` แล้วคำนวณ mastery ทันที ทำข้อเดิมซ้ำใน 7 วันไม่ได้ (`409 practice_already_attempted`) การตรวจซ้ำ+บันทึกอยู่ใต้ cache lock ต่อ (นักเรียน, ข้อ) (`cache_locks` ของ database cache store; รอได้ 2 วินาที) กดส่งสองครั้งพร้อมกันจึงนับครั้งเดียว

| Method | Path | ใคร | หมายเหตุ |
|---|---|---|---|
| GET | `/practice-items?skill=&status=&cursor=` | ครู | คลังของโรงเรียน (ใหม่ก่อน) พร้อม `skill` |
| POST | `/practice-items` | ครู | `{skill_id, answer_type, prompt_text, options?, answer_key, explanation, status?}` ข้อที่ครูเขียนเอง (`source = teacher`) — เพิ่มจาก DESIGN |
| PATCH | `/practice-items/{id}` | ครู | แก้ field ใดก็ได้ของข้อ; `status` `draft\|approved\|retired` อนุมัติตั้ง `approved_by/at`; แก้เนื้อหาข้อที่ยัง `approved` ต้องเป็นครูที่สอนวิชานั้น (`403 subject_not_taught`) และตั้ง `approved_by/at` ใหม่ |
| POST | `/skills/{id}/practice-items/generate` | ครู | `{count?: 1–20 (5)}` → `202 {data: {queued, skill_id, count}}` จำกัด 10 ครั้ง/นาที ไม่มี Gemini key (ของครูหรือเซิร์ฟเวอร์) → `422 ai_key_missing` ไม่เข้าคิว (key ที่หายไประหว่างรอคิว: job จบพร้อม log `practice_gen.no_ai_key`) |
| GET / POST | `/skills/{id}/resources` | ครู | ลิงก์ทบทวนของทักษะ `{title, url (http/https)}` |
| PATCH / DELETE | `/resources/{id}` | ครู | แก้/ลบลิงก์ของโรงเรียนตัวเอง — เพิ่มจาก DESIGN |
| GET | `/assignments/{id}/analytics` | ครู | `{published_count, min_count_for_r: 20, items: [{question_id, position, type, max_points, prompt_text, n, p, r}], most_missed: [question_id…], skill_error_counts: [{skill, error_type, count}]}` จากผลที่เผยแพร่แล้วเท่านั้น (§14.3): `p` = mean(final/max), `r` = mean กลุ่มสูง 27 % − กลุ่มต่ำ 27 % ตาม `total_score` (`null` ถ้าเผยแพร่ < 20 คน), heatmap นับ `final_error_types` ต่อทักษะของข้อ |
| GET | `/classrooms/{id}/mastery` | ครู | `{skills, students: [{id, name, student_number}], cells: [{student_id, skill_id, value, n_obs, level}]}` |
| GET | `/students/{id}/mastery` | ครู | นักเรียนในห้องที่ตัวเองสอน (`404` อื่นๆ) รูปแบบเดียวกับของนักเรียน + `student` |
| GET | `/student/mastery` | นักเรียน | `{data: [{skill, skill_id, value, n_obs, level, updated_at}] (อ่อนสุดก่อน = เรียงตาม `value` เหมือน `/student/practice`; `too_little` เป็นแค่ป้ายเมื่อ `n_obs < 2`), meta: {available: true, weaknesses: [skill_id × 3 แรก]}}` |
| GET | `/student/practice` | นักเรียน | `{data: [{skill, skill_id, mastery, items: [{id, answer_type, prompt_text, options}], resources}], meta}` |
| POST | `/student/practice/{item_id}/attempts` | นักเรียน | `{answer}` → `201` ดูข้างบน จำกัด 60 ครั้ง/นาที ข้อที่ยังไม่อนุมัติ/ของโรงเรียนอื่น `404` |

**Export สำหรับ notebook BKT (§14.4)** — `php artisan eduvision:export-observations [path] [--school=ID] [--since=YYYY-MM-DD]` เขียน CSV คอลัมน์ `id,student_id,skill_id,source,response_id,practice_attempt_id,score_ratio,observed_at` (UTC) ค่าเริ่มต้นลง `storage/app/private/exports/` (บน Plesk รันผ่าน Scheduled Task "Run now" แล้วดาวน์โหลดจาก File Manager) วางไฟล์ที่ `ml/data/skill_observations.csv`

**ข้อมูลเทรน CNN (§8.6, §12.3)** — เมื่อครูแก้คะแนนข้อตัวเลข (`short` ที่มี `numeric` หรือ `show_work` ที่เฉลยสุดท้ายเป็นตัวเลข) ในโรงเรียนที่ `allow_training_data = true`, `TrainingSamples` คัดลอกภาพ crop (กรอบคำตอบสุดท้ายสำหรับ `show_work`) ไป `training/{school}/{response}.webp` (นอกนโยบายลบภาพ §7.3) แล้วบันทึก `training_samples` (`source = teacher_correction`, `writer_key` = sha256 ของ student id, หนึ่งแถวต่อข้อ) label = `answer_text` ที่ครูส่งมา (ต้องเป็น `0-9 . - /` เลขไทยแปลงให้) ไม่งั้นถ้าครูให้เต็ม = ค่าเฉลย ถ้าแก้บางส่วนโดยไม่บอกสิ่งที่อ่านได้ ไม่บันทึก

## โมเดลบนมือถือ (DESIGN §8.6, §9.8, §12)

| Method | Path | ใคร | หมายเหตุ |
|---|---|---|---|
| GET | `/ml/models/active?name=digit_crnn` | ครู/นักเรียน | `{id, name, version, sha256, size_bytes, download_url, metrics, created_at}` `metrics` คือ `metrics.json` ทั้งก้อน (decode contract, `abstain_below`) `404 model_not_found` ถ้ายังไม่เปิดใช้เวอร์ชันใด |
| GET | `/ml/models/{id}/file` | ครู/นักเรียน | ไฟล์ .tflite (`application/octet-stream`, header `X-Checksum-Sha256`) แอปตรวจ sha256 ก่อนสลับไปใช้ `410 model_file_missing` ถ้าไฟล์ถูกลบ |

- ไฟล์อยู่บน private disk ที่ `models/{name}/{version}.tflite` (§7.3) เปิดใช้ได้ทีละเวอร์ชันต่อชื่อ (`ModelVersion::activate()`)
- ในเครื่อง/ตอน deploy: `php artisan eduvision:register-model ml/models/digit_crnn/0.1.0 [--no-activate]` อ่าน `metrics.json` (`name`, `version`, `file`, `sha256`) ตรวจ sha256 ของไฟล์ คัดลอกขึ้น disk แล้ว upsert `model_versions` (เปิดใช้ทันทีถ้าไม่ใส่ `--no-activate`) ไฟล์ `.tflite` ไม่อยู่ใน git ต้อง export/คัดลอกมาก่อน (ดู `ml/README.md`)
- Filament: เมนู "โมเดลบนมือถือ" อัปโหลด .tflite + วาง metrics.json, เปิดใช้งาน, ลบ (ลบไฟล์ด้วย) และเมนู "การเรียก AI" อ่าน `ai_calls` (กรองตามงาน/ผล/key, รวม token ท้ายตาราง)
- การอัปโหลดผ่าน Filament ขึ้นกับ PHP ของเซิร์ฟเวอร์: ไฟล์ที่เกิน `upload_max_filesize` (Plesk มักตั้งไว้ 2M) ล้มเหลวที่ Livewire ก่อนถึง validation ของฟอร์ม (ฟอร์มรับถึง 8 MB) → ตั้ง `upload_max_filesize` ≥ 8M และ `post_max_size` ≥ 16M ใน Plesk > PHP Settings (ค่าเดียวกับที่ `POST /scans` ต้องการอยู่แล้ว ดูหัวข้อสแกน) หรือลงทะเบียนโมเดลด้วย `php artisan eduvision:register-model <dir>` ผ่าน Scheduled Task "Run now" ซึ่งไม่ขึ้นกับขนาดอัปโหลด (`digit_crnn 0.1.0` ปัจจุบัน 1.29 MB ผ่านทั้งสองทาง)

## Push notification (FCM, DESIGN §9.9)

- `Notifier` ผูกเป็น `FcmNotifier` เมื่อ `FIREBASE_CREDENTIALS` ชี้ไปที่ไฟล์ service account ที่ใช้ได้ (อยู่นอก document root) ไม่งั้นเป็น `LogNotifier` ที่เขียน `notify.*` ลง log แทน
- FCM HTTP v1 เรียกด้วย Laravel HTTP client: เซ็น JWT ของ service account ด้วย `firebase/php-jwt` (RS256) แลก access token ที่ `oauth2.googleapis.com/token` แล้ว cache แบบเข้ารหัสจนเกือบหมดอายุ ส่งทีละเครื่อง token ที่ FCM บอกว่าใช้ไม่ได้ (`UNREGISTERED`, `INVALID_ARGUMENT`, `SENDER_ID_MISMATCH`) ถูกลบจาก `device_tokens` ได้ `401` จะขอ token ใหม่แล้วลองอีกครั้งเดียว
- ข้อความ 4 แบบของ §9.9 (ไม่มีคะแนน) และ `data.type` ที่แอปใช้เปิดหน้า: `grading_done` {assignment_id}, `results_published` {submission_id, assignment_id}, `appeal_opened`, `appeal_resolved` {submission_id, appeal_id} และของ §18.2: `retake_requested` {assignment_id} (ครูตีกลับงานใน Google Classroom ให้ถ่ายใหม่ body มีเหตุผลของครู)
- ส่งจาก queued listener บน queue `default` (worker ของ cron): `SubmissionPublished` → `NotifyStudentOfPublishedResult`, `AppealOpened` → `NotifyTeacherOfAppeals` (หน่วง 60 วินาทีแล้วส่งครั้งเดียวต่อชุดพร้อมจำนวนรวม), `AppealResolved` → `NotifyStudentOfAppealResolution`, `RetakeRequested` → `NotifyStudentOfRetakeRequest`
- ตรวจบน server: Scheduled Task "Run now" `artisan eduvision:fcm-check` (ขอ access token) หรือ `artisan eduvision:fcm-check --user=<id>` (ส่งข้อความทดสอบไปทุกเครื่องของผู้ใช้นั้น)

## Google Classroom (DESIGN §18)

ครูเชื่อมบัญชี Google ของตัวเอง (นักเรียนใช้ Classroom ตามปกติ) server เก็บเฉพาะ refresh token แบบเข้ารหัส (`google_accounts.encrypted_refresh_token`, cast `encrypted`) แล้วเรียก Classroom v1 และ Drive v3 ตรงด้วย Laravel HTTP client (`app/Domain/Google/GoogleApi`) **ไม่ใช้ `google/apiclient`** รูปของนักเรียนไม่ผ่าน server: แอปดาวน์โหลดไฟล์แนบจาก Drive ด้วยสิทธิ์ของครูบนเครื่อง รัน pipeline สแกนเดิม แล้วส่ง `POST /scans` ตามปกติ

ตั้งใน `.env`: `GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET` (OAuth client ชนิด Web application ตัวเดียวกับ `GOOGLE_SERVER_CLIENT_ID` ของแอป Android ตาม KICKOFF ส่วนที่ 6), `GOOGLE_OAUTH_REDIRECT_URI` (callback ของการเชื่อมผ่านเบราว์เซอร์ ด้านล่าง ว่าง = `APP_URL` + `/google/oauth/callback`), `GOOGLE_TIMEOUT`, `GOOGLE_CLASSROOM_APP_LINK` (ลิงก์แอปที่แนบท้ายคำสั่งของงาน) ถ้าไม่ตั้ง client ทุก endpoint ในตารางนี้ยกเว้น `GET /google/status` ตอบ `503 google_not_configured` ก่อนเงื่อนไขอื่นทั้งหมด (`classroom_not_linked`, `not_posted`, ห้องหรืองานของคนอื่น ฯลฯ แต่หลัง 401/403 ของ auth และ role: middleware `google.configured`, `App\Http\Middleware\EnsureGoogleConfigured`) ส่วน `GET /google/status` ตอบ `configured: false` (และ `server_configured: false` ชื่อเดิม) แอปจึงซ่อนส่วน Classroom ทั้งหมด ส่วนอื่นของระบบทำงานปกติ

**เชื่อมบัญชีได้สองทาง** ทั้งสองทางเก็บบัญชีด้วยโค้ดเดียวกัน (`GoogleAccounts::connect`: ตรวจ scope ครบ, refresh token เข้ารหัส, `userProfiles/me`):

1. **แอป Android ที่มี `GOOGLE_SERVER_CLIENT_ID`**: `google_sign_in` ให้ server auth code → `POST /google/connect` แลก code โดย `redirect_uri` ว่าง (ไม่ใช้ `GOOGLE_OAUTH_REDIRECT_URI`)
2. **ผ่านเบราว์เซอร์** (Flutter web, หรือ build ที่ไม่มี client id): `POST /google/oauth/url` → แอปเปิดหน้า consent ของ Google → Google พาเบราว์เซอร์กลับมาที่ `GET /google/oauth/callback` (route เว็บ ไม่ต้อง login) → server แลก code ด้วย redirect URI เดิม แล้วแสดงหน้าผลภาษาไทย (Blade `resources/views/google/oauth-result.blade.php`: ไม่มี asset ภายนอก ไม่มี script, `noindex`, CSP `default-src 'none'`, `Cache-Control: no-store` ไม่มี session/cookie) → แอปถาม `GET /google/status` ทุก 3 วินาทีจนเห็นว่าเชื่อมแล้ว
   - `state` = 32 byte สุ่ม (base64url) เก็บใน cache store `database` 10 นาที ชี้ไปที่ user id ของครู (เก็บเฉพาะ SHA-256 ของ state, `App\Domain\Google\GoogleOAuthStates`) ใช้ได้ครั้งเดียว: ถูกลบตั้งแต่ callback ครั้งแรกไม่ว่าผลเป็นอะไร ไม่มี/หมดอายุ/ใช้แล้ว → หน้า 400 ก่อนเรียก Google
   - หน้าผล: สำเร็จ (บอกอีเมล Google และชื่อครู) / `error=access_denied` → "ยกเลิกการเชื่อม" (200) / error อื่นจาก Google → 400 / scope ไม่ครบ → 422 พร้อมรายการสิทธิ์ที่ยังไม่ได้ติ๊กเป็นภาษาไทย (`GoogleScopes::LABELS`) และ revoke grant ที่ไม่ครบ / แลก code ไม่ได้ → 422 `google_code_invalid`, 503, 502 ตาม `GoogleErrors` / server ไม่ได้ตั้ง client → 503 / บัญชีครูถูกระงับระหว่างทาง → 403 ไม่มี code, state, token หรือ secret ในหน้า log หรือ redirect
   - **ต้องลงทะเบียน redirect URI** ใน Google Cloud Console → Credentials → OAuth client แบบ Web → *Authorized redirect URIs* ให้ตรงกับ `GOOGLE_OAUTH_REDIRECT_URI` ทุกตัวอักษร (scheme, host, port, path): เครื่อง dev `http://127.0.0.1:8000/google/oauth/callback` (ถ้าใช้ `http://localhost:8000` ต้องลงทะเบียนแบบนั้นแทน เพราะ Google ถือเป็นคนละ URI), server จริง `https://teacherhelper.phuwish.com/google/oauth/callback` ไม่ตรง → Google แสดง `redirect_uri_mismatch` เองและไม่กลับมาที่ server; *Authorized JavaScript origins* ไม่จำเป็นสำหรับทางนี้ (หน้าเว็บของแอปไม่ได้เรียก Google เอง)
   - callback จำกัด 20 ครั้ง/นาที/IP (`throttle:google-oauth-callback`)

| Method | Path | ใคร | หมายเหตุ |
|---|---|---|---|
| POST | `/google/oauth/url` | ครู | ตอบ `{data: {url}}` หน้า consent ของ Google (`client_id`, `redirect_uri`, `response_type=code`, scope ทั้งหมดของ `GoogleScopes::REQUIRED`, `access_type=offline`, `prompt=consent`, `include_granted_scopes=true`, `state`) state ใหม่ทุกครั้ง ใช้ limiter `google` |
| GET | `/google/oauth/callback` (เว็บ ไม่มี `/api/v1`) | เบราว์เซอร์ | `?state&code` หรือ `?state&error` ตอบหน้า HTML ภาษาไทย (ดูด้านบน) |
| POST | `/google/connect` | ครู | `{server_auth_code}` แลกที่ `oauth2.googleapis.com/token` ต้องได้ครบทุก scope ใน `GoogleScopes::REQUIRED` (ขาด → `422 google_scope_missing` พร้อม `errors.scopes` และ revoke grant ที่ไม่ครบทิ้ง) อ่านบัญชีจาก `userProfiles/me` ตอบ `{data: status}` `422 google_code_invalid` (code หมดอายุ/ใช้แล้ว), `422 google_refresh_token_missing`, `409 google_account_in_use` (บัญชี Google เดียวกันเชื่อมกับครูอีกคนอยู่) |
| GET | `/google/status` | ครู | `{connected, email, scopes[], needs_reconnect, last_error, connected_at, configured, server_configured}` (`configured` = `server_configured` = server มี client id + secret) |
| DELETE | `/google/disconnect` | ครู | revoke ที่ Google (best effort ผลอยู่ใน `revoked`) แล้วลบแถวเสมอ เรียกซ้ำได้ |
| GET | `/google/courses` | ครู | คอร์ส `ACTIVE` ที่ครูสอน (`teacherId=me`) `[{course_id, name, section, linked_classroom_id}]` |
| POST / DELETE | `/classrooms/{id}/google-link` | ครู | `{course_id}` ต้องเป็นคอร์สของครู (`422 errors.course_id`) `201` ผูกใหม่ / `200` เปลี่ยนคอร์ส `409 course_already_linked` (ห้องอื่นผูกอยู่), `409 classroom_has_google_posts` (ห้องที่มีงานโพสต์แล้วย้ายคอร์สไม่ได้) DELETE → `204` การจับคู่นักเรียนคงอยู่ |
| GET | `/classrooms/{id}/google-roster` | ครู | นักเรียนในคอร์ส `[{google_user_id, name, email, suggested_student_id, matched_student_id}]` เสนอคู่ด้วย `RosterMatcher` (ชื่อที่ normalize แล้ว → สลับลำดับคำ → ชื่อจริงที่ไม่ซ้ำ ชื่อกำกวมไม่เสนอ) `422 classroom_not_linked` |
| PUT | `/classrooms/{id}/google-roster` | ครู | `{matches: [{google_user_id, student_id\|null}]}` บัญชีที่ไม่ส่งมาคงคู่เดิม นักเรียนคนเดียวกับสองบัญชี → `422` เก็บที่ `classroom_students.google_user_id/google_email` (unique ต่อห้อง) แถว import ที่ยังไม่สแกนย้ายตามคู่ใหม่ |
| POST | `/assignments/{id}/google-post` | ครู | `{attach_blank_worksheet?, instructions?, due_at?}` การบ้านต้อง `ready` และห้องผูกคอร์สแล้ว สร้าง courseWork `PUBLISHED` ที่ `maxPoints` = คะแนนเต็ม พร้อมคำสั่งถ่ายรูปตาม §18.2 ถ้าแนบใบงานสำรอง อัปโหลด PDF ที่ไม่มีชื่อ (QR `student_id = 0`, `WorksheetPdfRenderer` เดิม) ขึ้น Drive ของครู (`drive.file`, multipart) แล้วแนบแบบ `VIEW` ตอบ `201 {course_work_id, alternate_link, drive_file_id, has_blank_worksheet, posted_at}` `409 already_posted` / `assignment_not_ready` / `google_post_in_progress`, `422 classroom_not_linked`, `503 qr_key_missing` การบ้านที่โพสต์แล้วลบไม่ได้ (`409 assignment_posted`) |
| GET | `/assignments/{id}/google-submissions` | ครู | sync `studentSubmissions` ที่ `TURNED_IN` และมีไฟล์แนบใน Drive ลง `classroom_submission_imports` (จับคู่ `userId` → นักเรียนตาม roster) แล้วคืน `[{id, google_submission_id, google_user_id, student: {id, name, student_number}\|null, state, attachments: [{drive_file_id, title, mime_type}], alternate_link, retake_reason, last_error, grade_pushed_at, updated_at}]` เรียงตามเลขที่ แถวกลับเป็น `new` เมื่อไฟล์แนบเปลี่ยนหรือส่งใหม่หลังถูกตีกลับ (`updateTime` อย่างเดียวไม่พอ เพราะการให้คะแนนก็เปลี่ยนค่านี้) `409 not_posted` |
| POST | `/google-submissions/{id}/return` | ครู | `{reason}` เรียก `studentSubmissions.return` → state `returned_for_retake` และแจ้งนักเรียนด้วย push `retake_requested` พร้อมเหตุผล (Classroom API ไม่มี private comment) `409 google_submission_not_returnable` (ตีกลับไปแล้ว/ให้คะแนนแล้ว) |
| POST | `/assignments/{id}/google-grades/retry` | ครู | `202 {data: {queued}}` ส่ง `PushClassroomGradeJob` ให้ submission ที่เผยแพร่แล้วซึ่งเป็น `grade_failed` หรือยังไม่เคยส่ง (นักเรียนจับคู่แล้วแต่ไม่มีแถว) |
| GET | `/student/retake-requests` | นักเรียน | (เพิ่มจาก §18.6) งานของตัวเองที่ครูตีกลับ `[{id, assignment: {id, title}, reason, requested_at, alternate_link}]` และ `GET /student/results/{id}` มี `retake_reason` |

- **state ของ `classroom_submission_imports`**: `new` (มีรูปให้สแกน) → `imported` (`POST /scans` รับรูปแล้ว) → `graded` (คะแนนถึง Classroom แล้ว, `grade_pushed_at`) หรือ `grade_failed` (`last_error` ภาษาไทย แสดงในแอปครู) `returned_for_retake` (ตีกลับ, `retake_reason`) กลับเป็น `new` เมื่อนักเรียนส่งใหม่ `needs_retake` สงวนไว้ให้แอปใช้
- **สแกนจาก Classroom** (§18.3): `POST /scans` รับ `meta.source` (`camera` ค่าเริ่มต้น หรือ `classroom`) และ `meta.google_submission_id` ซึ่งต้องเป็นแถวที่ sync แล้วของการบ้านนั้น (`422 google_submission_unknown`) นักเรียน = คนใน QR ใบงานสำรอง (`student_id = 0`) ใช้นักเรียนที่จับคู่กับบัญชีผู้ส่ง (`422 student_unknown` ถ้ายังไม่จับคู่ และเสมอเมื่อมาจากกล้อง) QR เป็นคนอื่นกว่าผู้ส่ง → รับตาม QR และติดป้าย `identity_mismatch` ไว้ใน `responses.fuzzy_trace` (คิวตรวจทานยกขึ้นก่อน ป้ายตามไปถึงสแกนซ้ำที่รอยืนยันด้วย) `scans.source/google_submission_id` บันทึกที่มา แถว import เป็น `imported` โดย `student_id` ยังเป็นนักเรียนที่จับคู่กับบัญชีผู้ส่ง (ไม่ใช่คนใน QR เมื่อ `identity_mismatch`) จะเป็นคนใน QR ก็ต่อเมื่อบัญชีผู้ส่งยังไม่ได้จับคู่กับใคร
- **ส่งคะแนนกลับ**: `SubmissionPublished` (และ `AppealResolved` ที่คะแนนเปลี่ยน) → listener `QueueClassroomGradePush` → `PushClassroomGradeJob` บน queue `default` (unique ต่อ submission) ใช้บัญชี Google ของครูที่โพสต์งาน เรียก `studentSubmissions.patch?updateMask=assignedGrade` = `total_score` แล้ว `return` งานที่นักเรียนส่งเป็นกระดาษหา submission ด้วย `userId` ของบัญชีที่จับคู่ (ถ้านักเรียนไม่ได้กดส่งใน Classroom ใส่คะแนนได้แต่ return ไม่ได้ บันทึกไว้ใน `last_error`) ส่งคะแนนให้เฉพาะ submission ของบัญชี Google ที่จับคู่กับนักเรียนคนนั้นเท่านั้น แถวที่บัญชีผู้ส่งไม่ตรง (สแกนติดป้าย `identity_mismatch` หรือจับคู่ roster ใหม่หลังสแกน) หรือนักเรียนยังไม่ได้จับคู่ → `grade_failed` พร้อมเหตุผลโดยไม่เรียก Google (จับคู่แล้วกด retry ได้) Google ล่ม/429/5xx ลองใหม่หลัง 1 และ 5 นาที ล้มครบ → `grade_failed`
- **ข้อผิดพลาดจาก Google** (`GoogleApiException` → `GoogleErrors`): `invalid_grant` ตอนขอ access token (หรือ Classroom/Drive ตอบ 401 ซ้ำหลังลองด้วย token ใหม่แล้ว) → `google_accounts.last_error = invalid_grant`, status `needs_reconnect: true` และทุก endpoint ตอบ `409 google_reconnect_required` โดยไม่เรียก Google อีกจนครูเชื่อมใหม่ (OAuth app โหมด Testing: refresh token หมดอายุใน 7 วัน) scope ถูกถอนภายหลัง → `422 google_scope_missing` + `needs_reconnect`; `@ProjectPermissionDenied` (courseWork ที่ไม่ได้สร้างผ่านแอป) → `409 project_permission_denied`; อื่นๆ `409 google_permission_denied` / `google_not_found` / `google_failed_precondition`, `503 google_api_disabled` / `google_unavailable`, `502 google_error` ยังไม่เชื่อมบัญชี → `409 google_not_connected`
- access token แลกจาก refresh token แล้ว cache แบบเข้ารหัสจน 5 นาทีก่อนหมดอายุ (`GoogleAccessTokens`) ได้ `401` ทิ้ง cache แล้วลองใหม่ครั้งเดียว ไม่มี token ใน response, log หรือ payload ของ job (มี test ตรวจทุกทาง) endpoint ที่เรียก Google จำกัด 30 ครั้ง/นาทีต่อครู (`throttle:google`)
- test ทั้งหมดใช้ `Http::fake` (`tests/Feature/Google/GoogleFixtures`: token, revoke, courses, students, courseWork, studentSubmissions, patch, return, Drive upload; `GoogleOAuthBrowserFlowTest`: url + state, callback สำเร็จ/ใช้ซ้ำ/หมดอายุ/`access_denied`/แลก code ไม่ได้/scope ขาด/ไม่ได้ตั้งค่า/สิทธิ์) ทดสอบกับ Google จริงต้องมี Cloud project และคอร์สทดลองตาม KICKOFF ส่วนที่ 6

## Queue และ heartbeat

Plesk ไม่มี process ค้าง จึงใช้ Scheduled Task ทุก 1 นาทีเรียก

```
php artisan eduvision:queue-work
```

command นี้ (1) dispatch `QueueHeartbeatJob` ลง table `jobs` แล้ว (2) รัน `queue:work --queue=grading,default,pdf --stop-when-empty --max-time=50` (option ตาม DESIGN §7.2) job เขียนเวลาไว้ใน cache key `queue.last_run_at` ซึ่ง `GET /health` อ่านออกมา ถ้าค่าเก่ากว่า `HEARTBEAT_MAX_AGE_MINUTES` (ค่าเริ่มต้น 3 นาที) `status` จะเป็น `degraded` แปลว่า cron + artisan + database queue ไม่ครบวงจร

option เดียวที่มีคือ `--memory=<MB>` (ค่าเริ่มต้น 128 เท่า `queue:work`): เกณฑ์ที่ worker หยุดเองอย่างสุภาพหลังจบ job (exit code 12) เพิ่มได้เมื่อ `memory_limit` ของ PHP บน hosting สูงกว่านั้น test ที่รันคำสั่งนี้ส่ง `--memory=1024` เพราะการรันแบบ coverage (pcov) ถือข้อมูลของทั้ง suite ไว้ใน process เดียว

## ลบไฟล์ตามนโยบาย (DESIGN §7.2, §7.3)

Scheduled Task ทุกวัน 02:00 เรียก

```
php artisan eduvision:purge-images
```

- PDF ใบงาน (มีชื่อนักเรียน) ที่สร้างมาเกิน 30 วัน: แถวใน `worksheet_prints` ยังอยู่ แต่เปลี่ยนเป็น `failed` พร้อม `error` "ไฟล์ใบงานถูกลบแล้วเพราะเก็บไว้ครบ 30 วัน กรุณาสั่งพิมพ์ใหม่" และไฟล์ใต้ `worksheets/` ที่ไม่มีแถวชี้ถึงแต่เก่ากว่า 30 วันก็ถูกลบด้วย
- ภาพหน้าเต็ม `scans/{school}/{assignment}/{scan}.webp` ของ submission ที่เผยแพร่แล้ว (scan `active`/`superseded`) → `scans.page_image_path = NULL`
- สแกนซ้ำที่รอครูยืนยัน (`pending_confirm`) เก็บภาพหน้าเต็มและที่พักไฟล์ไว้ไม่เกิน 30 วัน (`ScanRetention::PENDING_RESCAN_DAYS`) และไม่เกิน `crop_retention_until` จากนั้นหมดอายุ: ลบภาพหน้าเต็มและที่พักไฟล์ `page_image_path = NULL` และ scan เป็น `superseded` (ยืนยันไม่ได้แล้ว ตอบ `409 scan_files_missing`)
- ภาพ crop `crops/{school}/{assignment}/{response}[_final].webp` ของสแกนที่ทำจนถึง `schools.crop_retention_until` เมื่อวันนั้นผ่านไปแล้ว → `crop_path`/`final_crop_path = NULL` คะแนนและค่าที่อ่านได้ยังอยู่
- ไฟล์ค้างจาก request ที่ถูกตัดกลางทาง (เก่ากว่า 24 ชั่วโมง): โฟลเดอร์ `pending/{scan}` ของสแกนที่ไม่ได้รอยืนยันแล้ว และโฟลเดอร์ `crops/{school}/{assignment}/replaced/{scan}`

## ความปลอดภัย (DESIGN §16.1, B7)

สิ่งที่ middleware/โค้ดทำให้ทุก request และ test ที่ล็อกไว้ใน `tests/Feature/Security/`

| เรื่อง | ที่ทำ | test |
|---|---|---|
| ทุก route ใต้ `/api/v1` ต้องมีแถวใน matrix: guest 401, ผิด role 403, ครูโรงเรียนอื่น 404 (หรือ 403 เมื่อไม่ได้ค้นหาแถวก่อน), ครูร่วมโรงเรียนที่ไม่ใช่เจ้าของ 403/404, เพื่อนร่วมห้อง 404, บัญชี `disabled` 403 `account_not_active`, admin ไม่มีสิทธิ์ API | policy ต่อ action + `role:` middleware | `AuthorizationMatrixTest` (**เพิ่ม route ใหม่ต้องเพิ่มแถว** ไม่งั้น `test_every_api_route_is_in_the_matrix` แดง) |
| `role:teacher` / `role:student` / `role:teacher,student` ตรวจทั้ง `users.role` และ ability ของ token | `App\Http\Middleware\EnsureRole` | matrix + `test_a_token_with_a_foreign_ability_is_forbidden_everywhere` |
| FormRequest ของ endpoint ที่มี `{id}` ตรวจ body **หลัง** policy: ขอแถวของคนอื่นได้ 403/404 เสมอ ไม่ใช่ 422 ที่บอกว่าแถวมีอยู่ | trait `App\Http\Requests\Concerns\ValidatesAfterAuthorization` (controller ต้องอ่าน body ผ่าน `validated()`/`safe()` เท่านั้น และเรียกก่อน side effect เช่นการเรียก Google) | matrix |
| id ต้องเป็นตัวเลข 1–18 หลัก (`Route::pattern`) ไม่งั้น 404 ก่อนถึง controller; body ผิดรูป/ใหญ่เกิน/ควบคุมอักขระ → 422 ใน envelope; mass assignment ของ `school_id`, `teacher_id`, `role`, `status`, `id`, คอลัมน์ AI ถูกทิ้ง | `routes/api.php`, `$fillable`, FormRequest | `InputHardeningTest` |
| headers ทุก response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, ลบ `X-Powered-By`; เฉพาะ `api/*`: `Cache-Control: no-store, private` (ยกเว้น controller ที่ตั้งเองเช่นไฟล์โมเดล), `X-Robots-Tag: noindex`, CSP `default-src 'none'` สำหรับ JSON; `Strict-Transport-Security` เฉพาะ HTTPS | `App\Http\Middleware\SecurityHeaders` (append ใน `bootstrap/app.php`) | `SecurityHeadersTest` |
| rate limit (named limiter ใน `AppServiceProvider` ทุกตัว แต่ละตัวมี bucket ของตัวเอง): `teacher-auth` register+login รวมกัน 10/นาที/IP (จงใจใช้ bucket เดียว), `student-auth` 120/นาที/IP + PIN 10/นาที/บัญชี, `api` 120/นาที/ผู้ใช้ทุก route, `google` 30/นาที/ครู, ต่อผู้ใช้แยกกัน: `ai-key` (PUT `me/ai-key`) 10, `explanation` 20, `practice-generate` 10, `appeal` 30, `practice-attempt` 60 ต่อนาที (**ห้ามใช้ `throttle:N,M` เปล่า** เพราะ key เป็น id ผู้ใช้อย่างเดียว ทุก route ที่ใช้จะนับรวมกัน); PIN ผิด 5 ครั้งล็อก 15 นาทีที่บัญชี; token หมดอายุ/ปลอม/ถูกลบ → 401; logout ยกเลิก token | `AppServiceProvider`, `StudentAuthenticator`, Sanctum | `AuthHardeningTest` (`test_every_api_throttle_is_a_registered_named_limiter`, `test_each_throttled_route_has_its_own_bucket`), `Api/StudentAuthTest`, `Api/TeacherAuthTest` |
| payload ที่ส่ง Gemini มีเฉพาะ crop ของช่องคำตอบ + โจทย์/เฉลย/rubric: ไม่มีชื่อ, เลขที่, ห้อง, โรงเรียน, ภาพหน้าเต็ม, QR, key (render body จริงของ `HttpGeminiClient::payload()` แล้วค้น) และ `ai_calls`/log ก็ไม่มี | `ExtractionRequests`, `ExplanationRequests` | `GeminiPayloadPrivacyTest` |
| prompt injection ผ่านลายมือ: `tests/fixtures/injection/*.png` (+ `manifest.json`) ผ่าน `GradeScanJob` → `suspicious_instruction` → บนสุดของคิว, `bulk_approvable: false`, ไม่สร้างคำอธิบาย; system instruction บอกว่าภาพเป็นข้อมูล และ schema บังคับ flag ตรวจกับโมเดลจริงได้ด้วย `php artisan eduvision:gemini-check --injection` (ต้องมี key, 1 request ต่อภาพ, ส่งแต่ละภาพเป็น crop ของข้อเติมคำผ่าน prompt/schema `extract` ของจริง แล้วเทียบกับ `expect_suspicious_instruction` ไม่ตรงแม้ภาพเดียว = exit 1) | `FakeGeminiClient` อ่าน marker `[fake:...]` จาก tEXt chunk ของ PNG เหมือนโมเดลที่อ่านข้อความในภาพ | `PromptInjectionTest`, `Console/GeminiCheckCommandTest` |
| Gemini key ของครูไม่ออกทาง response/log/`ai_calls`/แถว DB แม้ตอน Google ปฏิเสธ key; key กลางก็เช่นกัน | `TeacherApiKey` (encrypted), `GeminiKeyResolver` | `SecretsHygieneTest` |
| ไม่มี `env()` นอก `config/` (production ใช้ `optimize`), ทุกตัวแปรของ Krucheck อยู่ใน `.env.example`, `.env.example` ไม่มีค่าจริง, suite ไม่แตะ Gemini จริง; suite ไม่อ่าน config/route/event cache (`phpunit.xml` ชี้ `APP_CONFIG_CACHE`/`APP_ROUTES_CACHE`/`APP_EVENTS_CACHE` ไปที่ไฟล์ที่ไม่มีอยู่ใน `storage/framework/testing/` และ `tests/TestCase::setUp` fail ถ้าเจอ cache) จึงรัน `php artisan optimize` คู่กับ suite ได้โดย test ไม่ไปใช้ค่าใน `.env` (key Gemini จริง, database ในเครื่อง) | `phpunit.xml`, `tests/TestCase.php` | `ConfigCacheSafetyTest` |
| secret scan | pre-commit hook (`.git/hooks`, ในเครื่องเท่านั้น) กัน `AIza...` และ `AQ....`; CI รัน gitleaks ทั้งประวัติด้วยกฎ default + `google-aq-api-key` (`AQ\.[0-9A-Za-z_-]{40,}`) จาก `.gitleaks.toml` ที่ root เพราะกฎ default จับ key แบบ `AQ.` ได้เฉพาะเมื่ออยู่ติดคำว่า api_key | `.github/workflows/backend.yml`, `.gitleaks.toml` |

CORS ของ `api/*` เป็นค่าเริ่มต้นของ Laravel (`allowed_origins: *`) จงใจคงไว้เพื่อ `flutter run -d chrome` ตอนพรีวิว UI; API ใช้ bearer token ไม่ใช่ cookie จึงไม่มี CSRF

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
app/Models/{School,User}.php และ GoogleAccount, ClassroomGoogleLink, AssignmentGoogleLink, ClassroomSubmissionImport (§18.4)
app/Domain/Worksheets/                       LayoutBuilder, WorksheetPdfRenderer, QrSigner, PdfMerger, LayoutService, WorksheetPrintService
app/Domain/Assignments/                      QuestionData (ตรวจ answer_key), QuestionEditor, QuestionPositions, RubricService
app/Domain/Gemini/                           GeminiClient (interface), FakeGeminiClient, RubricDraft
app/Domain/Scans/                            ScanIngestor (POST /scans, confirm-replace), ScanMeta, LayoutPageMatcher, ResponseWriter, SubmissionStatus, ScanFiles, ScanRetention
app/Domain/Grading/                          McqGrader (§11.6), ReviewPriority (§11.8), Understanding, ScoreRounding (§11.7)
app/Domain/Review/                           ReviewQueue, ResponseReviewer, Publisher, Appeals, ScoreExplainer (§9.5, §13)
app/Domain/Notifications/                    Notifier, PushNotifier, FcmNotifier + Fcm/ (HTTP v1, service-account JWT), LogNotifier, NoticeTexts (§9.9)
app/Domain/Google/                           GoogleOAuth, GoogleAccessTokens, GoogleApi (Classroom v1 + Drive v3 ผ่าน HTTP client), GoogleAccounts, GoogleRoster + RosterMatcher + NameNormalizer, CourseWorkPoster, GoogleSubmissionSync, ClassroomGradePusher, GoogleApiException + GoogleErrors (§18)
app/Events/, app/Listeners/                  SubmissionPublished, AppealOpened, AppealResolved, RetakeRequested, listener ที่ส่ง push (queue default) และ QueueClassroomGradePush (§18)
app/Console/Commands/FcmCheckCommand.php     eduvision:fcm-check
app/Console/Commands/RegisterModelCommand.php eduvision:register-model (model_versions จาก ml/models/<name>/<version>)
app/Console/Commands/ExportObservationsCommand.php eduvision:export-observations (CSV สำหรับ notebook BKT)
app/Domain/Mastery/                          MasteryCalculator (EWMA §14.2), Recommender (§14.1), ItemAnalysis (§14.3)
app/Domain/Practice/                         PracticeBank, PracticeItemData (ตรวจ item), PracticeGrader (AnswerMatcher), PracticeAttempts
app/Domain/Gemini/Practice*                  PracticeGenRequest, PracticeGenerator, PracticeDraft (practice_gen §10.6)
app/Domain/Training/TrainingSamples.php      ตัวอย่างเทรนจากการแก้คะแนนข้อตัวเลข (§8.6)
app/Listeners/RecordMasteryObservations.php  SubmissionPublished / AppealResolved → skill_observations + mastery
app/Filament/Resources/{ModelVersions,AiCalls}/ web admin ของ model_versions และ ai_calls (§7.5)
app/Jobs/{DraftRubricJob,RenderWorksheetsJob,MergeWorksheetsJob,GradeScanJob,PushClassroomGradeJob,GeneratePracticeItemsJob}.php
resources/fonts/                             Sarabun (OFL) ที่เพิ่ม glyph U+200B ดู README ในโฟลเดอร์
app/Providers/Filament/AdminPanelProvider.php
config/eduvision.php                         ค่าที่อ่านจาก .env (ห้ามใช้ env() นอก config เพราะ production ใช้ config:cache)
database/migrations/                         0001_..._schools → users → cache → jobs → personal_access_tokens
database/seeders/SchoolSeeder.php
resources/worksheet/aruco/                   ArUco marker PNG + manifest สำหรับใบงาน (สร้างจาก ml/tools/gen_aruco.py)
app/Http/Middleware/                         EnsureUserIsActive (`active`), EnsureRole (`role:`), SecurityHeaders
app/Http/Requests/Concerns/                  ValidatesAfterAuthorization (ตรวจ body หลัง policy)
tests/Feature/                               Api/, Scans/, Grading/, Review/, Google/, Practice/, Mastery/, Analytics/, Ml/, Notifications/, Console/, Filament/, AdminPanelTest
tests/Feature/Security/                      AuthorizationMatrixTest (+ SecurityWorld), AuthHardeningTest, InputHardeningTest, SecurityHeadersTest, GeminiPayloadPrivacyTest, PromptInjectionTest, SecretsHygieneTest, ConfigCacheSafetyTest
tests/fixtures/injection/                    ภาพ crop ที่มีคำสั่งแทรก + manifest.json (DESIGN §10.7 ข้อ 5)
```

## Deploy บน Plesk

คู่มือเต็มทีละขั้นอยู่ที่ **`docs/HOSTING.md`** (ภาษาไทย: PHP settings, Git, Composer แบบสลับ docroot, `.env` ทุกตัวแปร, Scheduled Task 3 ตัว, Gemini/FCM/Google Classroom, ลงทะเบียนโมเดล, smoke check, troubleshooting) สรุปสั้น: Plesk Git pull ทั้ง monorepo, document root = `<deployment path>/backend/public`, `.env` อยู่ที่ `backend/.env` (นอก document root), Composer extension รัน `composer install`, Scheduled Task ทุกนาที `artisan eduvision:queue-work`, Scheduled Task ทุกวัน 02:00 `artisan eduvision:purge-images` และ migration/`optimize` ผ่าน Scheduled Task "Run now"

โฟลเดอร์ `public/css/filament`, `public/js/filament` และ `public/fonts/filament` ถูก commit ไว้จงใจ (ลบบรรทัด ignore ของ skeleton ออกจาก `.gitignore` แล้ว) เพื่อให้ไปถึง Plesk ผ่าน Git โดยไม่ต้องพึ่ง script `filament:upgrade` หลัง `composer install` หรือรัน `filament:assets` บน server เมื่ออัปเกรด Filament ให้รัน `php artisan filament:assets` แล้ว commit ไฟล์ที่เปลี่ยนด้วย

## ก่อน commit

```bash
vendor/bin/pint --test && php artisan test
```

CI (`.github/workflows/backend.yml`) รันสี่งานทุก push/PR ที่แตะ `backend/`: suite บน PHP 8.3 + SQLite พร้อม coverage (pcov, ขั้นต่ำ 85% ของบรรทัด), suite เดิมบน MariaDB 11 (engine ของ production: ตอนนี้ต่างจาก SQLite แค่ enum เรียงตามลำดับประกาศ), pint และ gitleaks ทั้งประวัติ git ไม่มีขั้น deploy (ทำเองใน Plesk ตาม `docs/HOSTING.md`)

coverage ในเครื่อง (PHP ของ Homebrew ไม่มี pcov/xdebug: build pcov จาก pecl แล้วโหลดเฉพาะคำสั่งนี้ด้วย `-d extension=...`):

```bash
php -d extension=/path/to/pcov.so -d pcov.enabled=1 -d pcov.directory=app vendor/bin/phpunit --coverage-text
# 27 ก.ย. 2569: lines 94.96% (8387/8832), methods 81.21%, classes 57.88%
```

รัน suite กับ MariaDB จริงเหมือน CI (ต้องมี database ว่างชื่อ `eduvision_test` ใน container): `DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=eduvision_test DB_USERNAME=eduvision DB_PASSWORD=eduvision php artisan test` (`phpunit.xml` ตั้ง `DB_*` เฉพาะเมื่อ environment ไม่ได้ตั้ง)

ห้าม commit `.env`, key ใดๆ หรือข้อมูลนักเรียนจริง (repo เป็น public; pre-commit hook กัน Google API key ไว้ชั้นหนึ่ง และ gitleaks ใน CI อีกชั้น) clone ใหม่หรือแก้ผ่านเว็บ GitHub ไม่มี hook จึงเหลือแค่ gitleaks ใน CI

`php artisan test` ไม่เขียน `storage/logs/laravel.log` (`phpunit.xml` ตั้ง `LOG_CHANNEL=discard` ซึ่งเป็น `NullHandler` ใน `config/logging.php` ข้อความ error ที่ test ตั้งใจสร้าง เช่น "FCM down" จึงไม่ปนกับ log จริง) อยากเห็น log ของ test ให้รัน `LOG_CHANNEL=single php artisan test`
