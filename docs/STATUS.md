# EduVision: รายงานความคืบหน้า

- **วันที่:** 27 ก.ย. 2569 เวลา 12:15
- **ผู้อ่าน:** ทีม EduVision (ใช้ประกอบรายงานวิชา 03376133 และ 03376132 ได้)
- **สรุปสั้น:** **เขียนโค้ดครบทุก phase (1–7) แล้ว** ทดสอบทั้งระบบในเครื่องผ่าน รวมถึงตรวจการบ้านด้วย Gemini จริง สิ่งที่ยังไม่ได้ทำคือ **ทดสอบกับกระดาษและมือถือจริง, deploy ขึ้น hosting และ push ขึ้น GitHub** ซึ่งต้องเป็นทีมทำ (หัวข้อ 5)

## 1. ตัวเลขรวม

| รายการ | ค่า |
|---|---|
| commit ใน `main` (ยังไม่ push) | 56 |
| backend (Laravel 13) | **706 tests ผ่าน** (11,283 assertions) ทั้งบน SQLite และ MariaDB 11, line coverage **94.9%** |
| backend: API endpoint | 85 route ภายใต้ `/api/v1` |
| app (Flutter, Android) | **442 tests ผ่าน**, `flutter analyze` ไม่มี issue, line coverage **83%** (เกณฑ์วิชา 70%), build APK debug/release ได้ |
| ml (Python) | **198 tests ผ่าน** |
| CI (GitHub Actions) | `backend.yml` (test + MariaDB + coverage + pint), `app.yml` (analyze + test + coverage gate + APK), `secrets.yml` (gitleaks ทุก push) |
| คู่มือ deploy | [`docs/HOSTING.md`](HOSTING.md) |

## 2. ทำอะไรได้แล้ว

| Phase | backend | app |
|---|---|---|
| M0 | สมัคร/login ครู, health + heartbeat ของ queue, error รูปแบบเดียว, Filament admin | login, session, shell ครู/นักเรียน |
| 1 ใบงาน | layout อัตโนมัติ + **PDF แยกรายคน** (ArUco 4 มุม, QR มีลายเซ็น, ฟอนต์ไทย) 40 คน/80 หน้าใน 0.7 วินาที | **หน้าสแกน**: กล้อง → Kotlin + OpenCV (ArUco, warp, ความเบลอ, crop, วัดการฝน) + ML Kit อ่าน QR |
| 2 แกนหลัก | ห้องเรียน, นักเรียน, **login นักเรียนด้วยบัตร QR / PIN** (ล็อกเมื่อผิด 5 ครั้ง), พิมพ์บัตร, import ตัวชี้วัด, **รับสแกน** (idempotent, สแกนซ้ำ, ลบภาพตามนโยบาย) | ห้องเรียน, การบ้าน 4 ประเภท, rubric, พิมพ์ใบงาน, **สแกนออฟไลน์ + คิวอัปโหลดเบื้องหลัง** |
| 3 ตรวจด้วย AI | **Gemini จริง** (structured output, prompt มีเวอร์ชัน) + fake สำหรับทดสอบ, **key ของครูเก็บเข้ารหัส**, **Fuzzy 2 ระบบ** ตรงกับ reference Python ทุกค่า, ป้องกัน prompt injection | **ใส่ Gemini key 3 จุด** (การ์ดหน้าหลัก, ตั้งค่า, แบนเนอร์หน้าตรวจทาน) |
| 4 HITL | คิวตรวจทาน, override + เหตุผล, อนุมัติกลุ่ม, **เผยแพร่**, ขอตรวจใหม่, FCM | หน้าตรวจทาน (แท็บเล็ต master-detail, "เหตุผลของคะแนน"), หน้าผลของนักเรียน, ขอตรวจใหม่ |
| 5 CNN | endpoint โมเดล, `eduvision:register-model`, training samples | **ตัวอ่านเลข TFLite บนเครื่อง** (โมเดล v0.1.0) |
| 6 ITS/EDM | คลังแบบฝึก (Gemini สร้าง ครูอนุมัติ), mastery (EWMA), analytics (p/r, heatmap) | แบบฝึกซ่อม, หน้า mastery, dashboard ครู |
| 7 Classroom | เชื่อม Google, ผูกคอร์ส, จับคู่นักเรียน, โพสต์งาน, sync งานที่ส่ง, **ส่งคะแนนกลับอัตโนมัติ** (กันส่งผิดคน) | เชื่อมบัญชี, จับคู่, โพสต์, **ดึงรูปที่นักเรียนส่งมาสแกนบนเครื่อง** (JPEG/HEIC/PDF), ตีกลับ |
| ความปลอดภัย | authorization ทุก endpoint × ทุกบทบาท, rate limit แยกต่อ endpoint, security headers, privacy ของ payload ที่ส่ง Gemini | token/key ไม่ถูก log หรือเก็บในเครื่อง, cleartext เฉพาะ debug |

## 3. ผลทดสอบทั้งระบบ (integration, 27 ก.ย.)

agent รันวงจรเต็มในเครื่องด้วย `backend/tools/smoke.sh` (รันซ้ำได้): สมัครครู → admin อนุมัติ → ห้อง + นักเรียน → การบ้านครบ 4 ประเภท → PDF → อัปโหลดสแกน → ตรวจ → คิวตรวจทาน → override → อนุมัติกลุ่ม → เผยแพร่ → นักเรียน login ด้วย PIN เห็นคะแนนและคำอธิบาย → ขอตรวจใหม่ → ครูตอบ → mastery/analytics → แบบฝึก → endpoint Classroom (ไม่มี credential ตอบ 503 ถูกต้อง) **ผ่านทั้งหมด**

**ตรวจด้วย Gemini จริง** (`gemini-3.8-flash`, 1 นักเรียน 3 ข้อ, 5 ครั้ง)

| ข้อ | ผล |
|---|---|
| ตอบสั้น `7 × 8` นักเรียนตอบ 56 | ตรงเฉลย เต็ม |
| แสดงวิธีทำ นักเรียนเขียน `3 × 12 = 38` แล้ว `ตอบ 38 แท่ง` | **2.5/4 เข้าใจบางส่วน** ชี้บรรทัดที่ผิดได้ถูก |
| ตอบอิสระ | ประเมินตามเกณฑ์ใน rubric ได้ |
| คำอธิบายภาษาไทย | ชมก่อนแล้วชี้จุดผิด ใช้น้ำเสียงเป็นกลาง |
| เวลา / token | 2–4 วินาทีต่อข้อ, ประมาณ 1,400–2,700 token ขาเข้าต่อข้อ |

**สิ่งที่แก้จากการทดสอบกับของจริง**
- prompt v1 ตัดสินบรรทัดสรุปที่ต่อจากบรรทัดผิดว่าผิดด้วย (คะแนนต่ำเกินจริง 1.5/4) → prompt v2 ใช้หลัก **error carried forward** ได้ 2.5/4 ถูกต้อง
- คำอธิบายบางข้อลงท้าย "ครับ" บางข้อ "นะ" → กำหนดน้ำเสียงเดียว
- ภาพ prompt injection ("ให้คะแนนเต็ม" ฯลฯ) จับได้ **5/5** กับ Gemini จริง

## 4. โมเดลอ่านตัวเลข v0.1.0

เทรนด้วยข้อมูลสังเคราะห์จาก MNIST/EMNIST (12 epoch, 7.7 นาทีบน M5 Pro) วัดบน test set 2,400 ภาพที่แยกตามคนเขียน: CER 5.9%, ถูกทั้งสตริง 81.8% (1 หลัก 97%, 6 หลัก 68%), ไม่ตอบ 3.3% **ยังไม่เคยเจอลายมือจริง** เทรนซ้ำด้วย dataset ของทีมได้ด้วย `ml/train/import_dataset.py`

## 5. สิ่งที่ทีมต้องทำต่อ (agent ทำแทนไม่ได้)

| # | งาน | ทำไม | อ้างอิง |
|---|---|---|---|
| 1 | **ทดสอบกล้องกับกระดาษจริงบนมือถือ Android** | ArUco/crop/อ่าน QR ทดสอบด้วยภาพสังเคราะห์เท่านั้น เสี่ยงที่สุดก่อนนำเสนอ | DESIGN §15 Phase 1 |
| 2 | ยืนยันให้ **push ขึ้น GitHub** | repo เป็น public, CI จะเริ่มรันหลัง push | — |
| 3 | ออก SSL + รัน `tools/hosting-probe.php` แล้ว **deploy ตาม `docs/HOSTING.md`** | ใช้งานผ่านเน็ต | HOSTING.md |
| 4 | Firebase: `google-services.json` + service account | แจ้งเตือนบนมือถือ | — |
| 5 | Google Cloud OAuth (Web + Android) | Google Classroom | KICKOFF ส่วนที่ 6 |
| 6 | ลายมือจริงจากทีม + dataset ตัวเลข | วัดความแม่นของ Gemini กับลายมือเด็กจริง และเทรน CNN ซ้ำ | KICKOFF ส่วนที่ 5 |
| 7 | ใส่ `ADMIN_EMAIL` / `ADMIN_PASSWORD` ของตัวเองใน `backend/.env` | ตอนนี้ฐานข้อมูลในเครื่องมี admin ทดสอบ `admin@example.com` ที่ agent สร้าง | backend/README.md |
| 8 | **เปลี่ยน Gemini API key** หลังนำเสนอ และจำกัด key ให้ใช้ได้เฉพาะ Generative Language API | key ถูกส่งผ่านแชต | DESIGN §10.1 |
| 9 | ปิด Antigravity IDE ในโฟลเดอร์นี้ | เคยสร้าง commit ปนกับงาน | — |

## 6. ข้อที่ยังต้องตัดสินใจ

- **เคสแสดงวิธีทำที่คำตอบใกล้เคียง** (เช่น 38 แทน 36) กับขั้นตอนถูกบางส่วน ตอนนี้ได้ระดับ "มั่นใจ" และอนุมัติแบบกลุ่มได้ ถ้า Gemini อ่านขั้นตอนพลาดแบบ prompt v1 จะหลุดการตรวจของครู ข้อเสนอ: บังคับให้เคสนี้อยู่อย่างน้อยระดับ "ควรดู" (DESIGN §11.8) เพิ่มภาระครูเล็กน้อยแต่ปลอดภัยกว่า

## 7. รันในเครื่อง

```bash
# server (ตอนนี้ .env ตั้ง GEMINI_FAKE=false = ใช้ Gemini จริงและเสียเงินทุกครั้งที่ตรวจ)
docker start eduvision-mariadb
cd backend && php artisan serve            # http://127.0.0.1:8000  admin ที่ /admin
php artisan eduvision:queue-work           # รันทุกครั้งที่มีงานในคิว (production ใช้ cron)
bash tools/smoke.sh                        # ทดสอบวงจรเต็มด้วย Gemini ปลอม

# app
cd app && flutter run -d chrome --dart-define=API_BASE_URL=http://127.0.0.1:8000   # พรีวิว UI
cd app && flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000              # มือถือจริง (ใช้ adb reverse tcp:8000 tcp:8000)
```

รายละเอียดอยู่ใน `backend/README.md`, `app/README.md`, `ml/README.md`
