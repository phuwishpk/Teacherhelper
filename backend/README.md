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

## Queue และ heartbeat

Plesk ไม่มี process ค้าง จึงใช้ Scheduled Task ทุก 1 นาทีเรียก

```
php artisan eduvision:queue-work
```

command นี้ (1) dispatch `QueueHeartbeatJob` ลง table `jobs` แล้ว (2) รัน `queue:work --queue=grading,default,pdf --stop-when-empty --max-time=50` (option ตาม DESIGN §7.2) job เขียนเวลาไว้ใน cache key `queue.last_run_at` ซึ่ง `GET /health` อ่านออกมา ถ้าค่าเก่ากว่า `HEARTBEAT_MAX_AGE_MINUTES` (ค่าเริ่มต้น 3 นาที) `status` จะเป็น `degraded` แปลว่า cron + artisan + database queue ไม่ครบวงจร

## โครงสร้างโค้ด (ตาม DESIGN §7.1)

```
app/Console/Commands/QueueWorkCommand.php    eduvision:queue-work
app/Exceptions/ApiException.php              error ที่มี code ให้แอปจัดการ (ไม่ถูกเขียนลง log)
app/Exceptions/ApiErrorResponse.php          รูปแบบ {message, errors, code} + code กลางของ error จาก framework
app/Http/Controllers/Api/V1/                 HealthController, TeacherAuthController, MeController
app/Http/Requests/Api/V1/                    validation ของแต่ละ endpoint
app/Http/Resources/UserResource.php
app/Jobs/QueueHeartbeatJob.php
app/Models/{School,User}.php
app/Providers/Filament/AdminPanelProvider.php
config/eduvision.php                         ค่าที่อ่านจาก .env (ห้ามใช้ env() นอก config เพราะ production ใช้ config:cache)
database/migrations/                         0001_..._schools → users → cache → jobs → personal_access_tokens
database/seeders/SchoolSeeder.php
resources/worksheet/aruco/                   ArUco marker PNG + manifest สำหรับใบงาน (สร้างจาก ml/tools/gen_aruco.py)
tests/Feature/                               Api/HealthTest, Api/TeacherAuthTest, Api/ErrorFormatTest, Console/QueueWorkCommandTest, AdminPanelTest
```

## Deploy บน Plesk

ทำตาม `docs/KICKOFF.md` B7 หลัง `tools/hosting-probe.php` ผ่าน สรุปสั้น: Plesk Git pull ทั้ง monorepo, document root = `<deployment path>/backend/public`, `.env` อยู่ที่ `backend/.env` (นอก document root), Composer extension รัน `composer install`, Scheduled Task ทุกนาที `artisan eduvision:queue-work` และ migration ผ่าน Scheduled Task "Run now" `artisan migrate --force`

โฟลเดอร์ `public/css/filament`, `public/js/filament` และ `public/fonts/filament` ถูก commit ไว้จงใจ (ลบบรรทัด ignore ของ skeleton ออกจาก `.gitignore` แล้ว) เพื่อให้ไปถึง Plesk ผ่าน Git โดยไม่ต้องพึ่ง script `filament:upgrade` หลัง `composer install` หรือรัน `filament:assets` บน server เมื่ออัปเกรด Filament ให้รัน `php artisan filament:assets` แล้ว commit ไฟล์ที่เปลี่ยนด้วย

## ก่อน commit

```bash
vendor/bin/pint --test && php artisan test
```

ห้าม commit `.env`, key ใดๆ หรือข้อมูลนักเรียนจริง (repo เป็น public; pre-commit hook กัน Google API key ไว้ชั้นหนึ่ง)
