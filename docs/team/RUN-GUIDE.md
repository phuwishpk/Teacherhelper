# วิธีรัน Krucheck บนเครื่องตัวเอง

คู่มือสำหรับเพื่อนในทีมที่จะเริ่มเขียนโค้ด ทำตามหัวข้อที่ตรงกับงานของตัวเอง

| งาน | ต้องลง |
|---|---|
| แอปอย่างเดียว (Flutter) | Git, Flutter |
| backend อย่างเดียว (Laravel) | Git, PHP 8.3 ขึ้นไป, Composer, Docker |
| ทั้งสองอย่าง | ทั้งหมดด้านบน |

---

## 0. เตรียมครั้งแรก (ทุกคน)

```bash
git clone https://github.com/phuwishpk/Teacherhelper.git
cd Teacherhelper
git config user.name  "ชื่อของคุณ"
git config user.email "อีเมลที่ผูกกับบัญชี GitHub ของคุณ"
```

ใช้อีเมลเดียวกับบัญชี GitHub ไม่อย่างนั้น commit จะไม่ขึ้นเป็นชื่อของคุณ

ติดตั้ง hook กัน key รั่ว (ทำครั้งเดียว) เพราะ hook ไม่ทำงานเองหลัง clone ให้รันในโฟลเดอร์ repo บน Windows ให้รันใน Git Bash:

```bash
cp docs/team/hooks/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

ก่อนเริ่มงานแต่ละครั้ง ให้อัปเดต main แล้วแตก branch ใหม่:

```bash
git checkout main && git pull
git checkout -b <ชื่อ branch ตามตารางใน 00-START-HERE.md>
```

---

## 1. แอป Flutter

### ลงโปรแกรม
- **Flutter (stable):** ลงตาม https://docs.flutter.dev/get-started/install แล้วรัน `flutter doctor` ให้ผ่านอย่างน้อยข้อ Flutter และ Chrome
- **Android:** ลง Android Studio และสร้างเครื่องจำลอง (AVD) ข้อนี้ไม่บังคับ เพราะทดสอบบน Chrome ได้

### ติดตั้งแพ็กเกจ
```bash
cd app
flutter pub get
```

### รันบน Chrome (ง่ายที่สุด)
```bash
flutter run -d chrome --web-port 5173 --dart-define=API_BASE_URL=http://127.0.0.1:8000
```
- ต้องใช้พอร์ต **5173** เพราะ Google Sign-in อนุญาตไว้แค่พอร์ตนี้
- `API_BASE_URL` คือที่อยู่ของ backend ถ้าไม่ได้รัน backend เอง ให้ขอที่อยู่ server ทดสอบจาก phuwishpk แล้วใส่แทน

### รันบน Android
```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000           # เครื่องจำลอง (10.0.2.2 คือเครื่องเรา)
flutter run --dart-define=API_BASE_URL=http://<IP ของคอมในวง Wi-Fi>:8000  # มือถือจริง
```
ถ้าใช้มือถือจริง ต้องรัน backend ด้วย `php artisan serve --host=0.0.0.0 --port=8000` และคอมกับมือถือต้องอยู่ Wi-Fi เดียวกัน

### ตรวจก่อน commit (ต้องผ่านทุกครั้ง)
```bash
flutter analyze
flutter test
```
ถ้าจะรัน test เฉพาะโฟลเดอร์: `flutter test test/live/`

---

## 2. Backend Laravel

### ลงโปรแกรม
- **PHP 8.3 ขึ้นไป** พร้อม extension `gd intl zip pdo_mysql bcmath exif`
  - macOS: `brew install php`
  - Windows: Laravel Herd (https://herd.laravel.com) มี PHP และ Composer ให้ในตัว
- **Composer:** https://getcomposer.org ถ้าใช้ Herd ไม่ต้องลงเพิ่ม
- **Docker:** Docker Desktop หรือ OrbStack (macOS) ใช้รันฐานข้อมูล MariaDB อย่างเดียว

> ห้ามใช้ MySQL ที่ลงผ่าน Homebrew เพราะ production ใช้ MariaDB

### เปิดฐานข้อมูล MariaDB (ครั้งแรกครั้งเดียว)
```bash
docker run -d --name eduvision-mariadb --restart unless-stopped \
  -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE=eduvision \
  -e MARIADB_USER=eduvision -e MARIADB_PASSWORD=eduvision \
  -p 3307:3306 -v eduvision-mariadb:/var/lib/mysql \
  mariadb:11
```
ฐานข้อมูลใช้พอร์ต 3307 ตรงกับ `.env.example` อยู่แล้ว ไม่ต้องแก้

ครั้งต่อไปถ้าปิดเครื่องไป ให้เปิดด้วย `docker start eduvision-mariadb`

### ตั้งค่าโปรเจกต์ (ครั้งแรกครั้งเดียว)
```bash
cd backend
composer install
cp -n .env.example .env
php artisan key:generate
```

แก้ไฟล์ `backend/.env`:
```
DEMO_TEACHER_EMAIL=teacher@local.test
DEMO_TEACHER_PASSWORD=secret1234
DEMO_STUDENT_PIN=123456
GEMINI_FAKE=true
```
- `DEMO_STUDENT_PIN` คือรหัสผ่านของนักเรียนตัวอย่าง ต้องเป็นตัวเลข 6 หลัก ถ้าไม่ใส่ค่า `DEMO_*` ครบ ระบบจะไม่สร้างครูและนักเรียนตัวอย่าง
- `GEMINI_FAKE=true` ใช้ AI ปลอม จึงตรวจการบ้านได้โดยไม่ต้องมี Gemini key
- `FIRESTORE_ENABLED` ให้เป็น `false` ไว้ เพราะ key ของ Firebase เป็นความลับ ไม่อยู่ใน repo
- ไม่ต้องตั้งค่า `ADMIN_*` งานของเพื่อนไม่ใช้บัญชีผู้ดูแล ตอน seed จะมีคำเตือนจาก `AdminSeeder` ซึ่งไม่เป็นปัญหา

สร้างตารางและข้อมูลตัวอย่าง:
```bash
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder
```

### รันเซิร์ฟเวอร์
เปิด 2 หน้าต่าง terminal:

```bash
php artisan serve                     # หน้าต่าง 1: http://127.0.0.1:8000
php artisan queue:work                # หน้าต่าง 2: คิวงาน (AI ตรวจ, แจ้งเตือน, ส่ง Classroom)
```

ถ้าไม่ได้เปิดคิว งานจะค้างสถานะ "กำลังตรวจ" ไปเรื่อย ๆ

### ที่อยู่ที่ใช้บ่อย
| ที่อยู่ | ใช้ทำอะไร | ล็อกอินด้วย |
|---|---|---|
| http://127.0.0.1:8000/api/v1/health | เช็กว่า backend กับฐานข้อมูลทำงาน | ไม่ต้อง |
| แอป (Chrome พอร์ต 5173) | ครู | `DEMO_TEACHER_EMAIL` / `DEMO_TEACHER_PASSWORD` |
| แอป (Chrome พอร์ต 5173) | ครูคนที่สอง (ไว้ลองกับครูร่วมสอน) | อีเมลครูต่อท้ายชื่อด้วย `+co` เช่น `teacher+co@local.test` / `DEMO_TEACHER_PASSWORD` |
| แอป (Chrome พอร์ต 5173) | นักเรียนในห้อง "ป.5/1 (ตัวอย่าง)" | รหัสผ่าน `DEMO_STUDENT_PIN` |

### ตรวจก่อน commit (ต้องผ่านทุกครั้ง)
```bash
php artisan test          # ใช้ SQLite ในหน่วยความจำ ไม่ต้องเปิด MariaDB
vendor/bin/pint           # จัดรูปแบบโค้ด (CI ตรวจ)
```
ถ้าจะรัน test เฉพาะไฟล์: `php artisan test --filter=LiveClassroomTest`

### ล้างข้อมูลแล้วเริ่มใหม่
```bash
php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder
```

---

## 3. Firestore ตอนพัฒนา

- **ใน test:** ไม่ต้องตั้งค่าอะไร ฝั่งแอปใช้ `fake_cloud_firestore` ส่วนฝั่ง backend ใช้ `Http::fake` ทุก test จึงรันได้โดยไม่ต่อ Firebase จริง
- **ลองกับ Firebase จริง:** ต้องมี service account กับค่า config ของ Firebase ซึ่งเป็นความลับ ให้ขอจาก phuwishpk และ**ห้าม commit** หรือลองบน server ทดสอบแทน
- **ถ้าแก้ `firebase/firestore.rules`:** แจ้ง phuwishpk หลัง merge ให้ไปกด Publish ใน Firebase console

---

## 4. ส่งงาน

```bash
git add <ไฟล์ที่แก้>
git commit -m "feat(app): add about screen route"
git push -u origin <ชื่อ branch ของงาน>
```
จากนั้นเปิด Pull Request บน GitHub โดยตั้ง base เป็น `main` แล้วรอ CI ผ่านครบ คนที่ merge คือ phuwishpk

**รูปแบบข้อความ commit:** `ชนิด(ส่วน): ทำอะไร` เขียนเป็นภาษาอังกฤษ
- ชนิด: `feat`, `fix`, `test`, `docs`, `refactor`
- ส่วน: `app`, `backend`, `ml`, `docs`, `tools`, `repo`

**ห้าม**
- commit ไฟล์ `.env`, key ของ Gemini หรือ Firebase, `google-services.json` และข้อมูลนักเรียนจริง (repo นี้เป็น public)
- `git commit --no-verify` (hook ที่มีอยู่ช่วยกัน key รั่ว)
- `composer update` (ใช้แค่ `composer install`)
- force push ขึ้น main
- ใช้รหัสผ่านอื่นนอกจาก `secret1234` ใน test ของ backend (ค่าอื่นจะโดน gitleaks ใน CI)

---

## 5. ปัญหาที่เจอบ่อย

| อาการ | วิธีแก้ |
|---|---|
| `SQLSTATE[HY000] [2002] Connection refused` | ฐานข้อมูลยังไม่เปิด: `docker start eduvision-mariadb` |
| แอปขึ้น "เชื่อมต่อเซิร์ฟเวอร์ไม่ได้" | เช็กว่า `php artisan serve` ยังรันอยู่ และ `API_BASE_URL` ถูก (เครื่องจำลองใช้ `10.0.2.2`) |
| งานค้าง "กำลังตรวจ" | ยังไม่ได้เปิด `php artisan queue:work` |
| ปุ่ม Google Sign-in ใช้ไม่ได้บนเว็บ | ต้องรันด้วย `--web-port 5173` |
| `composer install` บอกว่า PHP ไม่ตรง | ต้องใช้ PHP 8.3 ขึ้นไป เช็กด้วย `php -v` |
| test ใน CI ล้ม แต่ในเครื่องผ่าน | รัน `vendor/bin/pint` (backend) หรือ `flutter analyze` (แอป) แล้ว push ใหม่ |
