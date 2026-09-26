# EduVision: คู่มือ deploy backend บน Plesk (Hostatom shared hosting)

- **ฉบับ:** 27 ก.ย. 2569 (B7) เขียนจาก DESIGN §3.3, §7.2–§7.6 และ KICKOFF ส่วนที่ 3 (B7–B9) กับส่วนที่ 6 (Google Classroom)
- **สถานะ:** ยัง**ไม่เคย deploy จริง** ทุกข้อที่มี `⚠️ ต้องตรวจสอบ` คือสิ่งที่ยังไม่ได้ยืนยันกับหน้าจอ Plesk ของ Hostatom เมื่อทำจริงแล้วให้แก้เอกสารนี้ (และ DESIGN §7.2/§7.6 ถ้าค่าต่างจากที่เขียนไว้) ใน commit เดียวกัน ตาม KICKOFF Day 3 ข้อ 9
- **กติกา:** ไฟล์นี้อยู่ใน repo สาธารณะ **ห้ามใส่รหัสผ่าน, key, token, ชื่อ database จริง หรือ path ที่บอกโครงสร้าง home ของ subscription เกินจำเป็น** ค่าจริงเก็บใน password manager ของทีม
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
| งานเบื้องหลัง | Plesk **Scheduled Task** 3 ตัว: worker ทุก 1 นาที (`eduvision:queue-work`), ลบไฟล์ตามนโยบายทุกวัน 02:00 (`eduvision:purge-images`) และ task "maintenance" ที่ใช้กด Run Now (migrate, optimize, ตรวจ key ฯลฯ) ไม่ใช้ `schedule:run` เพราะต้องใช้ `proc_open` |
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
| `max_execution_time` | ≥ 120 | POST /scans ที่มีไฟล์หลายสิบไฟล์ และหน้า Filament import CSV |
| `upload_max_filesize` | ≥ 8M | หน้าเต็มของสแกน (`SCAN_MAX_PAGE_KB`), และการอัปโหลดโมเดลผ่าน Filament (§7) |
| `post_max_size` | ≥ 16M | หน้า + crop ทุกข้อในหนึ่ง request |
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

# ---- Gemini (§6.1) ----
GEMINI_API_KEY=                       # key กลาง ไม่บังคับ: ครูใส่ key ของตัวเองในแอปได้
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FAKE=false                     # ห้าม true บน production (ถูกเพิกเฉยพร้อม error ใน log)
GEMINI_TIMEOUT=30
GEMINI_CONCURRENCY=8
GEMINI_THINKING_LEVEL=low
GEMINI_SEND_TEMPERATURE=false

# ---- Firebase Cloud Messaging (§6.2) ----
FIREBASE_CREDENTIALS=                 # path เต็มของไฟล์ service account นอก docroot ว่าง = push ลง log เท่านั้น
FIREBASE_PROJECT_ID=
FIREBASE_TIMEOUT=10

# ---- Google Classroom (§6.3) ----
GOOGLE_OAUTH_CLIENT_ID=               # ว่างทั้งคู่ = endpoint ของ Classroom ตอบ 503 google_not_configured
GOOGLE_OAUTH_CLIENT_SECRET=
GOOGLE_OAUTH_REDIRECT_URI=
GOOGLE_TIMEOUT=20
GOOGLE_CLASSROOM_APP_LINK=
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
| `eduvision-queue-worker` | `eduvision/backend/artisan` | `eduvision:queue-work` | `* * * * *` | dispatch heartbeat แล้วรัน `queue:work --queue=grading,default,pdf --stop-when-empty --max-time=50` หนึ่งรอบ (DESIGN §7.2) ถ้าคิวว่างจบทันที เพิ่ม `--memory=256` ได้เฉพาะเมื่อ `memory_limit` ของ PHP ≥ 512M |
| `eduvision-purge-images` | `eduvision/backend/artisan` | `eduvision:purge-images` | `0 2 * * *` | ลบไฟล์ตามนโยบาย DESIGN §7.3: PDF ใบงานเกิน 30 วัน, ภาพหน้าเต็มของ submission ที่เผยแพร่แล้ว, crop หลัง `crop_retention_until`, สแกนซ้ำที่ไม่ยืนยันเกิน 30 วัน ⚠️ ต้องตรวจสอบ: เวลาของ cron เป็น timezone ของ server (คาดว่า Asia/Bangkok) |
| `eduvision-maintenance` | `eduvision/backend/artisan` | เปลี่ยนตามงาน (ตารางถัดไป) | `0 4 1 1 *` (ไกลๆ) | ใช้ปุ่ม **Run Now** เท่านั้น |

⚠️ ต้องตรวจสอบ: ชื่อช่อง arguments และรูปแบบ path (สัมพัทธ์กับ home หรือ path เต็ม) ในหน้า Add Task ของ Hostatom; Run Now บอกทันทีถ้า path ผิด

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
| ตรวจ FCM | `eduvision:fcm-check` | §6.2 |
| ลงทะเบียนโมเดล | `eduvision:register-model <path เต็มของโฟลเดอร์โมเดล>` | §7 |
| import ตัวชี้วัดจาก CSV | `eduvision:import-skills <path เต็มของไฟล์ csv>` | §8 (หรือใช้หน้า Filament) |
| export ข้อมูลสำหรับ notebook | `eduvision:export-observations --output=<path เต็ม>` | ดู `php artisan eduvision:export-observations --help` |
| ดูสถานะ migration / คิวที่ล้ม | `migrate:status` / `queue:failed` | อ่านอย่างเดียว |

ลำดับครั้งแรก

1. Run Now `migrate --force` → output ต้องขึ้น `DONE` ครบทุกไฟล์ใน `database/migrations/` (38 ไฟล์ ณ ฉบับนี้) phpMyAdmin เห็น table `schools`, `users`, `personal_access_tokens`, `jobs`, `cache`, `sessions`, `ai_calls`, `model_versions` ฯลฯ
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

# 6. สมัครครูด้วยรหัสโรงเรียน (หลังรอ 1 นาทีให้ throttle หมด)
curl -s "${H[@]}" -X POST $B/api/v1/auth/teacher/register -d '{"school_code":"<SEED_TEACHER_JOIN_CODE>","name":"ครูทดสอบ","email":"t1@example.com","password":"secret1234"}'
#   201 และบัญชีเป็น pending → อนุมัติใน /admin > Users แล้ว login จากแอปได้
```

ผ่านครบ = server พร้อมให้แอปใช้ จด base URL `https://teacherhelper.phuwish.com` ให้ฝั่ง Flutter (`--dart-define=API_BASE_URL=...`, ดู `app/README.md`)

---

## 5. Deploy ครั้งถัดไป (อัปเดตโค้ด)

CI (`.github/workflows/backend.yml`) รัน test บน PHP 8.3 + MariaDB, pint และ gitleaks ทุก push ที่แตะ `backend/` **ดูให้เขียวก่อน** แล้วจึง

1. Websites & Domains > Git > **Pull Updates** → **Deploy** (ถ้าเปิด Automatic deployment ไว้ ขั้นนี้เกิดเองเมื่อ push)
2. ถ้า commit นั้นแก้ `composer.lock`: ทำ §4.3 ซ้ำ (สลับ docroot → Composer Install → สลับกลับ) หรืออัปโหลด `vendor.zip` ใหม่
3. `eduvision-maintenance` Run Now: `migrate --force` (ถ้ามี migration ใหม่; ดู output)
4. Run Now: `optimize:clear` → `optimize` → `filament:optimize` (ทุกครั้ง เพราะ route/config/view ถูก cache ไว้)
5. `curl -s https://teacherhelper.phuwish.com/api/v1/health | jq .` และเปิด `/admin` หนึ่งหน้า
6. ถ้าแอปเวอร์ชันใหม่ต้องการ API ใหม่ ให้ deploy server **ก่อน** แจก APK

ช่วงระหว่างข้อ 1–4 (ไม่กี่นาที) request อาจได้ 500 ถ้า migration ยังไม่รัน ทำนอกเวลาที่ครูใช้งาน ถ้าต้องการปิดชั่วคราว: Run Now `down --retry=60` ก่อนข้อ 1 และ `up` หลังข้อ 4 (API จะตอบ `503 service_unavailable` ระหว่างนั้น)

Rollback: Git > "Change branch and path" ชี้ commit/branch ก่อนหน้า → Deploy → ทำข้อ 2–4 (migration ไม่ย้อนกลับเอง ถ้า migration ใหม่ทำลายข้อมูล ให้กู้จาก backup แทน ดู §9)

---

## 6. บริการภายนอก (ไม่บังคับ)

ทุกอย่างในหมวดนี้ปิดได้: ระบบตรวจงานทำงานด้วย key ของครู, push ลง log, และ Classroom ตอบ `503 google_not_configured`

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
2. `.env`: `GOOGLE_OAUTH_CLIENT_ID=` และ `GOOGLE_OAUTH_CLIENT_SECRET=` ของ client แบบ Web (`GOOGLE_OAUTH_REDIRECT_URI` เว้นว่างจนกว่า Google จะตอบ `redirect_uri_mismatch`) → `optimize:clear`/`optimize`
3. client ID เดียวกันไปที่แอปเป็น `--dart-define=GOOGLE_SERVER_CLIENT_ID=...`
4. hosting ต้องเรียก `oauth2.googleapis.com`, `classroom.googleapis.com`, `www.googleapis.com` ออกไปได้ (probe ตรวจ `oauth2.googleapis.com` แล้ว)
5. ทดสอบจากแอป: ตั้งค่า > เชื่อม Google Classroom → `GET /api/v1/google/status` ตอบ `connected: true`
6. ถ้า secret หลุด: Credentials > เลือก Web client > **Reset secret** แล้วแก้ `.env`

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

- **โรงเรียน**: `/admin` > Schools สร้างโรงเรียนและดู/สร้าง `teacher_join_code` ใหม่ (รหัสนี้คือด่านเดียวของการสมัครครู แจกเฉพาะครูของโรงเรียนนั้น) ตั้ง `allow_training_data` และ `crop_retention_until` ต่อโรงเรียนที่นี่ (DESIGN §7.5)
- **ครู**: สมัครจากแอปด้วยรหัสโรงเรียน แล้ว admin อนุมัติใน `/admin` > Users (`pending` → `active`)
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
| Rate limit | `/auth/teacher/*` 10 ครั้ง/นาที/IP; `/auth/student/*` 120/นาที/IP + PIN 10/นาที/บัญชี; API ทั่วไป 120/นาที/ผู้ใช้; endpoint ที่เรียก Google 30/นาที/ครู; endpoint ที่เรียก Gemini ตรง 10–20/นาที ตอบ `429 too_many_requests` + `Retry-After` |
| PIN / QR | PIN ผิด 5 ครั้งล็อกบัญชี 15 นาที (นับที่บัญชี ไม่ใช่ IP); QR token 256 บิต เก็บเป็น hash; ออกบัตรใหม่/รีเซ็ต PIN ยกเลิก token เดิมทั้งหมด; QR บนใบงานมีลายเซ็น HMAC ปลอมไม่ได้ |
| Token | Sanctum: ครู 30 วัน นักเรียน 180 วัน หมดอายุ/ถูกลบ/ปลอม → 401 ในรูปแบบเดียวกัน |
| Security headers | ทุก response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`; API เพิ่ม `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`, CSP `default-src 'none'` สำหรับ JSON; `Strict-Transport-Security` เฉพาะ HTTPS |
| Input | id ที่ไม่ใช่ตัวเลข 1–18 หลักเป็น 404 (ไม่ถึง controller); mass assignment ถูกกัน (`school_id`, `teacher_id`, `role`, `status`, คอลัมน์ AI); body ผิดรูปเป็น 422 ใน envelope ไม่มี 500 |
| ความเป็นส่วนตัวของ Gemini | payload ที่ส่งมีเฉพาะภาพ crop ของช่องคำตอบ, โจทย์, เฉลย/rubric ของครู ไม่มีชื่อ, เลขที่, ห้อง, โรงเรียน, ภาพหน้าเต็ม หรือ key (test render body จริงของ `HttpGeminiClient` แล้วค้นหา) |
| Prompt injection | `tests/fixtures/injection/*.png` ข้อที่มีคำสั่งแทรก → `suspicious_instruction` → บนสุดของคิวครู, ตัดสิทธิ์อนุมัติแบบกลุ่ม, ไม่สร้างคำอธิบายอัตโนมัติ (DESIGN §10.7) |
| Key ของครู | เข้ารหัสด้วย `APP_KEY` แสดง 4 ตัวท้าย ไม่ออกทาง response/log/`ai_calls` แม้ตอน Google ปฏิเสธ key |
| config cache | ไม่มี `env()` นอก `config/` (test สแกน) จึงปลอดภัยกับ `optimize` |
| CI | gitleaks สแกนทั้งประวัติ git ทุก push; pre-commit hook ในเครื่องกัน Google API key (`AIza...`) |

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
| POST /scans ตอบ `413 payload_too_large` หรือ Plesk ตอบ 413 เอง | `post_max_size`/`upload_max_filesize` ต่ำ หรือ nginx `client_max_body_size` | PHP Settings §4.1; ถ้าเป็นหน้า error ของ nginx ขอ Hostatom เพิ่ม `client_max_body_size 32m` |
| ครูโดน `429 too_many_requests` ตอน login | ผิดรหัสเกิน 10 ครั้ง/นาทีจาก IP เดียว (ทั้งโรงเรียนใช้ NAT เดียว) | รอตาม `Retry-After` (≤ 60 วินาที) ไม่ใช่การล็อกบัญชี |
| นักเรียน `423 pin_locked` | ผิด PIN 5 ครั้ง | รอ 15 นาที หรือครูรีเซ็ต PIN จากแอป (สแกนบัตร QR ยังใช้ได้) |
| ไม่มี `Strict-Transport-Security` ใน response ทั้งที่เรียกทาง https | PHP เห็น request เป็น http (proxy ภายในของ Plesk ไม่ส่ง `HTTPS=on`) | ⚠️ ต้องตรวจสอบ: ถ้าเกิดจริง เพิ่ม `$middleware->trustProxies(at: ['127.0.0.1'])` ใน `bootstrap/app.php` พร้อม test แล้ว deploy |
| อัปโหลดโมเดลใน `/admin` ล้ม | เกิน `upload_max_filesize` | ใช้ `eduvision:register-model` (§7) |
| อีเมลจาก Scheduled Task ทุกนาที | Notify ตั้งเป็นทุกครั้ง หรือ task ล้มจริง | ตั้ง Notify = Errors only; ถ้าล้ม อ่าน output |

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
