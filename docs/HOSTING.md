# EduVision: คู่มือ deploy backend บน Plesk (Hostatom shared hosting)

- **ฉบับ:** 27 ก.ย. 2569 (B7) เขียนจาก DESIGN §3.3, §7.2–§7.6 และ KICKOFF ส่วนที่ 3 (B7–B9) กับส่วนที่ 6 (Google Classroom) **ปรับ 30 ก.ย. 2569** สำหรับ Phase 8–9 (DESIGN §19–§21): งานรอบของ cron (§4.7), ค่า PHP (§4.1), ตัวแปร `.env` ใหม่ (§4.6) และ scope `classroom.announcements` (§6.3)
- **สถานะ:** ยัง**ไม่เคย deploy จริง** ทุกข้อที่มี `⚠️ ต้องตรวจสอบ` คือสิ่งที่ยังไม่ได้ยืนยันกับหน้าจอ Plesk ของ Hostatom เมื่อทำจริงแล้วให้แก้เอกสารนี้ (และ DESIGN §7.2/§7.6 ถ้าค่าต่างจากที่เขียนไว้) ใน commit เดียวกัน ตาม KICKOFF Day 3 ข้อ 9
- **กติกา:** ไฟล์นี้อยู่ใน repo สาธารณะ **ห้ามใส่รหัสผ่าน, key, token, ชื่อ database จริง หรือ path ที่บอกโครงสร้าง home ของ subscription เกินจำเป็น** ค่าจริงเก็บใน password manager ของทีม
- **ฉบับย่อ:** ลำดับขั้นตอนขึ้นเว็บครั้งแรกแบบทำตามทีละข้ออยู่ใน [DEPLOY-CHECKLIST.md](DEPLOY-CHECKLIST.md) เอกสารนี้คือรายละเอียดและวิธีแก้ปัญหาของแต่ละข้อ
- **เครื่อง self-hosted:** ชุดทดสอบบนเครื่องของผู้พัฒนาเอง (Docker) มีคู่มือแยกที่ [SELFHOST.md](SELFHOST.md) เอกสารนี้ไม่ครอบคลุม และคำสั่งของสองที่ใช้แทนกันไม่ได้
- **ผู้อ่าน:** คนที่มีสิทธิ์เข้า Plesk ของ `teacherhelper.phuwish.com` (ไม่ต้องเขียนโค้ดเป็น แต่ต้องใช้ terminal บน Mac ได้เพื่อ `curl` ตรวจผล)

สารบัญ

1. [ภาพรวม](#1-ภาพรวม)
2. [ก่อนเริ่ม](#2-ก่อนเริ่ม)
3. [โครงบน server](#3-โครงบน-server)
4. [Deploy ครั้งแรก](#4-deploy-ครั้งแรก)
5. [Deploy ครั้งถัดไป](#5-deploy-ครั้งถัดไป-อัปเดตโค้ด)
6. [บริการภายนอก (ไม่บังคับ): Gemini, FCM, Google Classroom](#6-บริการภายนอก-ไม่บังคับ)
7. [ลงทะเบียนโมเดลอ่านตัวเลข](#7-ลงทะเบียนโมเดลอ่านตัวเลข-model_versions)
8. [ตัวชี้วัดและข้อมูลตั้งต้น](#8-ตัวชี้วัดและข้อมูลตั้งต้น)
9. [ความปลอดภัย: สิ่งที่โค้ดทำให้แล้ว และสิ่งที่ต้องทำเอง](#9-ความปลอดภัย)
10. [Cloudflare (ทำภายหลัง)](#10-cloudflare-ไม่บังคับ-ทำภายหลัง)
11. [Troubleshooting](#11-troubleshooting)
12. [บันทึกสิ่งที่ยืนยันกับของจริงแล้ว](#12-บันทึกสิ่งที่ยืนยันกับของจริงแล้ว)

---

## 1. ภาพรวม

| เรื่อง | ค่า |
|---|---|
| Hosting | Hostatom **Web Hosting Boost PL** (shared Plesk): ไม่มี SSH, ไม่มี Docker, ห้ามมี process ค้าง (DESIGN §3.3) |
| โดเมน | `https://teacherhelper.phuwish.com` (subdomain ของ `phuwish.com`, DNS A → IP ของ Hostatom ที่มีอยู่แล้ว) |
| สิ่งที่รัน | Laravel 13 API ที่ `/api/v1/*` และ Filament admin ที่ `/admin` จาก `backend/` ของ monorepo |
| วิธี deploy | **Plesk Git** ดึง `main` ของ `github.com/phuwishpk/Teacherhelper` แบบ manual (Pull Updates → Deploy) แล้ว **PHP Composer extension** ติดตั้ง dependency ไม่มี CI/CD ที่ deploy ให้ (`.github/workflows/backend.yml` แค่รัน test) |
| งานเบื้องหลัง | Plesk **Scheduled Task** 3 ตัว: worker ทุก 1 นาที (`eduvision:queue-work` ซึ่งทำงานรอบด้วย: ซิงก์ Google Classroom ทุก 5 นาที, วิเคราะห์รายคนรอบกลางคืนหลัง 01:00 และถามผล batch ทุกนาที ดู §4.7), ลบไฟล์ตามนโยบายทุกวัน 02:00 (`eduvision:purge-images`) และ task "maintenance" ที่ใช้กด Run Now (migrate, optimize, ตรวจ key ฯลฯ) ไม่ใช้ `schedule:run` เพราะต้องใช้ `proc_open` |
| Database | MariaDB ของ Plesk (สร้างจากหน้า Databases) queue, cache และ session ใช้ database ทั้งหมด ไม่มี Redis |
| ไฟล์ | `storage/app/private/` ใต้ `backend/` (ภาพสแกน, crop, PDF, โมเดล) ไม่มี URL สาธารณะ โหลดผ่าน controller ที่ตรวจ policy เท่านั้น (DESIGN §7.3) |
| PHP | 8.3 หรือ 8.4 ตามที่ probe รายงาน `composer.json` ล็อก `config.platform.php = 8.3.0` ห้ามใช้ syntax ใหม่กว่า 8.3 |

ลำดับที่ต้องทำครั้งแรก: §2 เตรียมค่า → §4.1 PHP → §4.2 Git → §4.3 Composer → §4.4 docroot + SSL → §4.5 database → §4.6 `.env` → §4.7 Scheduled Tasks + migrate → §4.8 ตรวจด้วย curl → (ถ้าต้องการ) §6, §7, §8

---

## 2. ก่อนเริ่ม

1. **hosting probe ผ่านแล้ว** (KICKOFF Day 0, `tools/hosting-probe.php`): PHP ≥ 8.3 พร้อม extension ครบ, เรียก HTTPS ออกไป `generativelanguage.googleapis.com`, `fcm.googleapis.com`, `oauth2.googleapis.com` ได้, Scheduled Task รันทุก 1 นาทีและค้างได้ ≥ 55 วินาที จดผลลง §12 ถ้าข้อใดไม่ผ่านดู KICKOFF B9 (ย้ายไป Private Hosting)
2. **ลบ probe ออกจาก server แล้ว**: `hosting-probe.php`, `probe-cron.log`, Scheduled Task ของ probe และ database ทดสอบ ตรวจ: `curl -s -o /dev/null -w '%{http_code}\n' 'https://teacherhelper.phuwish.com/hosting-probe.php'` ต้องได้ 404
3. **เตรียมค่าใน password manager** (สร้างในเครื่อง ไม่ต้องพึ่ง server) หนึ่งบรรทัดต่อค่า

   ```bash
   cd backend
   php artisan key:generate --show                              # APP_KEY ของ production (คนละค่ากับเครื่องตัวเอง)
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"            # QR_SIGNING_KEY (64 ตัวอักษร) ตั้งครั้งเดียว ห้ามเปลี่ยนภายหลัง
   php -r "echo strtoupper(bin2hex(random_bytes(4))), PHP_EOL;" # SEED_TEACHER_JOIN_CODE 8 ตัว (รหัสสมัครครูของโรงเรียนแรก)
   php -r "echo bin2hex(random_bytes(12)), PHP_EOL;"            # ADMIN_PASSWORD ชั่วคราวของ admin คนแรก (เปลี่ยนหลัง login ครั้งแรก)
   ```

   และค่าที่ได้จาก Plesk/Google ระหว่างทาง: ชื่อ database + user + รหัสผ่าน (§4.5), Gemini key กลาง (ไม่บังคับ, §6.1), ไฟล์ service account ของ Firebase (ไม่บังคับ, §6.2), OAuth client ID/secret ของ Google Classroom (ไม่บังคับ, §6.3)
4. **เครื่อง Mac** มี `curl` และ `jq` (`brew install jq`) สำหรับ §4.8

---

## 3. โครงบน server

path สัมพัทธ์กับ **home ของ subscription** (ดูได้จาก Plesk > Files; path เต็มมักเป็น `/var/www/vhosts/<โดเมนหลัก>/` ⚠️ ต้องตรวจสอบ)

```
<home>/eduvision/                         ← deployment path ของ Plesk Git (clone ทั้ง monorepo)
<home>/eduvision/backend/                 ← Laravel; artisan อ่าน .env จากโฟลเดอร์นี้เสมอ
<home>/eduvision/backend/.env             ← นอก document root (สร้างเองผ่าน Files, ไม่ได้มากับ git)
<home>/eduvision/backend/vendor/          ← จาก Composer extension (ไม่ได้มากับ git)
<home>/eduvision/backend/public/          ← document root ของ teacherhelper.phuwish.com
<home>/eduvision/backend/storage/         ← log, cache, ไฟล์ส่วนตัว (เขียนได้โดย PHP-FPM และ Scheduled Task)
<home>/eduvision/ml/models/digit_crnn/0.1.0/  ← metrics.json มากับ git; model.tflite ต้องอัปโหลดเอง (§7)
<home>/eduvision-private/                 ← สร้างเอง: ไฟล์ service account ของ Firebase และของลับอื่นที่ไม่ใช่ .env (§6.2)
```

- `artisan` bootstrap แอปจาก `backend/` และอ่าน `backend/.env` เสมอ การที่ document root คือ `backend/public` จึงพอแล้วให้ `.env`, `composer.json`, `storage/` อยู่นอกที่เว็บเข้าถึงได้ ไม่ต้องแก้ `bootstrap/app.php`
- Git deploy, PHP-FPM และ Scheduled Task รันเป็น system user เดียวกันของ subscription จึงเขียน `storage/` และ `bootstrap/cache/` ได้โดยไม่ต้อง chmod (โฟลเดอร์ย่อยของ `storage/framework` มากับ repo ผ่านไฟล์ `.gitignore` placeholder) ถ้าเจอ `Permission denied` ตั้ง 755 ให้สองโฟลเดอร์นี้ผ่าน Files > Change Permissions
- ⚠️ ต้องตรวจสอบ (KICKOFF R8): หลัง Deploy ครั้งที่ 2 ไฟล์ที่ไม่อยู่ใน git (`backend/.env`, `backend/vendor/`, `storage/app/private/*`) ต้องยังอยู่ ถ้า Plesk ล้าง deployment path ให้ย้าย `.env` ไป `<home>/eduvision-private/.env` แล้วบันทึกวิธี (เช่น symlink ผ่าน Scheduled Task `ln -s`) ลง §12 ก่อนใช้งานจริง

---

## 4. Deploy ครั้งแรก

### 4.1 PHP ของ subdomain

Websites & Domains > `teacherhelper.phuwish.com` > **PHP Settings**

| ค่า | ตั้งเป็น | ทำไม |
|---|---|---|
| PHP version | เวอร์ชันที่ probe รายงาน (≥ 8.3) | ต้องเป็นเวอร์ชันเดียวกับ Scheduled Task ทุกตัว (§4.7) |
| Handler | แบบที่มี **Apache** อยู่ในสาย เช่น "FPM application served by Apache" | Laravel ใช้ `public/.htaccess` ส่งทุก request เข้า `index.php` ถ้าเป็น "served by nginx" ล้วน ทุก route จะ 404 ยกเว้น `/` (ดู §11) ⚠️ ต้องตรวจสอบ: ชื่อ handler ที่ Hostatom เปิดให้ |
| `memory_limit` | ≥ 256M | mPDF ตอนสร้างใบงาน/บัตร QR และ worker (`queue:work --memory=128` เป็นเกณฑ์หยุดเองที่ต่ำกว่านี้) |
| `max_execution_time` | ≥ 120 (อย่างน้อย 90) | POST /scans ที่มีไฟล์หลายสิบไฟล์, หน้า Filament import CSV และ **"วิเคราะห์ตอนนี้"** (`POST /students/{id}/analysis/run` เรียก Gemini ใน request เอง ถ้าผลแรกใช้ไม่ได้ถามซ้ำอีกครั้ง ต้องได้อย่างน้อย 90 วินาที DESIGN §20.5) |
| `upload_max_filesize` | ≥ 10M | ไฟล์งานที่ส่งแบบรูปทั้งหน้าและไฟล์เฉลย/เอกสารของครูไฟล์ละไม่เกิน 10 MB (`SUBMISSION_MAX_FILE_MB`, `DOCUMENT_MAX_FILE_MB`, DESIGN §19.4–§19.6), หน้าเต็มของสแกน (`SCAN_MAX_PAGE_KB`) และการอัปโหลดโมเดลผ่าน Filament (§7) ถ้าต่ำกว่านี้ไฟล์ใหญ่ถูก PHP ทิ้งก่อนถึงแอป |
| `post_max_size` | ≥ 55M | งานส่งหนึ่งครั้งมีได้ 5 ไฟล์ × 10 MB (`SUBMISSION_MAX_PAGES`) บวกส่วนหัวของ multipart และหน้า + crop ทุกข้อของ POST /scans ถ้า body ใหญ่เกิน API ตอบ `413 file_too_large` |
| `max_file_uploads` | ≥ 100 | 1 หน้า + 2 ไฟล์ต่อข้อ ถ้าต่ำกว่านี้ PHP ทิ้งไฟล์ที่เกินเงียบๆ และ API ตอบ `503 too_many_files` |
| `open_basedir` | `{WEBSPACEROOT}{/}{:}{TMP}{/}` | ต้องครอบ `<home>/eduvision/` ทั้งก้อน ไม่ใช่แค่ docroot |
| `display_errors` | off | production |
| `expose_php` | off (ช่อง Additional directives ถ้าไม่มีในรายการ) | ไม่บอกเวอร์ชัน PHP ใน header `X-Powered-By` (middleware `SecurityHeaders` ลบให้อีกชั้น) |

ตรวจสอบ: หน้า PHP Settings แสดงเวอร์ชันที่เลือกและค่าข้างบน จดเวอร์ชันและ handler ลง §12

### 4.2 Git

1. Files > สร้างโฟลเดอร์ `eduvision` ที่ระดับ home (deployment path ต้องมีอยู่ก่อน)
2. Websites & Domains > `teacherhelper.phuwish.com` > **Git** > Add Repository

| ช่อง | ค่า |
|---|---|
| Repository type | Remote Git repository |
| URL | `https://github.com/phuwishpk/Teacherhelper.git` (repo public ใช้ HTTPS ไม่ต้องใส่ deploy key) |
| Branch / Deployment path ("Change branch and path") | `main` / `/eduvision` |
| Deployment mode | **Manual** (กด "Pull Updates" แล้ว "Deploy" เอง) ค่อยเปิด Automatic + webhook เมื่อ CI นิ่งแล้ว |
| Enable additional deploy actions | **ปิด** (คำสั่งใน chroot ของ Git extension ไม่มี `php`; ใช้ Scheduled Task แทน) |

ตรวจสอบ: Files เห็น `eduvision/backend/artisan`, `eduvision/backend/public/index.php` และ `eduvision/docs/DESIGN.md`

### 4.3 Composer (ไม่มี SSH: ใช้ extension โดยสลับ document root ชั่วคราว)

PHP Composer extension ของ Plesk สแกนหา `composer.json` **เฉพาะใต้ document root** ของโดเมน จึงต้องชี้ docroot ไปที่ `backend/` ก่อนหนึ่งจังหวะ

1. Hosting Settings > **Document root** = `eduvision/backend` (ชั่วคราว) > OK
2. Websites & Domains > **PHP Composer** (หรือ Applications > Scan) > เห็นรายการที่ชี้ `eduvision/backend/composer.json` > **Install** (หรือ Install Dependencies)
   ⚠️ ต้องตรวจสอบ: ตัวติดตั้งใช้ `--no-dev` หรือไม่ (ถ้าไม่ ก็แค่มี phpunit/pint ติดมาด้วย ไม่เป็นไร) และรัน script `post-autoload-dump` (`package:discover`, `filament:upgrade`) หรือไม่ (ถ้าไม่รัน Laravel สร้าง `bootstrap/cache/packages.php` เองตอน boot และ asset ของ Filament commit มากับ repo แล้ว)
   ถ้าหน้า Composer มีช่อง environment variables ให้ใส่ `COMPOSER_MEMORY_LIMIT=-1`
3. Hosting Settings > Document root = `eduvision/backend/public` (ค่าจริง) > OK ⚠️ ต้องตรวจสอบ: ช่องนี้ยอมชี้ออกนอกโฟลเดอร์เดิมของ subdomain หรือไม่ ถ้าไม่ยอม ให้ตั้ง deployment path (§4.2) เป็นโฟลเดอร์ย่อยใต้ docroot เดิมของ subdomain แล้ว docroot = `<โฟลเดอร์ย่อย>/backend/public`

ตรวจสอบ: Files เห็น `eduvision/backend/vendor/autoload.php` และ Hosting Settings แสดง docroot ลงท้าย `backend/public`

**ทางสำรอง** ถ้า extension ล้ม (memory/timeout/สแกนไม่เจอ): build `vendor.zip` จาก**สำเนา**ของ `backend/` เพื่อไม่ให้ working tree ในเครื่องเสีย dev package

```bash
rm -rf /tmp/bk && cp -R /Users/phuwish/WebapplearningProj/backend /tmp/bk
(cd /tmp/bk && composer install --no-dev --optimize-autoloader && zip -qr ~/vendor.zip vendor)
```

Files > Upload `vendor.zip` ลง `eduvision/backend/` > Extract ทำซ้ำทุกครั้งที่ `composer.lock` เปลี่ยน (ดู §5)

### 4.4 Document root และ SSL

1. docroot = `eduvision/backend/public` (จาก §4.3 ข้อ 3)
2. SSL/TLS Certificates > Install (**Let's Encrypt**) > ติ๊กเฉพาะ "Secure the domain name" > Get it free
3. เปิดสวิตช์ **"Redirect from http to https"** (Hosting Settings หรือหน้า SSL/TLS) จำเป็น เพราะแอปส่ง `Strict-Transport-Security` เฉพาะเมื่อ request มาทาง HTTPS (`App\Http\Middleware\SecurityHeaders`)

ตรวจสอบ: `curl -sSI https://teacherhelper.phuwish.com/ | head -1` ไม่มี cert error (ได้ 500 จนกว่า §4.6–§4.7 เสร็จ ถือว่าปกติ) และ `curl -sI http://teacherhelper.phuwish.com/ | head -3` เป็น 301 ไป https

### 4.5 Database

1. Databases > **Add Database**: ชื่อและ user ตามที่ Plesk อนุญาต (จดใน password manager) สิทธิ์ทั้งหมดบน database นั้น
2. เปิด phpMyAdmin จด **เวอร์ชัน MariaDB** ที่หน้าแรกลง §12 (ถ้าเป็น MySQL ไม่ใช่ MariaDB ให้ใช้ `DB_CONNECTION=mysql` ใน §4.6) และตั้ง collation ของ database เป็น `utf8mb4_unicode_ci` (migration ใช้ utf8mb4)
3. host ของ database บน Hostatom มักเป็น `localhost` port `3306` ⚠️ ต้องตรวจสอบจากหน้า Databases

### 4.6 `.env` และ `APP_KEY`

Files > `eduvision/backend/` > **Create File** ชื่อ `.env` แล้วแก้ด้วย Code Editor (หนึ่งตัวแปรต่อบรรทัด ห้ามมีช่องว่างรอบ `=`) ค่าทุกตัวมีคำอธิบายใน `backend/.env.example` ด้านล่างคือชุดสำหรับ production ครบทุกตัวแปรที่แอปอ่าน

```ini
# ---- แอป ----
APP_NAME=EduVision
APP_ENV=production
APP_KEY=base64:...                    # จาก php artisan key:generate --show ในเครื่อง (§2 ข้อ 3) ดูคำเตือนใต้ตาราง
APP_DEBUG=false                       # ห้าม true บน production (หน้า error จะโชว์ .env และ query)
APP_URL=https://teacherhelper.phuwish.com
APP_TIMEZONE=UTC                      # เก็บ UTC แสดง Asia/Bangkok ในแอป (DESIGN §7.6)
APP_LOCALE=th
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=th_TH
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

# ---- log (storage/logs/laravel-YYYY-MM-DD.log เก็บ 14 วัน) ----
LOG_CHANNEL=daily
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info
LOG_DAILY_DAYS=14

# ---- database (จาก §4.5) ----
DB_CONNECTION=mariadb                 # mysql ถ้า phpMyAdmin บอกว่าเป็น MySQL
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

# ---- session / queue / cache: database ทั้งหมด (ไม่มี Redis) ----
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true            # cookie ของ /admin ส่งเฉพาะทาง HTTPS
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300              # > 240 วินาทีของ GradeScanJob กัน worker รอบถัดไปแย่ง job ที่ยังรันอยู่
CACHE_STORE=database
MAIL_MAILER=log

# ---- EduVision (config/eduvision.php) ----
SEED_TEACHER_JOIN_CODE=...            # 8 ตัวสุ่ม (§2 ข้อ 3) รหัสสมัครครูของโรงเรียนแรกที่ seeder สร้าง
HEARTBEAT_MAX_AGE_MINUTES=3           # /health เป็น degraded เมื่อ worker ไม่ได้รันนานกว่านี้
ADMIN_EMAIL=                          # ใส่ชั่วคราวเฉพาะตอน seed admin คนแรก (§4.7 ข้อ 4) แล้วลบออก
ADMIN_PASSWORD=
QR_SIGNING_KEY=...                    # 64 ตัวอักษร (§2 ข้อ 3) ตั้งครั้งเดียว ห้ามเปลี่ยน: ใบงานที่พิมพ์ไปแล้วจะสแกนไม่ได้
WORKSHEET_BATCH_SIZE=10
SCAN_MAX_PAGE_KB=4096
SCAN_MAX_CROP_KB=1024

# ---- Phase 8–9 (DESIGN §19–§21) ค่าตั้งต้นใช้ได้เลย คำอธิบายเต็มอยู่ใน backend/.env.example ----
SUBMISSION_MAX_PAGES=5                # ไฟล์ต่องานส่งแบบรูปทั้งหน้า (หน้า PDF นับรวม)
SUBMISSION_MAX_FILE_MB=10             # MB ต่อไฟล์ (ต้องไม่เกิน upload_max_filesize ใน §4.1)
DOCUMENT_MAX_FILE_MB=10               # ไฟล์เฉลย/ใบโจทย์/เอกสารรายวิชาของครู MB ต่อไฟล์
DOCUMENT_MAX_TOTAL_MB=20              # MB รวมต่อการอ่านหนึ่งครั้ง (ส่งให้ Gemini ใน call เดียว)
DOCUMENT_MAX_PAGES=30                 # หน้าต่อการอ่านหนึ่งครั้ง เกินนี้ครูต้องเลือกช่วงหน้า
DOCUMENT_RETENTION_DAYS=30            # วันที่เก็บไฟล์ของครู (ผลอ่านยังอยู่หลังลบไฟล์)
CLASSROOM_SYNC_MAX_COURSEWORK=20      # การบ้านที่ซิงก์งานส่งได้ต่อรอบ cron
CLASSROOM_SYNC_BUDGET_SECONDS=40      # รอบซิงก์หยุดเริ่มงานใหม่หลังเวลานี้ (worker รอบละ 50 วินาที)
CLASSROOM_ROSTER_SYNC_MINUTES=15      # ซิงก์รายชื่อห้องที่ผูก Classroom เองทุกกี่นาที (นักเรียนใหม่เข้า Google ได้โดยไม่ต้องใช้ PIN)
CLASSROOM_SYNC_MAX_ROSTERS=10         # ห้องที่ซิงก์รายชื่อได้ต่อรอบ cron
GEMINI_PRICE_INPUT_PER_M=0.50         # USD ต่อล้าน token ขาเข้า ใช้แสดงราคาก่อนอ่านเอกสาร ตรวจราคาปัจจุบันของ Google ก่อน
GEMINI_PRICE_OUTPUT_PER_M=3.00        # USD ต่อล้าน token ขาออก (ว่างทั้งคู่ = แสดงเป็น token อย่างเดียว)
USD_THB_RATE=33
MASTERY_PASS_THRESHOLD=0.5            # ตัวชี้วัด "ผ่าน" เมื่อ mastery ถึงค่านี้ (§20.3)
ANALYSIS_BATCH_MAX=200                # คำขอต่อ batch ของการวิเคราะห์รอบกลางคืน (§20.8)
GRADING_BLANK_INK_MAX=0.005           # ช่องที่หมึกน้อยกว่านี้ = "ไม่ได้ตอบ" ไม่ส่ง Gemini (§21.3)
GRADING_CNN_SKIP_ENABLED=false        # คง false จนกว่า calibration กับลายมือจริงผ่าน (§21.10)
GRADING_CNN_SKIP_MIN_CONFIDENCE=0.97
GRADING_CNN_SKIP_SAMPLE_RATE=0.10

# ---- Gemini (§6.1) ----
GEMINI_API_KEY=                       # key กลาง ไม่บังคับ: ครูใส่ key ของตัวเองในแอปได้
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FAKE=false                     # ห้าม true บน production (ถูกเพิกเฉยพร้อม error ใน log)
GEMINI_TIMEOUT=30
GEMINI_CONCURRENCY=8
GEMINI_THINKING_LEVEL=low
GEMINI_SEND_TEMPERATURE=false
GEMINI_MEDIA_SHORT=high               # media resolution ต่อภาพ (§21.5) คง high จนกว่า calibration ผ่าน
GEMINI_MEDIA_WORK=high
GEMINI_MEDIA_PAGE=high                # ภาพทั้งหน้าของงานนักเรียน
GEMINI_MEDIA_DOCUMENT=medium          # เอกสารของครูเท่านั้น
GEMINI_MEDIA_PER_PART=false
GEMINI_PAGE_MAX_QUESTIONS=15          # ข้อต่อ call ของ extract_page/extract_batch (§21.4) เกินนี้แบ่ง call
GEMINI_CALIBRATION_MIN_SAMPLES=40     # เกณฑ์ของ eduvision:calibrate-gemini (§21.10)
GEMINI_CALIBRATION_MAX_DROP=0.02
GEMINI_CALIBRATION_MAX_SCORE_FLIPS=0

# ---- Firebase Cloud Messaging (§6.2) ----
FIREBASE_CREDENTIALS=                 # path เต็มของไฟล์ service account นอก docroot ว่าง = push ลง log เท่านั้น
FIREBASE_PROJECT_ID=
FIREBASE_TIMEOUT=10

# ---- Google Classroom (§6.3) ----
GOOGLE_OAUTH_CLIENT_ID=               # ว่างทั้งคู่ = endpoint ของ Classroom ตอบ 503 google_not_configured
GOOGLE_OAUTH_CLIENT_SECRET=
GOOGLE_OAUTH_REDIRECT_URI=https://teacherhelper.phuwish.com/google/oauth/callback   # ว่าง = APP_URL + /google/oauth/callback ต้องตรงกับที่ลงทะเบียนใน Google (§6.3)
GOOGLE_TIMEOUT=20
GOOGLE_CLASSROOM_APP_LINK=
CLASSROOM_SUGGEST_THRESHOLD=0.7       # นำเข้าคอร์ส: เสนอ "ผูกกับห้องที่มีอยู่" เมื่อนักเรียนในคอร์สอยู่ห้องเดียว ≥ 70% (DESIGN §24.10)

# ---- เข้าสู่ระบบด้วย Google (§6.4) คนละ project กับ Classroom ----
GOOGLE_SIGNIN_CLIENT_IDS=             # client ID แบบ Web ของ project sign-in (ตัวแรก) ว่าง = ปิด ทุก route ตอบ 503 google_signin_not_configured
GOOGLE_SIGNIN_CLIENT_SECRET=          # ใช้กับทางเว็บเท่านั้น (Flutter web) production ที่ใช้แต่แอป Android เว้นว่างได้
GOOGLE_SIGNIN_REDIRECT_URI=           # ว่าง = APP_URL + /auth/google/callback ต้องตรงกับที่ลงทะเบียนใน §6.4 ข้อ 4
GOOGLE_SIGNIN_APP_URL=                # ที่อยู่ของเว็บแอปที่ callback ส่งกลับ: https://teacherhelper.phuwish.com/app (§4.9) ว่าง = ปิดทางเว็บ (Android ยังใช้ได้)
GOOGLE_SIGNIN_MAX_AGE=600             # วินาทีที่รับ ID token หลัง Google ออกให้
```

คำเตือนเรื่อง key

- **`APP_KEY`** เข้ารหัส Gemini key ของครู (`teacher_api_keys.encrypted_key`), refresh token ของ Google (`google_accounts.encrypted_refresh_token`), session และ cache ของ access token ถ้าเปลี่ยน `APP_KEY` ค่าเหล่านี้อ่านไม่ออกทั้งหมด ครูทุกคนต้องใส่ key และเชื่อม Google ใหม่ ถ้าจำเป็นต้องเปลี่ยนจริง ใส่ key เก่าไว้ใน `APP_PREVIOUS_KEYS=` (คั่นด้วย `,`) เพื่อให้ Laravel ถอดรหัสค่าเดิมได้ต่อ
- **`QR_SIGNING_KEY`** เซ็น QR บนใบงานทุกใบ เปลี่ยนแล้วใบงานที่พิมพ์ก่อนหน้าจะสแกนไม่ได้ทั้งหมด (`qr_invalid`)
- ค่าใน `.env` ห้าม commit, ห้ามวางลง issue/แชต และแก้แล้วต้อง `optimize:clear` + `optimize` (§5) เพราะ production ใช้ config cache

ตรวจสอบหลังบันทึก

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://teacherhelper.phuwish.com/.env            # ต้องไม่ใช่ 200
curl -s -o /dev/null -w '%{http_code}\n' https://teacherhelper.phuwish.com/backend/.env    # 404 หรือ 403
curl -s -o /dev/null -w '%{http_code}\n' https://teacherhelper.phuwish.com/composer.json   # 404
```

### 4.7 Scheduled Tasks

Websites & Domains > **Scheduled Tasks** > Add Task (Task type = **Run a PHP script**, PHP version = เดียวกับ §4.1, Notify = "Errors only" ช่วงแรก)

| ชื่อ | Script path | Arguments | ความถี่ (cron style) | หน้าที่ |
|---|---|---|---|---|
| `eduvision-queue-worker` | `eduvision/backend/artisan` | `eduvision:queue-work` | `* * * * *` | dispatch heartbeat และงานรอบ (ตารางถัดไป) แล้วรัน `queue:work --queue=grading,default,pdf --stop-when-empty --max-time=50` หนึ่งรอบ (DESIGN §7.2) ถ้าคิวว่างจบทันที เพิ่ม `--memory=256` ได้เฉพาะเมื่อ `memory_limit` ของ PHP ≥ 512M |
| `eduvision-purge-images` | `eduvision/backend/artisan` | `eduvision:purge-images` | `0 2 * * *` | ลบไฟล์ตามนโยบาย DESIGN §7.3: PDF ใบงานเกิน 30 วัน, ภาพหน้าเต็มของ submission ที่เผยแพร่แล้ว, crop หลัง `crop_retention_until`, สแกนซ้ำที่ไม่ยืนยันเกิน 30 วัน ⚠️ ต้องตรวจสอบ: เวลาของ cron เป็น timezone ของ server (คาดว่า Asia/Bangkok) |
| `eduvision-maintenance` | `eduvision/backend/artisan` | เปลี่ยนตามงาน (ตารางถัดไป) | `0 4 1 1 *` (ไกลๆ) | ใช้ปุ่ม **Run Now** เท่านั้น |

⚠️ ต้องตรวจสอบ: ชื่อช่อง arguments และรูปแบบ path (สัมพัทธ์กับ home หรือ path เต็ม) ในหน้า Add Task ของ Hostatom; Run Now บอกทันทีถ้า path ผิด

**งานรอบที่ `eduvision:queue-work` ทำเองทุกนาที** (ไม่ต้องเพิ่ม Scheduled Task และไม่ใช้ `schedule:run`) แต่ละข้อแยกกัน ข้อใดล้มจะเขียน `queue_work.dispatch_failed` ลง log แล้ว worker ยังตรวจงานต่อตามปกติ

| งาน | เมื่อไร | ทำอะไร | อ้างอิง |
|---|---|---|---|
| heartbeat | ทุกนาที | เขียน `queue_last_run_at` ที่ `/health` แสดง | DESIGN §7.2 |
| ซิงก์ Google Classroom (`ClassroomSyncJob`) | ทุก **5 นาที** (`Cache::add('classroom-sync:lock', 300 วินาที)` บน cache database) เฉพาะเมื่อมี `GOOGLE_OAUTH_CLIENT_ID` | ดึงงานใหม่ที่ครูสร้างในเว็บ Classroom (ร่างเฉลยด้วย AI รอครูอนุมัติ), งานที่นักเรียนส่ง (ดาวน์โหลดไฟล์จาก Drive บน server), คะแนนที่ครูแก้ใน Classroom (คะแนนไม่ตรงกัน), รายชื่อของห้องที่ไม่ได้ซิงก์มานานกว่า `CLASSROOM_ROSTER_SYNC_MINUTES` (นักเรียนใหม่ในคอร์สถูกเพิ่มและจับคู่เอง) ข้ามครูที่ต้องเชื่อม Google ใหม่ จำกัดต่อรอบด้วย `CLASSROOM_SYNC_MAX_COURSEWORK` และ `CLASSROOM_SYNC_BUDGET_SECONDS` ปุ่ม "ซิงก์ตอนนี้" ในแอปเข้าคิวรอบของห้องนั้นทันที | DESIGN §19.3, §19.10 |
| วิเคราะห์รายคนรอบกลางคืน (`BuildAnalysisBatchesJob`) | ครั้งแรกของวันหลัง **01:00 เวลาไทย** (`Cache::add('analysis-nightly:<วันที่>', 36 ชั่วโมง)`) | คำนวณจุดแข็ง/จุดที่ควรฝึกจาก mastery แล้วส่งเฉพาะนักเรียนที่ข้อมูลเปลี่ยนให้ Gemini Batch API (ราคาครึ่งหนึ่ง) batch ละ key หนึ่งตัว ห้องที่ไม่มี key ใดเลยถูกข้าม | DESIGN §20.8 |
| ถามผล batch (`PollAnalysisBatchJob`) | ทุกนาที สำหรับ batch ที่ส่งแล้วและถามครั้งล่าสุดเกิน 1 นาที | เก็บผลที่เสร็จลง `student_analyses` (ครูอนุมัติก่อนนักเรียนเห็น) batch ที่ค้างเกิน 10 นาทีระหว่างสร้าง/เก็บผลถูกตั้งเป็นล้มเหลว และ batch ที่ไม่เสร็จใน 72 ชั่วโมงหมดอายุ แถวของนักเรียนจึงไปรอบคืนถัดไป | DESIGN §20.8 |

ผลคือ worker รอบหนึ่งอาจใช้เวลาจนเกือบ 50 วินาทีเมื่อมีงานซิงก์ ปกติ และ cron ต้องค้างได้ ≥ 55 วินาที (§2 ข้อ 1) ถ้า cron ของ hosting ไม่ใช้ timezone ไทย ไม่มีผล เพราะโค้ดคิด 01:00 ตาม `Asia/Bangkok` เอง

**Arguments ของ `eduvision-maintenance` ที่ใช้บ่อย** (แก้ค่าในช่อง arguments แล้วกด Run Now อ่าน output ในหน้าเดียวกัน)

| งาน | Arguments | หมายเหตุ |
|---|---|---|
| migration (ครั้งแรกและทุกครั้งที่ deploy) | `migrate --force` | ต้องมี `--force` เพราะ `APP_ENV=production` จะถามยืนยันและใน task ถูกยกเลิกเงียบๆ |
| โรงเรียนแรก (ทดลอง/M0) | `db:seed --class=SchoolSeeder --force` | สร้างโรงเรียน 1 แห่งที่ `teacher_join_code` = `SEED_TEACHER_JOIN_CODE` โรงเรียนจริงเพิ่มใน `/admin` ได้ |
| admin คนแรก | `db:seed --class=AdminSeeder --force` | ต้องมี `ADMIN_EMAIL`/`ADMIN_PASSWORD` ใน `.env` ตอนรัน แล้ว**ลบสองค่านั้นออก** (seeder ไม่สร้างอะไรเมื่อว่าง) |
| cache config/route/view หลังแก้ `.env` หรือ deploy | `optimize` แล้ว `filament:optimize` | ห้ามลืม `optimize:clear` ก่อนถ้าแก้ `.env` (config cache ทำให้ค่าใหม่ไม่ถูกอ่าน) |
| ล้าง cache | `optimize:clear` แล้ว `filament:optimize-clear` | หลังจากนี้ `queue_last_run_at` ใน /health เป็น null ราว 1 นาทีจนกว่า worker รอบถัดไปจะเขียนใหม่ (cache:clear ล้าง table `cache`) ถือว่าปกติ |
| asset ของ Filament | `filament:assets` | เฉพาะถ้า `/admin/login` ขึ้นแต่ไม่มี CSS (asset ถูก commit มาใน `public/css/filament`, `public/js/filament`, `public/fonts/filament` แล้ว) |
| ตรวจ Gemini | `eduvision:gemini-check --generate` | §6.1 |
| ตรวจว่าโมเดลจริงจับ prompt injection ได้ | `eduvision:gemini-check --injection` | ส่งภาพตัวอย่าง 5 ภาพใน `backend/tests/fixtures/injection` (ภาพสังเคราะห์ ไม่มีข้อมูลนักเรียน) = 5 request; ทุกภาพต้องขึ้น `ok` และบรรทัดสุดท้าย `injection: 5/5 as expected` ถ้ามี `MISMATCH` แจ้งผู้พัฒนาเพื่อปรับ prompt |
| ตรวจ FCM | `eduvision:fcm-check` | §6.2 |
| ลงทะเบียนโมเดล | `eduvision:register-model <path เต็มของโฟลเดอร์โมเดล>` | §7 |
| import ตัวชี้วัดจาก CSV | `eduvision:import-skills <path เต็มของไฟล์ csv>` | §8 (หรือใช้หน้า Filament) |
| export ข้อมูลสำหรับ notebook | `eduvision:export-observations --output=<path เต็ม>` | ดู `php artisan eduvision:export-observations --help` |
| ดูสถานะ migration / คิวที่ล้ม | `migrate:status` / `queue:failed` | อ่านอย่างเดียว |

ลำดับครั้งแรก

1. Run Now `migrate --force` → output ต้องขึ้น `DONE` ครบทุกไฟล์ใน `database/migrations/` (50 ไฟล์ ณ 30 ก.ย. 2569) phpMyAdmin เห็น table `schools`, `users`, `personal_access_tokens`, `jobs`, `cache`, `sessions`, `ai_calls`, `model_versions` ฯลฯ
2. Run Now `db:seed --class=SchoolSeeder --force` (ถ้าต้องการโรงเรียนแรกจาก `.env`)
3. ใส่ `ADMIN_EMAIL`/`ADMIN_PASSWORD` ใน `.env` → Run Now `db:seed --class=AdminSeeder --force` → ลบสองค่านั้นออกจาก `.env` → login `/admin` แล้วเปลี่ยนรหัสผ่านทันที
4. Run Now `optimize` แล้ว `filament:optimize`
5. เปิด `eduvision-queue-worker` และ `eduvision-purge-images` ปล่อยไว้ 3 นาที

ตรวจสอบ: phpMyAdmin เห็น table ครบ, `cache` มี key ที่ลงท้าย `queue.last_run_at` และหน้า Scheduled Tasks ไม่มีอีเมล error

### 4.8 ตรวจจาก Mac (smoke check)

```bash
B=https://teacherhelper.phuwish.com
H=(-H 'Content-Type: application/json' -H 'Accept: application/json')

# 1. health: db ok และ heartbeat ขยับ (เกณฑ์ M0 ข้อ 3)
curl -s $B/api/v1/health | jq .                       # {"status":"ok","db":"ok","queue_last_run_at":"..."}
sleep 70; curl -s $B/api/v1/health | jq -r .queue_last_run_at   # ต้องใหม่กว่าครั้งแรก

# 2. security headers (SecurityHeaders middleware) รวม HSTS เพราะมาทาง HTTPS
curl -sI $B/api/v1/health | grep -iE 'strict-transport|x-content-type|x-frame|referrer-policy|permissions-policy|cache-control|content-security|x-robots'
#   strict-transport-security: max-age=31536000; includeSubDomains   ← ถ้าไม่มีบรรทัดนี้ ดู §11 "ไม่มี HSTS"
#   x-content-type-options: nosniff / x-frame-options: SAMEORIGIN / cache-control: no-store, private / content-security-policy: default-src 'none'; frame-ancestors 'none'

# 3. ไฟล์นอก docroot เข้าถึงไม่ได้ และ admin boot ได้
curl -s -o /dev/null -w '%{http_code}\n' $B/.env            # ต้องไม่ใช่ 200
curl -s -o /dev/null -w '%{http_code}\n' $B/composer.json   # 404
curl -s -o /dev/null -w '%{http_code}\n' $B/admin/login     # 200

# 4. error envelope {message, errors, code} แม้ไม่ส่ง Accept header
curl -s $B/api/v1/me; echo                                  # {"message":"กรุณาเข้าสู่ระบบ","errors":{},"code":"unauthenticated"}
curl -s "${H[@]}" $B/api/v1/classrooms/abc; echo            # code not_found

# 5. rate limit ของ login ครู (10 ครั้ง/นาที/IP) ครั้งที่ 11 ต้องได้ 429 + Retry-After
for i in $(seq 1 11); do curl -s -o /dev/null -w '%{http_code} ' "${H[@]}" -X POST $B/api/v1/auth/teacher/login -d '{"email":"nobody@example.com","password":"wrong-password"}'; done; echo

# 6. สมัครครู ไม่ต้องใช้รหัสโรงเรียน (หลังรอ 1 นาทีให้ throttle หมด) ถ้ามีหลายโรงเรียนใส่ "school_id" จาก GET /api/v1/auth/schools
curl -s "${H[@]}" $B/api/v1/auth/schools; echo              # {"data":[{"id":1,"name":"..."}]} ชื่ออย่างเดียว ไม่มีรหัส
curl -s "${H[@]}" -X POST $B/api/v1/auth/teacher/register -d '{"name":"ครูทดสอบ","email":"t1@example.com","password":"secret1234"}'
#   201 และบัญชีเป็น pending → อนุมัติใน /admin > Users แล้ว login จากแอปได้
```

ผ่านครบ = server พร้อมให้แอปใช้ จด base URL `https://teacherhelper.phuwish.com` ให้ฝั่ง Flutter (`--dart-define=API_BASE_URL=...`, ดู `app/README.md`)

### 4.9 เว็บแอป (`/app/`, DESIGN §25)

เว็บแอปคือ build ของ `app/` ที่วางเป็นไฟล์ static ใน `backend/public/app/` ครูและนักเรียนเปิดที่ `https://teacherhelper.phuwish.com/app/` (หน้าแรก `/` พาไปเอง) ทำหลัง §4.1–§4.7 เสร็จและ health ผ่านแล้ว

1. **build บน Mac** (ต้องมี Flutter; ใช้เวลาราว 1 นาที)
   ```bash
   tools/build-web.sh                                   # API = https://teacherhelper.phuwish.com
   # ได้ ~/eduvision-deploy/eduvision-web.zip (ราว 15 MB) และโฟลเดอร์ ~/eduvision-deploy/app/
   ```
2. **อัปโหลด** Plesk > Files > ไปที่ `eduvision/backend/public/` > Upload `eduvision-web.zip` > เลือกไฟล์ > **Extract Files** (ติ๊กแทนที่ไฟล์เดิม) ต้องได้ `eduvision/backend/public/app/index.html` แล้วลบไฟล์ zip ทิ้ง
   - File Manager ซ่อนไฟล์ที่ขึ้นต้นด้วยจุด ตรวจว่ามี `app/.htaccess` (header ความปลอดภัยของเว็บแอป) ถ้า extract แล้วไม่มี ให้อัปโหลดไฟล์นี้จาก `~/eduvision-deploy/app/.htaccess` เอง
3. **ตรวจจาก Mac**
   ```bash
   curl -sI https://teacherhelper.phuwish.com/ | grep -i '^location'          # location: .../app/
   curl -sI https://teacherhelper.phuwish.com/app/ | grep -iE '^(HTTP|content-security-policy|x-frame-options|cache-control)'
   curl -s https://teacherhelper.phuwish.com/app/build-commit.txt             # commit ที่ build
   ```
   - ไม่มี `content-security-policy`: hosting ไม่มี `mod_headers` หรือไม่อ่าน `.htaccess` ในโฟลเดอร์ย่อย เว็บยังใช้ได้ แต่ให้บันทึกลง §12 และแจ้ง Hostatom
   - `/app/` ได้ 500: `.htaccess` มีคำสั่งที่ hosting ไม่อนุญาต ลบ `app/.htaccess` ชั่วคราวแล้วบันทึกลง §12
4. เปิด `https://teacherhelper.phuwish.com/` ใน Chrome: ต้องเห็นหน้าเข้าสู่ระบบ ลอง login ครูและนักเรียน (PIN)
5. **Google sign-in บนเว็บ** (ไม่บังคับ): ตั้ง `.env` ตาม §6.4 ข้อ 6 โดย `GOOGLE_SIGNIN_APP_URL=https://teacherhelper.phuwish.com/app` (ไม่มี `/` ท้าย)

- ⚠️ ต้องตรวจสอบ: หลังกด Deploy ของ Git รอบถัดไป โฟลเดอร์ `backend/public/app/` (ไม่อยู่ใน git) ยังอยู่หรือไม่ (ข้อเดียวกับ `.env`/`vendor` ใน §3) ถ้าถูกลบ ให้ extract zip ซ้ำทุกครั้งหลัง Deploy และบันทึกลง §12
- สิ่งที่ทำบนเว็บไม่ได้ (สแกนด้วยกล้อง สแกนบัตร QR) อยู่ใน DESIGN §25.2 ครูใช้ **"อัปโหลดรูปเพื่อตรวจ"** แทน
- เครื่องที่ใช้ร่วมกัน: บอกนักเรียนให้กดออกจากระบบทุกครั้ง (DESIGN §25.3)

---

## 5. Deploy ครั้งถัดไป (อัปเดตโค้ด)

CI (`.github/workflows/backend.yml`) รัน test บน PHP 8.3 + MariaDB, pint และ gitleaks ทุก push ที่แตะ `backend/` **ดูให้เขียวก่อน** แล้วจึง

1. Websites & Domains > Git > **Pull Updates** → **Deploy** (ถ้าเปิด Automatic deployment ไว้ ขั้นนี้เกิดเองเมื่อ push)
2. ถ้า commit นั้นแก้ `composer.lock`: ทำ §4.3 ซ้ำ (สลับ docroot → Composer Install → สลับกลับ) หรืออัปโหลด `vendor.zip` ใหม่
3. `eduvision-maintenance` Run Now: `migrate --force` (ถ้ามี migration ใหม่; ดู output)
   - **รอบบัญชีนักเรียนระดับโรงเรียน (DESIGN §24) มี migration 4 ไฟล์** (`2026_10_01_000005` ถึง `2026_10_02_000002`) ตัวสุดท้าย**สร้าง `classroom_google_links` ใหม่แล้วคัดลอกแถว** (เปลี่ยน primary key) **export database จาก phpMyAdmin ก่อน** (§9) ทดสอบ up → down → up บน MariaDB 11.8 ในเครื่องแล้ว แต่ยังไม่เคยรันบน MariaDB ของ hosting ถ้า output มี error ให้หยุด อย่ารันซ้ำ แล้วส่ง output มาดู
4. Run Now: `optimize:clear` → `optimize` → `filament:optimize` (ทุกครั้ง เพราะ route/config/view ถูก cache ไว้)
5. `curl -s https://teacherhelper.phuwish.com/api/v1/health | jq .` และเปิด `/admin` หนึ่งหน้า
6. ถ้าแอปเวอร์ชันใหม่ต้องการ API ใหม่ ให้ deploy server **ก่อน** แจก APK
7. ถ้า commit นั้นแก้ `app/`: build และอัปโหลดเว็บแอปใหม่ (§4.9 ข้อ 1–3) **หลัง**ข้อ 1–5 เพื่อให้ API ใหม่พร้อมก่อนหน้าเว็บใหม่ และตรวจว่า Deploy ไม่ได้ลบ `backend/public/app/`

ช่วงระหว่างข้อ 1–4 (ไม่กี่นาที) request อาจได้ 500 ถ้า migration ยังไม่รัน ทำนอกเวลาที่ครูใช้งาน ถ้าต้องการปิดชั่วคราว: Run Now `down --retry=60` ก่อนข้อ 1 และ `up` หลังข้อ 4 (API จะตอบ `503 service_unavailable` ระหว่างนั้น)

Rollback: Git > "Change branch and path" ชี้ commit/branch ก่อนหน้า → Deploy → ทำข้อ 2–4 (migration ไม่ย้อนกลับเอง ถ้า migration ใหม่ทำลายข้อมูล ให้กู้จาก backup แทน ดู §9)

---

## 6. บริการภายนอก (ไม่บังคับ)

ทุกอย่างในหมวดนี้ปิดได้: ระบบตรวจงานทำงานด้วย key ของครู, push ลง log, Classroom ตอบ `503 google_not_configured` และ Google sign-in ตอบ `503 google_signin_not_configured` (แอปซ่อนปุ่ม)

### 6.1 Gemini (DESIGN §10.1)

- **ค่าเริ่มต้นคือครูใส่ key ของตัวเองในแอป** (หน้าตั้งค่า > Gemini key) เก็บเข้ารหัสใน `teacher_api_keys` แสดงเฉพาะ 4 ตัวท้าย ไม่มี key ของใครออกทาง API, log หรือ `ai_calls` (มี test ตรวจ) ข้อที่ไม่มี key ให้ใช้จะเข้าคิวครูเป็น "ตรวจเอง" (`ai_key_missing`) และครูกด requeue ได้หลังใส่ key
- **key กลาง** (ไม่บังคับ): สร้างใน Google AI Studio / Cloud Console บน billing account แบบ**เสียเงิน** (tier ฟรีอาจนำข้อมูลไปเทรน) จำกัด key ให้ใช้ได้เฉพาะ Generative Language API และตั้ง budget alert ใน Cloud Billing แล้วใส่ `GEMINI_API_KEY=` ใน `.env` (ตามด้วย `optimize:clear`/`optimize`)
- ตรวจจาก server: `eduvision-maintenance` Run Now `eduvision:gemini-check --generate` (แสดง key แค่ 4 ตัวท้าย ไม่เขียน `ai_calls`) หรือ `eduvision:gemini-check --teacher=<user id>` เพื่อตรวจ key ที่ครูคนหนึ่งบันทึกไว้
- `GEMINI_FAKE` ต้องเป็น `false` (production เพิกเฉยค่า true พร้อม `gemini.fake_refused` ใน log)
- ค่าใช้จ่ายและ error ดูที่ `/admin` > AI calls (`ai_calls` เก็บ token และผลลัพธ์ ไม่เก็บภาพหรือชื่อนักเรียน)
- ถ้า key หลุด: Cloud Console > Credentials > ลบ/สร้างใหม่ แล้วแก้ `.env` (key ของครูให้ครูเปลี่ยนในแอป)

### 6.2 Firebase Cloud Messaging (DESIGN §9.9)

1. Firebase console > Project settings > **Service accounts** > Generate new private key (ไฟล์ JSON)
2. Files > สร้างโฟลเดอร์ `eduvision-private` ที่ระดับ home (นอก docroot ทุกตัว) > Upload ไฟล์ JSON ไปที่นั่น ตั้ง permission 600 ถ้าทำได้
3. `.env`: `FIREBASE_CREDENTIALS=<path เต็ม>/eduvision-private/<ชื่อไฟล์>.json` (path สัมพัทธ์จะถูกตีความจาก `backend/`) → `optimize:clear`/`optimize`
4. Run Now `eduvision:fcm-check` → `access token ok`; `eduvision:fcm-check --user=<user id>` ส่ง push ทดสอบไปเครื่องของผู้ใช้นั้น (ไม่พิมพ์ key หรือ device token)
5. แอปต้องมี `google-services.json` ของ project เดียวกัน (ดู `app/README.md`) ไฟล์นี้ก็ห้าม commit

ไฟล์ key เสีย/ไม่มี = แอปยังทำงานปกติ push แค่ถูกเขียนลง log (`LogNotifier`) พร้อม `fcm.credentials_invalid` หนึ่งบรรทัด

### 6.3 Google Classroom (DESIGN §18, KICKOFF ส่วนที่ 6)

1. ทำ G1–G5 ใน KICKOFF ส่วนที่ 6: project เดียวกับ Firebase, เปิด Classroom API + Drive API, OAuth consent screen (โหมด Testing: refresh token หมดอายุ 7 วัน ครูต้องเชื่อมใหม่), OAuth client แบบ **Web application** (server ใช้แลก code) และแบบ **Android** (แอปใช้)
   - **scope ที่ server ต้องได้ครบ 7 ตัว** (`GoogleScopes::REQUIRED`): `classroom.courses.readonly`, `classroom.rosters.readonly`, `classroom.profile.emails`, `classroom.coursework.students`, `drive.file`, `drive.readonly` และ **`classroom.announcements`** (ใหม่ใน Phase 8: ประกาศผลส่วนตัวรายคน DESIGN §19.7) ต้องเพิ่มใน consent screen ด้วย (KICKOFF G3)
   - **ครูที่เชื่อมไว้ก่อน Phase 8 ต้องกด "เชื่อมใหม่"** (ตั้งค่า → Google Classroom) เพื่อให้สิทธิ์ประกาศ ระหว่างนั้น `GET /google/status` ตอบ `needs_reconnect: true` พร้อม `reconnect_message` แอปขึ้นแบนเนอร์ "ต้องเชื่อมบัญชี Google ใหม่" การซิงก์และประกาศของครูคนนั้นหยุดจนกว่าจะเชื่อมใหม่
2. **ลงทะเบียน redirect URI ของการเชื่อมผ่านเบราว์เซอร์**: Google Cloud Console → APIs & Services → Credentials → client แบบ Web → *Authorized redirect URIs* → เพิ่ม `https://teacherhelper.phuwish.com/google/oauth/callback` (ตรงทุกตัวอักษรกับ `GOOGLE_OAUTH_REDIRECT_URI` หรือ `APP_URL` + `/google/oauth/callback` ถ้าเว้นว่าง; ไม่มี `/` ท้าย) → Save (มีผลภายในไม่กี่นาที) ใช้ตอนครูเชื่อมจากเว็บ (Flutter web) หรือจากแอปที่ไม่มี `GOOGLE_SERVER_CLIENT_ID`: server ส่งครูไปหน้า consent ของ Google แล้ว Google พาเบราว์เซอร์กลับมาที่ `GET /google/oauth/callback` ของเรา *Authorized JavaScript origins* ไม่ต้องใส่ ถ้าทดสอบบนเครื่อง dev ด้วย client เดียวกัน เพิ่ม `http://127.0.0.1:8000/google/oauth/callback` ด้วย
3. `.env`: `GOOGLE_OAUTH_CLIENT_ID=` และ `GOOGLE_OAUTH_CLIENT_SECRET=` ของ client แบบ Web และ `GOOGLE_OAUTH_REDIRECT_URI=https://teacherhelper.phuwish.com/google/oauth/callback` (code จากแอป Android ผ่าน `POST /google/connect` ไม่ใช้ค่านี้) → `optimize:clear`/`optimize`
4. client ID เดียวกันไปที่แอป Android เป็น `--dart-define=GOOGLE_SERVER_CLIENT_ID=...` (ไม่บังคับ: ไม่ใส่ แอปเชื่อมผ่านเบราว์เซอร์แทน ตั้งแต่ Phase 8 server ดาวน์โหลดไฟล์งานที่ส่งจาก Drive เอง แอปจึงไม่ต้องใช้ค่านี้เพื่อสแกนงานจาก Classroom)
5. hosting ต้องเรียก `oauth2.googleapis.com`, `classroom.googleapis.com`, `www.googleapis.com` ออกไปได้ (probe ตรวจ `oauth2.googleapis.com` แล้ว) cron ซิงก์ทุก 5 นาทีและดาวน์โหลดไฟล์ที่นักเรียนแนบบน server (ไฟล์ละไม่เกิน `SUBMISSION_MAX_FILE_MB`)
6. ทดสอบจากแอป: ตั้งค่า > เชื่อม Google Classroom → (ผ่านเบราว์เซอร์: หน้า "เชื่อม Google Classroom สำเร็จ" ที่ `/google/oauth/callback`) → `GET /api/v1/google/status` ตอบ `configured: true, connected: true` ถ้าหน้า Google ขึ้น `Error 400: redirect_uri_mismatch` แปลว่า URI ในข้อ 2 กับ `.env` ไม่ตรงกัน (ดู `redirect_uri` ในลิงก์ที่แอปเปิดได้)
7. ถ้า secret หลุด: Credentials > เลือก Web client > **Reset secret** แล้วแก้ `.env`

### 6.4 เข้าสู่ระบบด้วย Google (DESIGN §24.9, KICKOFF ส่วนที่ 6 ข้อ G9–G11)

Google sign-in ใช้ **Google Cloud project แยกจาก Classroom** เพราะขอแค่ `openid email profile` ซึ่ง publish ได้โดยไม่ต้องผ่าน verification ส่วน project ของ Classroom (§6.3) ยังอยู่โหมด Testing ตามเดิม ไม่ต้องเปิด API ใดเลย (ใช้แค่ ID token) ไม่ทำส่วนนี้ = ปุ่ม Google ไม่แสดงในแอปและทุกอย่างอื่นใช้ได้ตามปกติ

1. **สร้าง project** Google Cloud Console → เลือก project (มุมซ้ายบน) → **New project** ชื่อ `eduvision-signin` (ชื่อไม่สำคัญ แต่ต้องเป็นคนละ project กับ Classroom/Firebase) → Create แล้วเลือก project นี้ให้แน่ใจก่อนทำข้อต่อไป
2. **หน้าจอขอความยินยอม** (Google Auth Platform หรือ APIs & Services → OAuth consent screen)
   - Branding: App name `EduVision`, User support email และ Developer contact = อีเมลของทีม **ไม่ต้องใส่โลโก้** (ใส่โลโก้แล้ว Google ต้องตรวจ brand ก่อนแสดง) Authorized domains: `phuwish.com` Application home page และ privacy policy ใส่ได้ถ้ามี
   - Audience: User type **External** → แล้วกด **Publish app** (In production) หลังตั้งเสร็จ scope พื้นฐานไม่ต้องผ่าน verification ถ้าค้างโหมด Testing จะใช้ได้เฉพาะ test users และบัญชีอื่นได้ error `access_denied`
   - Data access (Scopes): `openid`, `.../auth/userinfo.email`, `.../auth/userinfo.profile` เท่านั้น ห้ามเพิ่ม scope ของ Classroom หรือ Drive ใน project นี้
3. **client แบบ Web application** Credentials → Create credentials → OAuth client ID → Application type **Web application** ชื่อ `EduVision sign-in web`
   - client ID ตัวนี้คือ `aud` ของ ID token **ทุกตัว** (แอป Android ส่งค่านี้เป็น `serverClientId`) จึงต้องสร้างแม้จะไม่ใช้ทางเว็บ
4. **Authorized redirect URIs** ของ client Web (ใช้กับทางเว็บเท่านั้น): `https://teacherhelper.phuwish.com/auth/google/callback` และตอน dev `http://127.0.0.1:8000/auth/google/callback` (ตรงทุกตัวอักษร ไม่มี `/` ท้าย คนละ path กับ `/google/oauth/callback` ของ Classroom) *Authorized JavaScript origins* ไม่ต้องใส่ → Create แล้วจด **client ID** และ **client secret** (secret ใส่ `.env` เท่านั้น ห้าม commit หรือวางในแชต)
5. **client แบบ Android** Create credentials → OAuth client ID → **Android**: package name `com.eduvision.app` และ **SHA-1** ของ keystore ที่เซ็นแอป
   - debug (เครื่อง dev): `keytool -list -v -keystore ~/.android/debug.keystore -alias androiddebugkey -storepass android -keypass android | grep SHA1`
   - release: SHA-1 ของ keystore ที่ใช้เซ็น APK ที่แจก (ถ้าผ่าน Play App Signing ใช้ SHA-1 ในหน้า App integrity ของ Play Console) สร้างหนึ่ง client ต่อ SHA-1
   - ⚠️ ต้องตรวจสอบตอนสร้าง (DESIGN §24.9.1): Google ไม่ยอมให้ package + SHA-1 เดียวกันอยู่ในสอง project ถ้า project Classroom มี Android client ของ SHA-1 นี้อยู่ (KICKOFF G5) ให้ **ลบ Android client ตัวนั้นใน project Classroom ก่อน** แล้วสร้างที่ project sign-in Classroom ไม่เสียอะไร เพราะแอปที่ตั้ง sign-in แล้วเชื่อม Classroom ผ่านเบราว์เซอร์เสมอ (client ID ของ Android ไม่ต้องใส่ `.env`)
6. **`.env` บน server** (§4.6): `GOOGLE_SIGNIN_CLIENT_IDS=<client ID แบบ Web จากข้อ 3>` (ถ้าต้องรับ token จาก client อื่นด้วยให้ต่อท้ายคั่นด้วย `,` ตัวแรกต้องเป็น Web) ทางเว็บ (ไม่บังคับ): `GOOGLE_SIGNIN_CLIENT_SECRET=<secret>`, `GOOGLE_SIGNIN_REDIRECT_URI=https://teacherhelper.phuwish.com/auth/google/callback` และ `GOOGLE_SIGNIN_APP_URL=<ที่อยู่ของแอปเว็บ>` → Run Now `optimize:clear` → `optimize`
7. **build แอป** ด้วย `--dart-define=GOOGLE_SIGNIN_CLIENT_ID=<client ID แบบ Web จากข้อ 3>` (ค่าเดียวกับตัวแรกของ `GOOGLE_SIGNIN_CLIENT_IDS` ไม่ใช่ client ID แบบ Android) แอปที่ตั้งค่านี้เชื่อม Classroom ผ่านเบราว์เซอร์เสมอ (DESIGN §24.9.4) ในเครื่อง dev:
   ```bash
   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000 --dart-define=GOOGLE_SIGNIN_CLIENT_ID=<web client id>
   # เว็บในเครื่อง dev: พอร์ตต้องตรงกับ GOOGLE_SIGNIN_APP_URL ของ .env ในเครื่อง เช่น http://localhost:5173 (production ใช้ tools/build-web.sh, §4.9)
   flutter run -d chrome --web-port=5173 --dart-define=API_BASE_URL=http://127.0.0.1:8000
   ```
8. **ตั้งค่าของโรงเรียนใน `/admin`** (Filament → โรงเรียน → แก้ไข → ส่วน "เข้าสู่ระบบด้วย Google"): "โดเมนที่อนุญาต" (เช่น `school.ac.th` ว่าง = ทุกโดเมน) และสวิตช์ **"ให้นักเรียนเข้าสู่ระบบด้วย Google"** ซึ่ง**ปิดเป็นค่าตั้งต้น** เปิดเมื่อโรงเรียนมีความยินยอมของผู้ปกครองแล้วเท่านั้น (PDPA DESIGN §24.14) ปุ่ม "ลบการเชื่อม Google ของนักเรียนทั้งหมด" อยู่หัวหน้าเดียวกัน
9. **ตรวจ**
   ```bash
   curl -s https://teacherhelper.phuwish.com/api/v1/auth/google/config | jq .
   # {"data":{"enabled":true,"web_flow":false,"notice_version":"gsi-1"}}  (web_flow true เมื่อตั้งข้อ 6 ทางเว็บครบ)
   ```
   - hosting ต้องเรียก `https://www.googleapis.com/oauth2/v3/certs` ได้ (กุญแจตรวจลายเซ็น แคชใน cache database) และ `oauth2.googleapis.com` (เฉพาะทางเว็บ)
   - ครู: ถ้าอีเมลของบัญชี Google ตรงกับอีเมลที่สมัครไว้ กด Google ในแท็บครูแล้วเข้าได้เลย (เชื่อมอัตโนมัติ) ถ้าไม่ตรง ให้ login ด้วยรหัสผ่านแล้วเชื่อมที่ตั้งค่า → "บัญชี Google สำหรับเข้าสู่ระบบ" admin เชื่อมที่หน้า `/admin-home` ของแอปหลัง login ด้วยรหัสผ่าน (ไม่เชื่อมอัตโนมัติ)
   - นักเรียน: กด Google ในแท็บนักเรียน ครั้งแรกเข้าหน้า "ยืนยันตัวตนครั้งแรก" (รหัสห้อง + เลขที่ + PIN หรือสแกนบัตร) ครั้งต่อไปเข้าได้ทันที
   - ⚠️ **ตรวจ `sub` = `userId` ของ Classroom** (DESIGN §24.9.3): ใช้บัญชีนักเรียนทดสอบที่อยู่ในคอร์สทดลอง (KICKOFF G7) ที่ครูนำเข้าห้องแล้ว กด Google ในแท็บนักเรียน ถ้าเข้าได้ทันทีโดยไม่ถาม PIN แปลว่าตรงกัน (การเชื่อมเป็น `classroom_roster`) ถ้าถาม PIN แปลว่าไม่ตรง ไม่มีอะไรพัง (ยืนยันด้วย PIN ได้) แต่ให้บันทึกผลใน §12
10. ถ้า secret หลุด: Credentials → client Web → **Reset secret** แล้วแก้ `.env` (ทางเว็บเท่านั้นที่ใช้ secret)

---

## 7. ลงทะเบียนโมเดลอ่านตัวเลข (`model_versions`)

แอปโหลดโมเดล TFLite ที่ active จาก `GET /api/v1/ml/models/active` (DESIGN §9.8) ไฟล์ `.tflite` ไม่อยู่ใน git (`*.tflite` ถูก ignore) มีเฉพาะ `ml/models/digit_crnn/0.1.0/metrics.json` และ `model.tflite.sha256`

**ทางหลัก: Scheduled Task** (ไม่ติดขีดจำกัดขนาดอัปโหลดของ PHP)

1. Files > `eduvision/ml/models/digit_crnn/0.1.0/` > Upload `model.tflite` (จากเครื่องนักพัฒนา `ml/models/digit_crnn/0.1.0/model.tflite` ประมาณ 1.3 MB) ไว้ข้าง `metrics.json`
2. `eduvision-maintenance` Run Now: `eduvision:register-model <path เต็มของ home>/eduvision/ml/models/digit_crnn/0.1.0`
   คำสั่งตรวจ sha256 ของไฟล์กับ `metrics.json`, คัดลอกไป `storage/app/private/models/digit_crnn/0.1.0.tflite` และ upsert แถวใน `model_versions` พร้อม metrics ทั้งก้อน แล้ว**เปิดใช้ทันที** (ใส่ `--no-activate` ถ้ายังไม่ต้องการ)
3. ลบ `model.tflite` ที่อัปโหลดไว้ใน `ml/models/...` ได้ (สำเนาจริงอยู่ใน `storage/`) หรือเก็บไว้เพื่อ Deploy ครั้งถัดไปไม่ต้องอัปโหลดซ้ำ ⚠️ ต้องตรวจสอบว่า Plesk Git ไม่ลบไฟล์ที่ไม่อยู่ใน repo (§3)
4. ตรวจ: login ครูจากแอป หรือ `curl -s -H "Authorization: Bearer <token>" https://teacherhelper.phuwish.com/api/v1/ml/models/active | jq .` ได้ `name: digit_crnn`, `version: 0.1.0`, `sha256` ตรง `metrics.json`

**ทางเลือก: อัปโหลดผ่าน `/admin` > Model versions** ฟอร์มรับไฟล์ได้ถึง 8 MB แต่ถูกจำกัดด้วย `upload_max_filesize`/`post_max_size` ของ PHP บน Plesk (§4.1) ถ้าอัปโหลดล้ม (413 หรือฟอร์มเงียบ) ใช้ทางหลักแทน

เวอร์ชันใหม่จาก `ml/` (ดู `ml/README.md`): export ได้โฟลเดอร์ `ml/models/<name>/<version>/` ใหม่ → commit `metrics.json` + `.sha256` → deploy → ทำข้อ 1–2 ด้วยเวอร์ชันใหม่ เวอร์ชันเก่ายังอยู่ใน `model_versions` สลับ active ได้ใน `/admin`

---

## 8. ตัวชี้วัดและข้อมูลตั้งต้น

- **โรงเรียน**: `/admin` > Schools สร้างโรงเรียน (`teacher_join_code` ระบบสุ่มให้ อยู่ในหัวข้อ "รหัสสมัครครูสำหรับแอปรุ่นเก่า (ไม่จำเป็น)" ที่ย่อไว้ ไม่ต้องแจกครู DESIGN §17 #70) ตั้ง `allow_training_data` และ `crop_retention_until` ต่อโรงเรียนที่นี่ (DESIGN §7.5)
- **ครู**: สมัครจากแอปโดยเลือกโรงเรียน (มีโรงเรียนเดียวแอปแสดงชื่อให้) ไม่ต้องใช้รหัสโรงเรียน แล้ว admin อนุมัติใน `/admin` > ผู้ใช้และครู (`pending` → `active`) **การอนุมัตินี้เป็นด่านเดียว** ตรวจชื่อและอีเมลก่อนกด เมนูมีตัวเลขครูที่รออนุมัติ
- **ตัวชี้วัด (Q-matrix, DESIGN §2.3)**: ไฟล์ CSV จาก `docs/curriculum/` (ทีมทำ) import ได้สองทาง
  - `/admin` > Skills > Import CSV (ไฟล์ ≤ 2 MB)
  - หรืออัปโหลด CSV ผ่าน Files แล้ว Run Now `eduvision:import-skills <path เต็ม>` (เพิ่ม `--school=<id>` สำหรับทักษะย่อยของโรงเรียนเดียว) import ซ้ำได้: upsert ตาม `skill_code`
- **ข้อมูลสาธิต**: `db:seed --force` (ทั้ง DatabaseSeeder) จะสร้างโรงเรียนสาธิต + admin (ถ้าตั้ง env) + ตัวชี้วัดตัวอย่างจาก `database/data/skills_sample.csv` เหมาะกับ server ทดลองเท่านั้น production ใช้ seeder แยกตาม §4.7

---

## 9. ความปลอดภัย

### สิ่งที่โค้ดทำให้แล้ว (มี test ใน `backend/tests/Feature/Security/`)

| เรื่อง | รายละเอียด |
|---|---|
| Authorization ทุก endpoint | ทุก route ใต้ `/api/v1` มีแถวใน `AuthorizationMatrixTest` (guest 401, ผิด role 403, ครูโรงเรียนอื่น/ครูร่วมโรงเรียนที่ไม่ใช่เจ้าของ 403/404, เพื่อนร่วมห้อง 404, บัญชี disabled 403 `account_not_active`, admin ไม่มีสิทธิ์ API) เพิ่ม route ใหม่โดยไม่เพิ่มแถว test จะแดง |
| Role + ability | middleware `role:teacher` / `role:student` ตรวจทั้ง role ของบัญชีและ ability ของ token; policy ตรวจซ้ำที่ระดับแถว |
| Rate limit | `/auth/teacher/*` (สมัคร + login รวมกัน) 10 ครั้ง/นาที/IP; `/auth/student/*` 120/นาที/IP + PIN 10/นาที/บัญชี; API ทั่วไป 120/นาที/ผู้ใช้; endpoint ที่เรียก Google 30/นาที/ครู; `/google/oauth/callback` (ไม่ต้อง login) 20/นาที/IP; ต่อผู้ใช้แยก bucket กันต่อ endpoint: บันทึก Gemini key 10, สร้างคำอธิบายใหม่ 20, สร้างแบบฝึกด้วย AI 10, ขอตรวจใหม่ 30, ส่งคำตอบแบบฝึก 60, อ่าน/ร่างเฉลย 10, อ่านเอกสารรายวิชา 10, เสนอตัวชี้วัด 10, วิเคราะห์ตอนนี้ 10, อัปโหลดเอกสาร 20, ครูอัปโหลดงานรูปทั้งหน้า 60, นักเรียนส่งงาน 10 ครั้ง/นาที (ใช้ endpoint หนึ่งจนเต็มไม่ทำให้อีก endpoint โดนด้วย) ตอบ `429 too_many_requests` + `Retry-After` |
| PIN / QR | PIN ผิด 5 ครั้งล็อกบัญชี 15 นาที (นับที่บัญชี ไม่ใช่ IP); QR token 256 บิต เก็บเป็น hash; ออกบัตรใหม่/รีเซ็ต PIN ยกเลิก token เดิมทั้งหมด; QR บนใบงานมีลายเซ็น HMAC ปลอมไม่ได้ |
| Token | Sanctum: ครู 30 วัน นักเรียน 180 วัน หมดอายุ/ถูกลบ/ปลอม → 401 ในรูปแบบเดียวกัน |
| Security headers | ทุก response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`; API เพิ่ม `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`, CSP `default-src 'none'` สำหรับ JSON; `Strict-Transport-Security` เฉพาะ HTTPS |
| Input | id ที่ไม่ใช่ตัวเลข 1–18 หลักเป็น 404 (ไม่ถึง controller); mass assignment ถูกกัน (`school_id`, `teacher_id`, `role`, `status`, คอลัมน์ AI); body ผิดรูปเป็น 422 ใน envelope ไม่มี 500 |
| ความเป็นส่วนตัวของ Gemini | payload ที่ส่งมีเฉพาะภาพ crop ของช่องคำตอบ, โจทย์, เฉลย/rubric ของครู ไม่มีชื่อ, เลขที่, ห้อง, โรงเรียน, ภาพหน้าเต็ม หรือ key (test render body จริงของ `HttpGeminiClient` แล้วค้นหา) |
| Prompt injection | `tests/fixtures/injection/*.png` ข้อที่มีคำสั่งแทรก → `suspicious_instruction` → บนสุดของคิวครู, ตัดสิทธิ์อนุมัติแบบกลุ่ม, ไม่สร้างคำอธิบายอัตโนมัติ (DESIGN §10.7) |
| Key ของครู | เข้ารหัสด้วย `APP_KEY` แสดง 4 ตัวท้าย ไม่ออกทาง response/log/`ai_calls` แม้ตอน Google ปฏิเสธ key |
| config cache | ไม่มี `env()` นอก `config/` (test สแกน) จึงปลอดภัยกับ `optimize` |
| CI | gitleaks สแกนทั้งประวัติ git ทุก push ด้วยกฎ default + `google-aq-api-key` จาก `.gitleaks.toml` (key แบบ `AQ.` ที่กฎ default จับไม่ได้); pre-commit hook ในเครื่องกัน Google API key (`AIza...` และ `AQ....`) แต่ไม่ติดไปกับ clone ใหม่หรือการแก้ผ่านเว็บ GitHub |

### สิ่งที่ต้องทำเองบน Plesk

- `APP_DEBUG=false` และ `display_errors` off เสมอ ถ้าต้องเปิดชั่วคราวเพื่อไล่ error ให้ปิดทันทีที่เสร็จ
- เปิด http→https redirect (§4.4) ไม่งั้นไม่มี HSTS และ cookie ของ `/admin` (`SESSION_SECURE_COOKIE=true`) จะไม่ทำงานทาง http
- **Backup**: Hostatom เก็บ backup 21 วัน และให้ตั้ง Plesk **Backup Manager** รายสัปดาห์ไปที่เก็บภายนอก (เช่น Google Drive ของทีม) รวม database และ `backend/storage/app/private/` (DESIGN §7.3) ทดลอง restore หนึ่งครั้งก่อนเปิดใช้จริง
- **Log**: `backend/storage/logs/laravel-*.log` (เก็บ 14 วันตาม `LOG_DAILY_DAYS`) และ Plesk > Logs (Apache/PHP) ใน log ไม่มี key, token หรือภาพ แต่มี id ของนักเรียน ห้ามแชร์ทั้งไฟล์ออกนอกทีม
- **บัญชี admin**: ใช้รหัสผ่านยาวและไม่ซ้ำ, ไม่แชร์บัญชี, เพิ่ม admin คนอื่นใน `/admin` > Users แทนการใช้ `ADMIN_*` ซ้ำ
- **Plesk** เอง: เปิด 2FA ของบัญชี Plesk/Hostatom, จำกัดผู้รู้รหัส
- **การหมุน key**: `QR_SIGNING_KEY` ห้ามหมุน; `APP_KEY` หมุนได้เฉพาะเมื่อจำเป็นและใช้ `APP_PREVIOUS_KEYS` (§4.6); Gemini key กลาง/OAuth secret หมุนได้อิสระ (แก้ `.env` + `optimize`)
- **PDPA**: ก่อน pilot กับนักเรียนจริงต้องมีใบยินยอมของผู้ปกครองและตั้ง `allow_training_data` ของโรงเรียน (DESIGN §16.2) ภาพลายมือถูกลบตามนโยบาย §7.3 โดย task `eduvision-purge-images` ดูให้ task นี้รันอยู่จริง (`storage/logs` มี `purge.files` ทุกวัน)

---

## 10. Cloudflare (ไม่บังคับ ทำภายหลัง)

DESIGN §7.6: ต้องย้าย nameserver ของ `phuwish.com` ทั้งโดเมนไป Cloudflare (รวม MX ของ `mail.phuwish.com`) จึงเลื่อนไว้ ถ้าทำ

- เปิด proxy ของ record `teacherhelper`, SSL = **Full (strict)** (origin มี Let's Encrypt), cache bypass `/api/*`, rate limit เพิ่มบน `/api/v1/auth/*`
- **ต้องแก้โค้ดก่อนเปิด proxy**: เพิ่ม `$middleware->trustProxies(at: [...ช่วง IP ของ Cloudflare...], headers: ...)` ใน `backend/bootstrap/app.php` มิฉะนั้น Laravel เห็นทุก request มาจาก IP ของ Cloudflare → rate limit ต่อ IP จะล็อกครูทั้งโรงเรียนพร้อมกัน และ `isSecure()` อาจเป็น false → ไม่มี HSTS ทำเป็น issue แยกพร้อม test
- ตั้ง "Always Use HTTPS" ที่ Cloudflare ด้วย และปิด "Rocket Loader"/"Auto Minify" สำหรับ `/admin` (Livewire)

---

## 11. Troubleshooting

| อาการ | สาเหตุที่พบบ่อย | แก้ |
|---|---|---|
| additional deploy actions ฟ้อง `php: not found` | chroot ของ Git extension ไม่มี PHP | ไม่ใช้ deploy actions ใช้ Scheduled Task และ Composer extension (§4.3, §4.7) |
| Composer: `Allowed memory size ... exhausted` หรือหมดเวลา | memory_limit ของ PHP ที่ extension ใช้ | `COMPOSER_MEMORY_LIMIT=-1` ในหน้า Composer ถ้าไม่มีช่อง ใช้ `vendor.zip` (§4.3) |
| Composer: `lock file does not contain a compatible set of packages` / platform check ล้ม | `composer.lock` resolve ด้วย PHP 8.5 ของเครื่อง (ไม่มี `config.platform.php`) | ในเครื่อง `composer config platform.php 8.3.0` (หรือเวอร์ชันจริงของ hosting) แล้ว `composer update` เต็ม commit lock ใหม่ |
| Composer: "No applications were found" | docroot ยังไม่ชี้ `backend/` ตอน Scan | §4.3 ข้อ 1 |
| `open_basedir restriction in effect` ใน log | open_basedir ครอบแค่ docroot | PHP Settings > `open_basedir` = `{WEBSPACEROOT}{/}{:}{TMP}{/}` ถ้าแก้เองไม่ได้ เปิด ticket Hostatom |
| ทุก route 404 ยกเว้น `/` | handler เป็น nginx-only ไม่อ่าน `.htaccess` | เปลี่ยน handler ให้ผ่าน Apache (§4.1) หรือขอ Hostatom ใส่ `location / { try_files $uri $uri/ /index.php?$query_string; }` |
| 500 หน้าเปล่า ไม่มี `storage/logs/laravel-*.log` | `storage/` เขียนไม่ได้ หรือ `APP_KEY` ว่าง/ผิดรูป | ดู Plesk > Logs; permission ตาม §3; `APP_KEY` ต้องขึ้นต้น `base64:` |
| `/admin/login` ขึ้นแต่ไม่มี CSS | asset ของ Filament ไม่ถึง server | Run Now `filament:assets` แล้วดูว่ามี `public/js/filament/` |
| `/admin` login วนกลับหน้า login | cookie ไม่ถูกส่ง (http หรือ `SESSION_DOMAIN` ผิด) | ใช้ https, `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=true` แล้ว `optimize:clear`/`optimize` |
| `queue_last_run_at` เป็น null ตลอด / `status: degraded` | task ชี้ path ผิด, PHP คนละเวอร์ชัน, `jobs` ค้าง, หรือเพิ่ง `optimize:clear` | Run Now ที่ `eduvision-queue-worker` อ่าน output; `queue:failed` ใน maintenance; รอ 1–2 นาทีหลัง clear |
| งานตรวจไม่ขยับ, `failed_jobs` โต | Gemini key ไม่มี/ถูกปฏิเสธ, hosting เรียก Google ไม่ได้, job เกิน 240 วินาที | `eduvision:gemini-check --generate`; ดู `ai_calls` ใน `/admin`; ข้อที่ล้มครบ 3 ครั้งเป็น `manual` ให้ครูตรวจเองอยู่แล้ว |
| แก้ `.env` แล้วค่าไม่เปลี่ยน | config cache จาก `optimize` | Run Now `optimize:clear` + `filament:optimize-clear` แล้ว `optimize` + `filament:optimize` |
| Deploy แล้ว `.env`/`vendor` หาย | Plesk ล้าง deployment path | ย้าย `.env` ไป `<home>/eduvision-private/` แล้วบันทึกวิธี (§3) |
| POST /scans ตอบ `503 too_many_files` | `max_file_uploads` ต่ำกว่า 1 + 2 × จำนวนข้อ | PHP Settings ตาม §4.1 (แอปจะลองส่งใหม่เอง) |
| POST /scans หรือการส่งงานตอบ `413 file_too_large` หรือ Plesk ตอบ 413 เอง | `post_max_size`/`upload_max_filesize` ต่ำ หรือ nginx `client_max_body_size` | PHP Settings §4.1; ถ้าเป็นหน้า error ของ nginx ขอ Hostatom เพิ่ม `client_max_body_size 64m` (ต้องไม่น้อยกว่า `post_max_size` 55M) |
| ครูโดน `429 too_many_requests` ตอน login | ผิดรหัสเกิน 10 ครั้ง/นาทีจาก IP เดียว (ทั้งโรงเรียนใช้ NAT เดียว) | รอตาม `Retry-After` (≤ 60 วินาที) ไม่ใช่การล็อกบัญชี |
| นักเรียน `423 pin_locked` | ผิด PIN 5 ครั้ง | รอ 15 นาที หรือครูรีเซ็ต PIN จากแอป (สแกนบัตร QR ยังใช้ได้) |
| ไม่มี `Strict-Transport-Security` ใน response ทั้งที่เรียกทาง https | PHP เห็น request เป็น http (proxy ภายในของ Plesk ไม่ส่ง `HTTPS=on`) | ⚠️ ต้องตรวจสอบ: ถ้าเกิดจริง เพิ่ม `$middleware->trustProxies(at: ['127.0.0.1'])` ใน `bootstrap/app.php` พร้อม test แล้ว deploy |
| อัปโหลดโมเดลใน `/admin` ล้ม | เกิน `upload_max_filesize` | ใช้ `eduvision:register-model` (§7) |
| อีเมลจาก Scheduled Task ทุกนาที | Notify ตั้งเป็นทุกครั้ง หรือ task ล้มจริง | ตั้ง Notify = Errors only; ถ้าล้ม อ่าน output |
| ส่งงานรูปทั้งหน้า/เอกสารแล้วได้ `413` หรือ `422 file_too_large` ทั้งที่ไฟล์ไม่ถึง 10 MB | `upload_max_filesize` < 10M หรือ `post_max_size` < 55M | PHP Settings ตาม §4.1 |
| งานจาก Classroom ไม่เข้าแอป / "ซิงก์ล่าสุด" ไม่ขยับ | ครูต้องเชื่อม Google ใหม่ (ดู `/teacher/attention` → `needs_reconnect`), cron ไม่รัน, หรือ log มี `queue_work.dispatch_failed` step `classroom_sync` | ให้ครูกด "เชื่อมใหม่"; ตรวจ `eduvision-queue-worker` ตามแถว `queue_last_run_at` ข้างบน; รอบซิงก์ห่างกัน 5 นาที |
| ประกาศผลใน Classroom "ส่งไม่สำเร็จ" | ครูยังไม่ได้ให้ scope `classroom.announcements` หรือนักเรียนยังไม่ได้จับคู่บัญชี Google | เชื่อมใหม่ (§6.3) แล้วกด "ส่งประกาศอีกครั้ง" ในหน้าประกาศผลรายคน |
| แอปไม่มีปุ่ม "เข้าสู่ระบบด้วย Google" | `GOOGLE_SIGNIN_CLIENT_IDS` ว่าง (`/auth/google/config` ตอบ `enabled: false`), APK ไม่ได้ build ด้วย `GOOGLE_SIGNIN_CLIENT_ID` หรือบนเว็บ `web_flow: false` | §6.4 ข้อ 6–7 แล้ว `optimize:clear`/`optimize` |
| กด Google บน Android แล้วได้ `DEVELOPER_ERROR` / `[16]` / ไม่มีอะไรเกิดขึ้น | Android client ไม่มี SHA-1 ของ keystore ที่เซ็น APK นี้ หรือ package ไม่ใช่ `com.eduvision.app` หรือ `GOOGLE_SIGNIN_CLIENT_ID` เป็น client ID แบบ Android แทนแบบ Web | §6.4 ข้อ 5 และ 7 (debug กับ release ใช้ SHA-1 คนละตัว) |
| `422 google_token_invalid` ทุกครั้ง | `GOOGLE_SIGNIN_CLIENT_ID` ของแอปไม่อยู่ใน `GOOGLE_SIGNIN_CLIENT_IDS` (aud ไม่ตรง) หรือนาฬิกา server คลาดเกิน 60 วินาที | ใส่ client ID แบบ Web ตัวเดียวกันทั้งสองที่ log มีเหตุผลใน `google_signin.verify` (`aud`, `age`, ...) |
| `503 google_unavailable` | hosting เรียก `www.googleapis.com/oauth2/v3/certs` ไม่ได้ | ตรวจ outbound (§6.4 ข้อ 9) |
| `403 google_domain_not_allowed` / `student_google_disabled` | อีเมลไม่อยู่ในโดเมนที่อนุญาต / สวิตช์นักเรียนของโรงเรียนปิด | §6.4 ข้อ 8 |
| หน้า Google ทางเว็บขึ้น `Error 400: redirect_uri_mismatch` | URI ใน §6.4 ข้อ 4 ไม่ตรง `GOOGLE_SIGNIN_REDIRECT_URI` (หรือ `APP_URL` + `/auth/google/callback`) | แก้ให้ตรงทุกตัวอักษร |
| `Error 403: access_denied` ตอนเลือกบัญชี Google | consent screen ของ project sign-in ยังเป็นโหมด Testing | §6.4 ข้อ 2: Publish app |
| ทั้งห้องกด Google พร้อมกันแล้วบางคนได้ `429` | limiter `google-signin` 10 ครั้ง/นาที ต่อ IP (ทั้งโรงเรียนใช้ NAT เดียว) | รอตาม `Retry-After` หรือใช้ PIN/บัตร QR ไปก่อน (DESIGN §24.22 ข้อที่ต้องทบทวน) |
| "วิเคราะห์ตอนนี้" ได้ 500/504 | `max_execution_time` < 90 | PHP Settings ตาม §4.1 |
| ไม่มีการวิเคราะห์รอบกลางคืน | ห้องไม่มี Gemini key ใดเลย (ครูเจ้าของห้องและ key กลาง) หรือ worker ไม่รันหลัง 01:00 | ใส่ key; ดู `/admin` > AI calls (`feature = analysis_nightly`) |

---

## 12. บันทึกสิ่งที่ยืนยันกับของจริงแล้ว

กรอกเมื่อทำจริง (ห้ามใส่รหัสผ่าน/key/ชื่อ database) และแก้ข้อ ⚠️ ด้านบนให้ตรง

| รายการ | ค่าที่พบ | วันที่ |
|---|---|---|
| ผล hosting probe: PHP เวอร์ชัน + extension ที่ขาด | | |
| ผล probe: outbound ไป Gemini / FCM / oauth2 | | |
| ผล probe: ช่วงห่างของ cron จริง และเวลาค้างสูงสุด (≥ 55 s?) | | |
| เวอร์ชัน MariaDB (และ tag ของ container ในเครื่องที่ปรับให้ตรง `X.Y`) | | |
| PHP version + handler ที่เลือกใน §4.1 | | |
| path เต็มของ home และ deployment path | (ใส่เฉพาะรูปแบบ เช่น `/var/www/vhosts/<โดเมน>/eduvision`) | |
| docroot ของ subdomain ชี้นอกโฟลเดอร์เดิมได้หรือไม่ (§4.3 ข้อ 3) | | |
| Composer extension สแกนเจอและติดตั้งได้หรือไม่ / ใช้ vendor.zip | | |
| Deploy ซ้ำแล้ว `.env`/`vendor`/`storage` ยังอยู่หรือไม่ (§3) | | |
| รูปแบบ path/arguments ของ Scheduled Task ที่ใช้ได้จริง | | |
| timezone ของ cron (task 02:00 รันเวลาไทยจริงไหม) | | |
| มี `Strict-Transport-Security` ใน response หรือไม่ | | |
| เวลาสร้าง PDF ใบงาน 40 คนบน hosting (DESIGN §16.2) | | |
| throughput ตรวจ 1 หน้า (วินาที) และห้อง 40 คน (นาที) (DESIGN §7.2) | | |
| migration รอบบัญชีนักเรียน (§5 ข้อ 3) รันผ่านบน MariaDB ของ hosting | | |
| Google sign-in: Android client ย้ายจาก project Classroom ได้หรือต้องลบก่อน (§6.4 ข้อ 5) | | |
| Google sign-in: `sub` ของ ID token = `userId` ของ Classroom (§6.4 ข้อ 9) | | |
| เว็บแอป: `/app/` ส่ง `Content-Security-Policy` จาก `app/.htaccess` หรือไม่ (§4.9 ข้อ 3) | | |
| เว็บแอป: `backend/public/app/` ยังอยู่หลัง Deploy ของ Git รอบถัดไปหรือไม่ (§4.9) | | |
