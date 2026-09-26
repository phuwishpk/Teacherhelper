# EduVision: รายงานความคืบหน้า

- **วันที่:** 26 ก.ย. 2569 เวลา 15:55
- **ผู้อ่าน:** ทีม EduVision (ใช้ประกอบรายงานวิชา 03376133 และ 03376132 ได้)
- **สรุปสั้น:** โค้ดหลักของทุก phase (1–7) เขียนและทดสอบในเครื่องเสร็จแล้วประมาณ 85% เหลือ 3 ขั้นท้าย (แบบฝึก/analytics ฝั่ง server, security test + CI + คู่มือ deploy, coverage ของแอป) และการทดสอบทั้งระบบรวมกัน ยังไม่ได้ push ขึ้น GitHub และยังไม่ได้ deploy

## 1. ตัวเลขรวม

| รายการ | ค่า |
|---|---|
| commit ใน `main` (ยังไม่ push) | 38 |
| backend (Laravel 13): โค้ด / test | 22,000 / 11,000 บรรทัด PHP |
| backend: จำนวน test | **529 ผ่านทั้งหมด** (7,663 assertions) |
| backend: API endpoint ภายใต้ `/api/v1` | 70 |
| app (Flutter): โค้ด / test | 24,500 / 13,200 บรรทัด Dart + Kotlin 1,800 บรรทัด |
| app: จำนวน test | **399 ผ่านทั้งหมด** `flutter analyze` ไม่มี issue |
| ml (Python): โค้ด | 5,200 บรรทัด test ผ่านทั้งหมด |
| โมเดล CRNN `digit_crnn 0.1.0` | 440k parameters, TFLite 1.3 MB |

## 2. เสร็จแล้ว (ทดสอบผ่านและ commit แล้ว)

### backend (Laravel + Filament บน MariaDB)

| Phase | สิ่งที่ทำได้ |
|---|---|
| M0 | สมัคร/login ครู, health + heartbeat ของ queue, รูปแบบ error เดียวกันทั้ง API, Filament admin |
| 2 | โรงเรียน, ห้องเรียน, เพิ่มนักเรียนทีละหลายคน, **login นักเรียนด้วยบัตร QR หรือ PIN** (ผิด 5 ครั้งล็อก 15 นาที + rate limit), พิมพ์บัตร QR เป็น PDF, admin อนุมัติครู, import ตัวชี้วัดจาก CSV, วิชา |
| 1 | สร้างการบ้าน 4 ประเภท, rubric (Gemini ร่าง ครูอนุมัติ), **layout อัตโนมัติ + PDF ใบงานแยกรายคน** (ArUco 4 มุม, QR มีลายเซ็น HMAC, ฟอนต์ Sarabun) 40 คน 80 หน้าใน 0.7 วินาที, ลบ PDF หลัง 30 วัน |
| 2 | **รับสแกน** แบบ idempotent, ตรวจลายเซ็น QR, กติกาสแกนซ้ำ (superseded / pending_confirm), เก็บภาพ WebP, ตรวจปรนัยทันที, ลบภาพตามนโยบาย |
| 3 | **Gemini client จริง** (structured output, prompt แยกไฟล์มีเวอร์ชัน) + **fake** สำหรับทดสอบ, **key ของครู** เก็บเข้ารหัส (`/me/ai-key`), key กลาง fallback, ไม่มี key → ข้อเข้าคิว "ตรวจเอง" + requeue ได้, **FuzzyEngine ทั้ง 2 ระบบ** ให้ผลตรงกับ reference ใน Python ทุกค่า, `GradeScanJob` (ยิง Gemini พร้อมกัน 8 ข้อ, retry, บันทึก `ai_calls`), คำอธิบายจุดผิดรายข้อ |
| 4 | คิวตรวจทาน (เรียง manual → suspicious → priority), override พร้อมเหตุผล + `score_events`, อนุมัติแบบกลุ่ม, **เผยแพร่** (นักเรียนเห็นเฉพาะที่เผยแพร่), ขอตรวจใหม่ (ข้อละครั้ง), ผลของนักเรียน, **FCM** (เปิดใช้เมื่อมี service account) |
| 7 | **Google Classroom**: เชื่อมบัญชี Google ของครู (refresh token เข้ารหัส), ผูกห้องกับคอร์ส, จับคู่นักเรียน, โพสต์งาน + ใบงานสำรอง, sync งานที่ส่ง, ตีกลับ, **ส่งคะแนนกลับอัตโนมัติตอนเผยแพร่** (เรียก Google REST ตรง ไม่ใช้ SDK) |

### app (Flutter, Android)

| Phase | สิ่งที่ทำได้ |
|---|---|
| M0/2 | โหมดครูและโหมดนักเรียน, login ครู / นักเรียน (สแกนบัตร QR หรือ PIN), ห้องเรียน, เพิ่มนักเรียน, พิมพ์บัตร, การบ้าน 4 ประเภท, เลือกตัวชี้วัด, rubric, สั่งพิมพ์ใบงานแล้วเปิด/แชร์ PDF |
| 2 | **ฐานข้อมูลในเครื่อง (drift)**: cache layout + roster, "เตรียมสแกนออฟไลน์", **คิวอัปโหลด** ทำงานเบื้องหลังด้วย WorkManager, retry แบบ backoff, จัดการกรณีชนกัน |
| 1 | **หน้าสแกน**: กล้อง → Kotlin + OpenCV หา ArUco, warp, วัดความเบลอ, crop ตาม layout, วัดการฝนวงกลม + ML Kit อ่าน QR → แสดงชื่อนักเรียนและ crop ให้ยืนยัน → เข้าคิว |
| 3/4 | **ใส่ Gemini key 3 จุด** (การ์ดหน้าหลัก, หน้าตั้งค่า, แบนเนอร์หน้าตรวจทาน), หน้าตรวจทานแบบ master-detail บนแท็บเล็ต (ภาพ crop, สิ่งที่อ่านได้, "เหตุผลของคะแนน", แก้คะแนน + เหตุผล), อนุมัติกลุ่ม, เผยแพร่, ขอตรวจใหม่, หน้าผลของนักเรียน, FCM (เปิดเมื่อมี `google-services.json`) |
| 7 | Google Classroom: เชื่อมบัญชี, เลือกคอร์ส, จับคู่นักเรียน, โพสต์งาน, **ดึงรูปที่นักเรียนส่งมาสแกนบนเครื่อง** (รองรับ JPEG/HEIC/PDF), ตีกลับ |
| 5/6 | **ตัวอ่านตัวเลข TFLite บนเครื่อง** (ถอด CTC ตรงกับ reference), แบบฝึกซ่อม, หน้า mastery, dashboard ครู (ค่า p/r, heatmap ทักษะ × ข้อผิดพลาด, mastery ทั้งห้อง), จัดการคลังแบบฝึก |

### ml (Python)

- Fuzzy Logic reference ของ DESIGN §11 ทั้ง 2 ระบบ + กราฟ membership/surface (ใช้ในรายงานวิชา AI)
- ตัวสร้าง ArUco marker
- **pipeline เทรน CRNN + CTC ครบ**: สร้างข้อมูลสังเคราะห์จาก MNIST/EMNIST, เทรน, export TFLite float16, ประเมิน, นำเข้า dataset จริงในรูปแบบ `path,label,writer_key`
- notebook เปรียบเทียบ BKT กับ EWMA

**ผลโมเดล v0.1.0 (เทรนสังเคราะห์ 12 epoch, 7.7 นาที, test set แยกตามคนเขียน 2,400 ภาพ)**

| ตัวชี้วัด | ค่า |
|---|---|
| CER | 5.9% |
| exact match | 81.8% (1 หลัก 97%, 6 หลัก 68%) |
| อัตราไม่ตอบ (confidence < 0.8) | 3.3% |
| ความแม่นเมื่อตอบ | 82.9% |

ยังไม่เคยเจอลายมือจริง ตัวเลขนี้จะเปลี่ยนเมื่อได้ dataset ของทีม

## 3. กำลังทำ (agent รันอยู่)

| ขั้น | เนื้อหา |
|---|---|
| B6 | server: บันทึก mastery (EWMA) ตอนเผยแพร่, คลังแบบฝึก (Gemini สร้าง ครูอนุมัติ), ตรวจแบบฝึกทันที, analytics (p/r, heatmap), endpoint ของโมเดล, Filament อัปโหลดโมเดล |
| A5 | app: coverage ≥ 70%, integration test, CI ของแอป, build release |

## 4. เหลือ

| ขั้น | เนื้อหา |
|---|---|
| B7 | security test ทั้งระบบ (authorization ทุก endpoint, privacy ของข้อมูลที่ส่ง Gemini, ภาพ prompt injection), CI backend, **`docs/HOSTING.md` คู่มือ deploy Plesk** |
| Integration | รันทั้งระบบในเครื่อง (Gemini ปลอม) ตั้งแต่สมัครครูจนนักเรียนเห็นจุดผิด แล้วแก้สิ่งที่พัง |

## 5. งานที่ต้องเป็นคนทำ (agent ทำแทนไม่ได้)

| งาน | ทำไม | อ้างอิง |
|---|---|---|
| ปิด Antigravity IDE ในโฟลเดอร์นี้ | มันสร้าง commit ปนกับงานของ agent | — |
| ออก SSL + รัน `tools/hosting-probe.php` บน Plesk | ยืนยันว่า hosting รัน cron และเรียก Google ได้ ก่อน deploy | KICKOFF ส่วนที่ 1 Day 0 |
| Firebase: `google-services.json` + service account | FCM แจ้งเตือน | คู่มือที่ให้ไว้ในแชต |
| Google Cloud: OAuth client Web + Android | Google Classroom | KICKOFF ส่วนที่ 6 |
| Gemini key ใส่ในแอป (ครู) หรือ `.env` | ตรวจการบ้านจริง (ตอนนี้ใช้ fake) | DESIGN §10.1 |
| ทดสอบกล้องกับกระดาษจริงบนมือถือ Android | ทุกอย่างทดสอบด้วยรูปจำลอง ยังไม่เคยลองกระดาษจริง | DESIGN §15 Phase 1 |
| ลายมือจริงจากทีม + dataset สำหรับ CNN | วัดความแม่น Gemini และเทรนโมเดลซ้ำ | KICKOFF ส่วนที่ 5 งานที่ 2 |
| push ขึ้น GitHub + deploy | repo เป็น public จะถามก่อน | `docs/HOSTING.md` (กำลังเขียน) |

## 6. ข้อควรรู้ก่อนนำเสนอ

- **ยังไม่มีอะไรทดสอบกับของจริงเลย**: Gemini, Google Classroom, FCM ใช้ตัวปลอมทั้งหมดใน test; ArUco/กล้องทดสอบด้วยภาพสังเคราะห์; ต้องเผื่อเวลาแก้เมื่อเจอของจริง
- ข้อจำกัดของ Google Classroom API: ส่งคะแนนกลับได้เฉพาะงานที่โพสต์จากแอป, ไม่มี private comment, refresh token หมดอายุ 7 วันในโหมด Testing (DESIGN §18)
- โค้ดที่ agent เขียนมีปริมาณมาก ผู้นำเสนอควรอ่านโครงสร้างหลักก่อน: `backend/app/Domain/*` (Grading, Gemini, Scans, Worksheets, Google) และ `app/lib/features/*`
- commit ที่ชื่อ `feat: initialize…` / `feat: implement core platform features…` มาจาก IDE ตัวอื่นที่ auto-commit ไม่ใช่จากกระบวนการนี้

## 7. รันในเครื่องเพื่อดูของจริง

```bash
# server
docker start eduvision-mariadb
cd backend && php artisan migrate:fresh --seed && php artisan serve   # http://127.0.0.1:8000, admin ที่ /admin
php artisan eduvision:queue-work                                     # รันทุกครั้งที่มีงานในคิว (production ใช้ cron)

# app (พรีวิว UI ใน Chrome; กล้องต้องใช้มือถือจริง)
cd app && flutter run -d chrome --dart-define=API_BASE_URL=http://127.0.0.1:8000
```

รายละเอียดอยู่ใน `backend/README.md`, `app/README.md`, `ml/README.md`
