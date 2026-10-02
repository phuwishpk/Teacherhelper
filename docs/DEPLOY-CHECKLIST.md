# EduVision: รายการตรวจตอนขึ้นเว็บครั้งแรก

- **สำหรับ:** คนที่มีสิทธิ์เข้า Plesk ของ `teacherhelper.phuwish.com` ทำตามลำดับ 1–10 รายละเอียดและวิธีแก้ปัญหาอยู่ใน [HOSTING.md](HOSTING.md) (เลขหัวข้อในวงเล็บ)
- **ผลที่ได้:** backend ที่ `https://teacherhelper.phuwish.com/api/v1`, หน้าผู้ดูแลระบบที่ `/admin` และเว็บแอปของครูกับนักเรียนที่ `/app/` (DESIGN §25) แอป Android ทำภายหลัง
- **กติกา:** ไฟล์นี้อยู่ใน repo สาธารณะ ห้ามใส่รหัสผ่าน key หรือชื่อ database จริง ค่าจริงอยู่ในเครื่องของผู้พัฒนาเท่านั้น

## ไฟล์ที่ต้องเตรียมบน Mac

เก็บไว้นอก repo เช่น `~/eduvision-deploy/` (โฟลเดอร์นี้มีของลับ ห้าม commit ห้ามส่งในแชต)

| ไฟล์ | ใช้ทำอะไร | สร้างอย่างไร |
|---|---|---|
| `hosting-probe.php` | ตรวจ hosting ก่อน deploy | คัดลอก `tools/hosting-probe.php` แล้วตั้ง `PROBE_KEY` เป็นค่าสุ่ม (`openssl rand -hex 12`) |
| `env.production.txt` | เนื้อหาของ `.env` บน server | ชุดตัวแปรใน HOSTING §4.6 ใส่ `APP_KEY` และ `QR_SIGNING_KEY` ที่สร้างตาม HOSTING §2 ข้อ 3 |
| `vendor.zip` | ทางสำรองถ้า Composer ของ Plesk ติดตั้งไม่ได้ | คำสั่งใน HOSTING §4.3 "ทางสำรอง" |
| `eduvision-web.zip` | เว็บแอป | `tools/build-web.sh` (ได้ไฟล์ใน `~/eduvision-deploy/`) |

## ขั้นตอน

### 1. ตรวจ hosting (§2)

1. Plesk > Files > document root ของ `teacherhelper.phuwish.com` > Upload `hosting-probe.php`
2. เปิด `https://teacherhelper.phuwish.com/hosting-probe.php?key=<PROBE_KEY ที่ตั้งไว้>`
3. Scheduled Tasks > Add Task > Run a PHP script > ไฟล์ probe > ทุก 1 นาที รอ 5 นาทีแล้ว refresh
4. ต้องผ่านทุกข้อ: PHP ≥ 8.3, extension ครบ, เรียกออกไป Google ได้, cron ค้างได้ ≥ 55 วินาที จดผลลง HOSTING §12
5. ลบ `hosting-probe.php`, `probe-cron.log` และ task ของ probe

ถ้า PHP ของ server ไม่ใช่ 8.3 ให้แก้ `config.platform.php` ใน `backend/composer.json` ให้ตรงก่อนไปต่อ (CLAUDE.md "Hosting constraints")

### 2. ตั้งค่า PHP (§4.1)

PHP ≥ 8.3, handler ที่มี Apache, `memory_limit` ≥ 256M, `max_execution_time` ≥ 120, `upload_max_filesize` ≥ 10M, `post_max_size` ≥ 55M, `max_file_uploads` ≥ 100, `display_errors` off

### 3. ดึงโค้ด (§4.2)

Files: สร้างโฟลเดอร์ `eduvision` ที่ home > Git > Add Repository: `https://github.com/phuwishpk/Teacherhelper.git`, branch `main`, path `/eduvision`, โหมด Manual > Pull Updates > Deploy

### 4. ติดตั้ง vendor (§4.3)

- ทางหลัก: Document root = `eduvision/backend` ชั่วคราว > PHP Composer > Install > Document root = `eduvision/backend/public`
- ทางสำรอง: Upload `vendor.zip` ไปที่ `eduvision/backend/` แล้ว Extract > Document root = `eduvision/backend/public`

### 5. SSL (§4.4)

Let's Encrypt แล้วเปิด "Redirect from http to https"

### 6. Database (§4.5)

Add Database (จดชื่อ user และรหัสผ่านไว้ใน password manager) collation `utf8mb4_unicode_ci`

### 7. `.env` (§4.6)

Files > `eduvision/backend/` > Create File `.env` > วางเนื้อหาจาก `env.production.txt` > กรอก database (3 ค่า) และ admin คนแรก (2 ค่า)

ตรวจ: `curl -s -o /dev/null -w '%{http_code}\n' https://teacherhelper.phuwish.com/.env` ต้องไม่ใช่ 200

### 8. Scheduled Tasks และ migrate (§4.7)

1. เพิ่ม task แบบ Run a PHP script (script `eduvision/backend/artisan`)

   | ชื่อ | Arguments | ความถี่ |
   |---|---|---|
   | `eduvision-queue-worker` | `eduvision:queue-work` | `* * * * *` |
   | `eduvision-purge-images` | `eduvision:purge-images` | `0 2 * * *` |
   | `eduvision-maintenance` | เปลี่ยนตามงาน | `0 4 1 1 *` (ใช้ปุ่ม Run Now เท่านั้น) |

2. `eduvision-maintenance` > Run Now ทีละคำสั่ง ตามลำดับ: `migrate --force` → `db:seed --class=SchoolSeeder --force` → `db:seed --class=AdminSeeder --force` → `optimize` → `filament:optimize`
3. ลบ `ADMIN_EMAIL` และ `ADMIN_PASSWORD` ออกจาก `.env` แล้ว Run Now `optimize:clear` → `optimize`

### 9. ตรวจ backend (§4.8)

```bash
curl -s https://teacherhelper.phuwish.com/api/v1/health     # ต้องมี "db":"ok" และ queue_last_run_at ขยับทุกนาที
```

แล้วเปิด `https://teacherhelper.phuwish.com/admin` เข้าด้วย admin ที่ seed ไว้ และเปลี่ยนรหัสผ่านทันที

### 10. เว็บแอป (§4.9)

1. Files > `eduvision/backend/public/` > Upload `eduvision-web.zip` > Extract Files > ต้องได้ `public/app/index.html` และ `public/app/.htaccess` > ลบไฟล์ zip
2. ตรวจจาก Mac

   ```bash
   curl -sI https://teacherhelper.phuwish.com/ | grep -i '^location'                 # .../app/
   curl -sI https://teacherhelper.phuwish.com/app/ | grep -i 'content-security-policy'
   ```

3. เปิด `https://teacherhelper.phuwish.com/` ต้องเห็นหน้าเข้าสู่ระบบ สมัครครูในหน้าเว็บ อนุมัติใน `/admin` แล้ว login

## หลังขึ้นเว็บแล้ว

- จดสิ่งที่พบลง HOSTING §12 (เวอร์ชัน PHP และ MariaDB, รูปแบบ path ของ Scheduled Task, มี header ของเว็บแอปหรือไม่, `public/app` ยังอยู่หลัง Deploy รอบถัดไปหรือไม่)
- บริการเสริมทำทีหลังได้ (HOSTING §6): Gemini key กลาง, Google Classroom, เข้าสู่ระบบด้วย Google (`GOOGLE_SIGNIN_APP_URL=https://teacherhelper.phuwish.com/app`), Firebase
- ก่อนให้นักเรียนจริงใช้: ความยินยอมของผู้ปกครอง (PDPA), เปลี่ยน Gemini API key ที่เคยส่งผ่านแชต และบอกนักเรียนให้ออกจากระบบทุกครั้งบนเครื่องที่ใช้ร่วมกัน (DESIGN §25.3)

## ทุกครั้งที่อัปเดต (§5)

Git Pull Updates + Deploy → `migrate --force` → `optimize:clear` → `optimize` → `filament:optimize` → ถ้าโค้ดของ `app/` เปลี่ยน รัน `tools/build-web.sh` แล้วอัปโหลด zip ใหม่ (ตรวจด้วยว่า Deploy ไม่ได้ลบ `public/app`)
