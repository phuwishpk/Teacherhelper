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
| POST | `/scans` | ครู | multipart: `meta` (JSON ตาม §9.4), `page` (WebP) และ crop WebP หนึ่งไฟล์ต่อชื่อที่อ้างใน `meta.regions[].file` / `final_file` ตอบ `201 {scan_id, submission_id, state: "active"}`, `202 {…, state: "pending_confirm"}` (submission เผยแพร่แล้ว) หรือ `200` body เดิมเมื่อ `client_scan_id` ซ้ำ (state ปัจจุบันของ scan) ถูกปฏิเสธ: `422 qr_invalid` (ลายเซ็นผิด/ไม่พบการบ้าน), `422 layout_unknown`, `422 page_mismatch` (หน้าไม่อยู่ใน layout หรือชุดช่องคำตอบไม่ตรงกับหน้านั้น), `422 student_unknown` (ไม่อยู่ในห้อง หรือใบงานสำรอง `student_id = 0` จากกล้อง §18.3), `422 google_submission_unknown` (`meta.source = classroom` แต่ `meta.google_submission_id` ไม่ใช่งานที่ sync แล้วของการบ้านนี้ ดูส่วน Google Classroom), `422 validation_failed` (meta/ไฟล์), `403` (ไม่ใช่ครูของห้อง), `503 too_many_files` (PHP `max_file_uploads` ต่ำไป แอปส่งใหม่เองหลังผู้ดูแลแก้ค่า), `503 qr_key_missing` |
| POST | `/scans/{id}/confirm-replace` | ครู | ยืนยันใช้สแกนใหม่แทนหน้าที่เผยแพร่แล้ว → `200 {…, state: "active"}` เรียกซ้ำได้ `409 scan_superseded` (มีสแกนใหม่กว่า) / `scan_files_missing` (ไฟล์ที่พักไว้หายหรือหมดอายุ) |
| GET | `/scans/{id}/page` | ครู | ภาพหน้าเต็ม `image/webp` (`410 image_purged` หลังเผยแพร่และ purge แล้ว) |
| GET | `/responses/{id}/crop?part=main\|final` | ครู / นักเรียนเจ้าของ (หลังเผยแพร่) | ภาพ crop ของข้อ `final` = กรอบคำตอบสุดท้ายของ show_work (`410 image_purged` หลัง `crop_retention_until`) |

- **ขั้นตอน** (`App\Domain\Scans\ScanIngestor`): ตรวจ QR ด้วย `QrSigner` → สิทธิ์ครู → layout เวอร์ชันตาม QR → หน้าและชุดช่องคำตอบต้องตรงกับ layout → นักเรียนอยู่ในห้อง → ไฟล์ WebP ไม่เกิน `SCAN_MAX_PAGE_KB` / `SCAN_MAX_CROP_KB` จากนั้นทำใน transaction ที่ล็อกแถว submission
- **กติกาสแกนซ้ำ** (key = การบ้าน, นักเรียน, หน้า): ยังไม่เผยแพร่ → สแกนใหม่ `active` ของเก่า `superseded` และ response ของหน้านั้น (แถวเดิม, id เดิม) ถูกล้างผลตรวจแล้วตรวจใหม่ เผยแพร่แล้ว → `pending_confirm` เก็บ crop + `regions.json` ไว้ที่ `scans/{school}/{assignment}/pending/{scan}/` จนครูยืนยัน แล้ว submission กลับไป `grading` (ล้าง `published_at`) ทุกคะแนนที่ถูกแทนบันทึก `score_events.action = rescan` สแกนใหม่เขียน crop ทับ path เดิมของ response โดยย้ายไฟล์เดิมไปพักที่ `crops/{school}/{assignment}/replaced/{scan}/` ก่อน (`CropSwap`) ถ้า transaction ล้มจะคืนไฟล์เดิม ถ้าสำเร็จจะลบไฟล์ที่พักไว้
- **ตรวจตอนรับ**: ปรนัยให้คะแนนทันทีจากค่าการฝน (§11.6, `App\Domain\Grading\McqGrader`) พร้อม `review_priority` ตาม §11.8 (`ReviewPriority`) และ `score_events` `ai_scored` (actor `system`) ข้ออื่นเป็น `queued` แล้ว dispatch `GradeScanJob` ลง queue `grading` (**ตอนนี้เป็น stub** ขั้น B4 เติม Gemini + fuzzy) ข้อที่ชนิดคำถามถูกแก้หลังพิมพ์จนไม่ตรงกับช่องบนกระดาษเป็น `manual` (`fuzzy_trace.manual_reason`)
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
| GET | `/student/mastery` | นักเรียน | ยังเป็น placeholder `{data: []}` (Phase 6) |

โค้ดอยู่ใน `app/Domain/Review/` (`ReviewQueue`, `ResponseReviewer`, `Publisher`, `Appeals`, `ScoreExplainer`, `ReviewFlags`, `ScoreRules`) ป้าย `suspicious` / `identity_mismatch` ไม่ใช่คอลัมน์ อ่านจาก `fuzzy_trace` / `extraction`

## Push notification (FCM, DESIGN §9.9)

- `Notifier` ผูกเป็น `FcmNotifier` เมื่อ `FIREBASE_CREDENTIALS` ชี้ไปที่ไฟล์ service account ที่ใช้ได้ (อยู่นอก document root) ไม่งั้นเป็น `LogNotifier` ที่เขียน `notify.*` ลง log แทน
- FCM HTTP v1 เรียกด้วย Laravel HTTP client: เซ็น JWT ของ service account ด้วย `firebase/php-jwt` (RS256) แลก access token ที่ `oauth2.googleapis.com/token` แล้ว cache แบบเข้ารหัสจนเกือบหมดอายุ ส่งทีละเครื่อง token ที่ FCM บอกว่าใช้ไม่ได้ (`UNREGISTERED`, `INVALID_ARGUMENT`, `SENDER_ID_MISMATCH`) ถูกลบจาก `device_tokens` ได้ `401` จะขอ token ใหม่แล้วลองอีกครั้งเดียว
- ข้อความ 4 แบบของ §9.9 (ไม่มีคะแนน) และ `data.type` ที่แอปใช้เปิดหน้า: `grading_done` {assignment_id}, `results_published` {submission_id, assignment_id}, `appeal_opened`, `appeal_resolved` {submission_id, appeal_id} และของ §18.2: `retake_requested` {assignment_id} (ครูตีกลับงานใน Google Classroom ให้ถ่ายใหม่ body มีเหตุผลของครู)
- ส่งจาก queued listener บน queue `default` (worker ของ cron): `SubmissionPublished` → `NotifyStudentOfPublishedResult`, `AppealOpened` → `NotifyTeacherOfAppeals` (หน่วง 60 วินาทีแล้วส่งครั้งเดียวต่อชุดพร้อมจำนวนรวม), `AppealResolved` → `NotifyStudentOfAppealResolution`, `RetakeRequested` → `NotifyStudentOfRetakeRequest`
- ตรวจบน server: Scheduled Task "Run now" `artisan eduvision:fcm-check` (ขอ access token) หรือ `artisan eduvision:fcm-check --user=<id>` (ส่งข้อความทดสอบไปทุกเครื่องของผู้ใช้นั้น)

## Google Classroom (DESIGN §18)

ครูเชื่อมบัญชี Google ของตัวเอง (นักเรียนใช้ Classroom ตามปกติ) server เก็บเฉพาะ refresh token แบบเข้ารหัส (`google_accounts.encrypted_refresh_token`, cast `encrypted`) แล้วเรียก Classroom v1 และ Drive v3 ตรงด้วย Laravel HTTP client (`app/Domain/Google/GoogleApi`) **ไม่ใช้ `google/apiclient`** รูปของนักเรียนไม่ผ่าน server: แอปดาวน์โหลดไฟล์แนบจาก Drive ด้วยสิทธิ์ของครูบนเครื่อง รัน pipeline สแกนเดิม แล้วส่ง `POST /scans` ตามปกติ

ตั้งใน `.env`: `GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET` (OAuth client ชนิด Web application ตัวเดียวกับ `GOOGLE_SERVER_CLIENT_ID` ของแอป ตาม KICKOFF ส่วนที่ 6), `GOOGLE_OAUTH_REDIRECT_URI` (เว้นว่างสำหรับ code จาก Android), `GOOGLE_TIMEOUT`, `GOOGLE_CLASSROOM_APP_LINK` (ลิงก์แอปที่แนบท้ายคำสั่งของงาน) ถ้าไม่ตั้ง client ทุก endpoint ในตารางนี้ตอบ `503 google_not_configured` และ `GET /google/status` มี `server_configured: false` ส่วนอื่นของระบบทำงานปกติ

| Method | Path | ใคร | หมายเหตุ |
|---|---|---|---|
| POST | `/google/connect` | ครู | `{server_auth_code}` แลกที่ `oauth2.googleapis.com/token` ต้องได้ครบทุก scope ใน `GoogleScopes::REQUIRED` (ขาด → `422 google_scope_missing` พร้อม `errors.scopes` และ revoke grant ที่ไม่ครบทิ้ง) อ่านบัญชีจาก `userProfiles/me` ตอบ `{data: status}` `422 google_code_invalid` (code หมดอายุ/ใช้แล้ว), `422 google_refresh_token_missing`, `409 google_account_in_use` (บัญชี Google เดียวกันเชื่อมกับครูอีกคนอยู่) |
| GET | `/google/status` | ครู | `{connected, email, scopes[], needs_reconnect, last_error, connected_at, server_configured}` |
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
- **สแกนจาก Classroom** (§18.3): `POST /scans` รับ `meta.source` (`camera` ค่าเริ่มต้น หรือ `classroom`) และ `meta.google_submission_id` ซึ่งต้องเป็นแถวที่ sync แล้วของการบ้านนั้น (`422 google_submission_unknown`) นักเรียน = คนใน QR ใบงานสำรอง (`student_id = 0`) ใช้นักเรียนที่จับคู่กับบัญชีผู้ส่ง (`422 student_unknown` ถ้ายังไม่จับคู่ และเสมอเมื่อมาจากกล้อง) QR เป็นคนอื่นกว่าผู้ส่ง → รับตาม QR และติดป้าย `identity_mismatch` ไว้ใน `responses.fuzzy_trace` (คิวตรวจทานยกขึ้นก่อน ป้ายตามไปถึงสแกนซ้ำที่รอยืนยันด้วย) `scans.source/google_submission_id` บันทึกที่มา แถว import เป็น `imported` และ `student_id` เป็นคนที่สแกนถูกเก็บไว้
- **ส่งคะแนนกลับ**: `SubmissionPublished` (และ `AppealResolved` ที่คะแนนเปลี่ยน) → listener `QueueClassroomGradePush` → `PushClassroomGradeJob` บน queue `default` (unique ต่อ submission) ใช้บัญชี Google ของครูที่โพสต์งาน เรียก `studentSubmissions.patch?updateMask=assignedGrade` = `total_score` แล้ว `return` งานที่นักเรียนส่งเป็นกระดาษหา submission ด้วย `userId` ของบัญชีที่จับคู่ (ถ้านักเรียนไม่ได้กดส่งใน Classroom ใส่คะแนนได้แต่ return ไม่ได้ บันทึกไว้ใน `last_error`) Google ล่ม/429/5xx ลองใหม่หลัง 1 และ 5 นาที ล้มครบ → `grade_failed`
- **ข้อผิดพลาดจาก Google** (`GoogleApiException` → `GoogleErrors`): `invalid_grant` ตอนขอ access token → `google_accounts.last_error = invalid_grant`, status `needs_reconnect: true` และทุก endpoint ตอบ `409 google_reconnect_required` โดยไม่เรียก Google อีกจนครูเชื่อมใหม่ (OAuth app โหมด Testing: refresh token หมดอายุใน 7 วัน) scope ถูกถอนภายหลัง → `422 google_scope_missing` + `needs_reconnect`; `@ProjectPermissionDenied` (courseWork ที่ไม่ได้สร้างผ่านแอป) → `409 project_permission_denied`; อื่นๆ `409 google_permission_denied` / `google_not_found` / `google_failed_precondition`, `503 google_api_disabled` / `google_unavailable`, `502 google_error` ยังไม่เชื่อมบัญชี → `409 google_not_connected`
- access token แลกจาก refresh token แล้ว cache แบบเข้ารหัสจน 5 นาทีก่อนหมดอายุ (`GoogleAccessTokens`) ได้ `401` ทิ้ง cache แล้วลองใหม่ครั้งเดียว ไม่มี token ใน response, log หรือ payload ของ job (มี test ตรวจทุกทาง) endpoint ที่เรียก Google จำกัด 30 ครั้ง/นาทีต่อครู (`throttle:google`)
- test ทั้งหมดใช้ `Http::fake` (`tests/Feature/Google/GoogleFixtures`: token, revoke, courses, students, courseWork, studentSubmissions, patch, return, Drive upload) ทดสอบกับ Google จริงต้องมี Cloud project และคอร์สทดลองตาม KICKOFF ส่วนที่ 6

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
- ภาพหน้าเต็ม `scans/{school}/{assignment}/{scan}.webp` ของ submission ที่เผยแพร่แล้ว (scan `active`/`superseded`) → `scans.page_image_path = NULL`
- สแกนซ้ำที่รอครูยืนยัน (`pending_confirm`) เก็บภาพหน้าเต็มและที่พักไฟล์ไว้ไม่เกิน 30 วัน (`ScanRetention::PENDING_RESCAN_DAYS`) และไม่เกิน `crop_retention_until` จากนั้นหมดอายุ: ลบภาพหน้าเต็มและที่พักไฟล์ `page_image_path = NULL` และ scan เป็น `superseded` (ยืนยันไม่ได้แล้ว ตอบ `409 scan_files_missing`)
- ภาพ crop `crops/{school}/{assignment}/{response}[_final].webp` ของสแกนที่ทำจนถึง `schools.crop_retention_until` เมื่อวันนั้นผ่านไปแล้ว → `crop_path`/`final_crop_path = NULL` คะแนนและค่าที่อ่านได้ยังอยู่
- ไฟล์ค้างจาก request ที่ถูกตัดกลางทาง (เก่ากว่า 24 ชั่วโมง): โฟลเดอร์ `pending/{scan}` ของสแกนที่ไม่ได้รอยืนยันแล้ว และโฟลเดอร์ `crops/{school}/{assignment}/replaced/{scan}`

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
app/Jobs/{DraftRubricJob,RenderWorksheetsJob,MergeWorksheetsJob,GradeScanJob,PushClassroomGradeJob}.php
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
