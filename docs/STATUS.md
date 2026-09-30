# EduVision: รายงานความคืบหน้า

- **วันที่:** 30 ก.ย. 2569 เวลา 09:30
- **ผู้อ่าน:** ทีม EduVision (ใช้ประกอบรายงานวิชา 03376133 และ 03376132 ได้)
- **สรุปสั้น:** **เขียนโค้ด Phase 8 (ซิงก์ Google Classroom + ตรวจจากรูปทั้งหน้า) และ Phase 9 (รายวิชา แผนการสอน ตัวชี้วัด กราฟ และ AI วิเคราะห์รายคน) ครบแล้ว** พร้อมสถาปัตยกรรมประหยัด token (DESIGN §21) test ทุกชุดผ่าน และทดสอบทั้งวงจรในเครื่องผ่าน (Google และ Gemini เป็นของปลอม) งานจาก branch `feat/phase-8-9` **squash-merge เข้า `main` แล้ว** (30 ก.ย.) สิ่งที่ต้องเป็นทีมทำอยู่ในหัวข้อ 6

## 1. ตัวเลขรวม

| รายการ | Phase 1–7 (27 ก.ย.) | ตอนนี้ (30 ก.ย.) |
|---|---|---|
| commit | 56 ใน `main` | `main` 62 (เพิ่มเอกสารออกแบบ Phase 8–9) + **57 บน `feat/phase-8-9`** (squash-merge เข้า `main` เป็น commit เดียว) |
| backend (Laravel 13): test | 706 tests (11,283 assertions) | **1,075 tests ผ่าน** (20,909 assertions) ทั้งบน SQLite และ MariaDB 11 |
| backend: line coverage | 94.9% | ยังไม่ได้วัดรอบนี้ (เครื่องนี้ไม่มี pcov/xdebug) CI (`backend.yml`) วัดให้หลัง push |
| backend: API endpoint | 85 route ภายใต้ `/api/v1` | **147 route** |
| backend: migration | 38 ไฟล์ | 50 ไฟล์ (รันกับ MariaDB ของเครื่อง dev แล้ว) |
| app (Flutter, Android) | 442 tests, coverage 83% | **686 tests ผ่าน**, `flutter analyze` ไม่มี issue, line coverage **88.3%** (เกณฑ์วิชา 70%, ไม่นับไฟล์ generate) |
| ml (Python) | 198 tests | **198 tests ผ่าน** (Phase 8–9 ไม่แตะ `ml/`) |
| ทดสอบทั้งวงจร (`backend/tools/smoke.sh`) | 32 ขั้น | **48 ขั้น ผ่าน** (หัวข้อ 3) |
| package ใหม่ของแอป | — | `file_picker`, `fl_chart` เท่านั้น backend ไม่เพิ่ม package |
| คู่มือ deploy | [`docs/HOSTING.md`](HOSTING.md) | ปรับแล้วสำหรับงานรอบของ cron, ค่า PHP, ตัวแปร `.env` ใหม่ และ scope ใหม่ของ Google |

## 2. ทำอะไรได้แล้ว

### Phase 1–7 (เสร็จ 27 ก.ย.)

| Phase | backend | app |
|---|---|---|
| M0 | สมัคร/login ครู, health + heartbeat ของ queue, error รูปแบบเดียว, Filament admin | login, session, shell ครู/นักเรียน |
| 1 ใบงาน | layout อัตโนมัติ + **PDF แยกรายคน** (ArUco 4 มุม, QR มีลายเซ็น, ฟอนต์ไทย) | **หน้าสแกน**: กล้อง → Kotlin + OpenCV + ML Kit อ่าน QR |
| 2 แกนหลัก | ห้องเรียน, นักเรียน, login นักเรียนด้วยบัตร QR / PIN, import ตัวชี้วัด, รับสแกน | ห้องเรียน, การบ้าน 4 ประเภท, rubric, สแกนออฟไลน์ + คิวอัปโหลด |
| 3 ตรวจด้วย AI | Gemini (structured output, prompt มีเวอร์ชัน), key ของครูเข้ารหัส, Fuzzy 2 ระบบ, กัน prompt injection | ใส่ Gemini key |
| 4 HITL | คิวตรวจทาน, override, อนุมัติกลุ่ม, เผยแพร่, ขอตรวจใหม่, FCM | หน้าตรวจทาน, หน้าผลของนักเรียน |
| 5 CNN | endpoint โมเดล, training samples | ตัวอ่านเลข TFLite บนเครื่อง (v0.1.0) |
| 6 ITS/EDM | คลังแบบฝึก, mastery (EWMA), analytics | แบบฝึกซ่อม, mastery, dashboard ครู |
| 7 Classroom | เชื่อม Google, ผูกคอร์ส, โพสต์งาน, ส่งคะแนนกลับ | เชื่อมบัญชี, จับคู่, โพสต์ |

### Phase 8: ซิงก์ Google Classroom และตรวจจากรูปทั้งหน้า (DESIGN §19)

| ส่วน | backend | app |
|---|---|---|
| A. นำเข้าห้อง | ตัวอย่างก่อนสร้าง (ชื่อ, เดาระดับชั้น, เรียงชื่อไทย), สร้างห้อง + นักเรียน + ผูกคอร์สใน transaction เดียว, ซิงก์รายชื่อ, PIN ของนักเรียนที่เพิ่มอัตโนมัติ | ปุ่ม "นำเข้าจาก Google Classroom", หน้าแก้เลขที่/เอาออก, หน้า PIN |
| B. ซิงก์ด้วย cron | ทุก 5 นาทีใน `eduvision:queue-work`: งานใหม่จากเว็บ Classroom (ร่างเฉลยด้วย AI รออนุมัติ), งานที่ส่ง, **คะแนนไม่ตรงกัน**, นโยบายส่งช้า, `invalid_grant` → เชื่อมใหม่ | "ซิงก์ตอนนี้", การ์ด "รอดำเนินการ", หน้าคะแนนไม่ตรงกัน, รับงานส่งช้า |
| C. ตรวจจากรูปทั้งหน้า | หนึ่ง call ต่อหน้า + retry รายข้อ, ข้อที่หาไม่เจอ → ครูตรวจ, ไฟล์จาก Drive ดาวน์โหลดบน server (JPEG/PNG/HEIC/WebP/PDF) | ภาพทั้งหน้าในหน้าตรวจทาน, ป้าย "หาคำตอบข้อนี้ในภาพไม่เจอ" |
| D. งานไม่ใช้ใบงาน + เฉลยของครู | อ่านเฉลยจากรูป/PDF ครั้งเดียว แคช SHA-256 ทั้งโรงเรียน, ประมาณราคาก่อนอ่าน, AI ร่างเฉลยเมื่อไม่มี, **ไม่ตรวจก่อนครูอนุมัติเฉลย** | หน้าเฉลย 3 ทาง (พิมพ์, ถ่ายรูป, แนบไฟล์) + ช่วงหน้าและราคา |
| E. ส่งงานในแอป | นักเรียนส่งรูป/PDF เอง, ครูอัปโหลดแทนได้ | แท็บ "ส่งงาน" ของนักเรียน, หน้า "อัปโหลดรูปเพื่อตรวจ" ใช้บน Chrome ได้ครบ |
| F. ส่งผลกลับ | คะแนน + return (งานที่แอปสร้าง), **ประกาศส่วนตัวรายคน** (ทุกงาน, scope `classroom.announcements`), หน้า `/r/{id}` เปิดผลในแอป | หน้าประกาศผลรายคน + ส่งใหม่, แบนเนอร์เชื่อม Google ใหม่ |

### Phase 9: รายวิชา แผนการสอน ตัวชี้วัด และการวิเคราะห์ (DESIGN §20)

| ส่วน | backend | app |
|---|---|---|
| รายวิชา หน่วย แผน | CRUD + ผูกหลายห้อง, อ่านจากเอกสารด้วย Gemini แล้วครูยืนยัน, **การบ้านใหม่ทุกงานต้องมีรายวิชา** | ฟอร์มรายวิชา/หน่วย/แผน, หน้ายืนยันผลอ่านเอกสาร |
| ตัวชี้วัด | ระดับ strand/standard/indicator/sub_indicator, importer แบบ stream, ครูเพิ่มตัวชี้วัดของโรงเรียนเอง | ตัวเลือกแบบต้นไม้ |
| H. จับคู่ข้อกับตัวชี้วัด | Gemini เสนอเฉพาะตัวชี้วัดในแผน, roll-up ตามมาตรฐาน/หน่วย | หน้าจับคู่รายข้อ |
| I. กราฟ | 8 endpoint (mastery-summary, pass-rate, heatmap, indicator-progress, score-distribution, plan-progress) + ของนักเรียนเฉพาะตัวเอง | `fl_chart` ทุกแบบ (เรดาร์ 3–12 แกน, แท่ง, drill-down) |
| J. AI วิเคราะห์รายคน | ไม่มีชื่อ/id ใน input, **รอบกลางคืนผ่าน Gemini Batch API** หลัง 01:00 เฉพาะคนที่ข้อมูลเปลี่ยน, "วิเคราะห์ตอนนี้", ครูแก้/อนุมัติก่อนนักเรียนเห็น | หน้าวิเคราะห์รายคน, ข้อความที่ครูอนุมัติของนักเรียน |

### ประหยัด token (DESIGN §21)

อ่านเอกสารครั้งเดียว, ตัดสินช่องว่างและตัวเลขที่ CNN มั่นใจโดยไม่เรียก AI (ปิดไว้จนกว่า calibrate กับลายมือจริง), หนึ่ง call ต่อหน้า, media resolution ตามงาน, thinking level และเพดาน output ต่องาน, ใช้คำอธิบายซ้ำเมื่อคำตอบผิดแบบเดียวกัน, `ai_calls` บันทึก token แยกตามฟีเจอร์, ย่อภาพ crop บนมือถือเหลือ 768 px และ **calibration harness** (`eduvision:calibrate-gemini`)

## 3. ผลทดสอบทั้งระบบ (integration, 30 ก.ย.)

- **test ทุกชุด**: backend 1,075 ผ่านทั้ง SQLite และ MariaDB 11 (database `eduvision_test` แยกจากของ dev), `vendor/bin/pint --test` ผ่าน, app 686 ผ่าน + `flutter analyze` ไม่มี issue + coverage 88.3%, ml 198 ผ่าน
- รอบนี้เจอและแก้ test 2 ตัวที่ผ่านบน SQLite แต่ล้มบน MariaDB (ENUM เรียงตามลำดับที่ประกาศ ไม่ใช่ตามตัวอักษร และ DDL ใน transaction ของ test)
- `php artisan migrate` กับฐานข้อมูล dev: migration ใหม่ครบ (ตัวสุดท้าย `add_pin_pending_to_classroom_students`)
- **`backend/tools/smoke.sh` 48 ขั้นผ่าน** (Gemini ปลอม, รันซ้ำได้ ใช้ `SMOKE_PORT=8010` เมื่อมี server ที่ 8000 อยู่แล้ว)
  - วงจรเดิม: สมัครครู → อนุมัติ → ห้อง + นักเรียน → การบ้าน 4 ประเภท → PDF → สแกน → ตรวจ → ตรวจทาน → เผยแพร่ → นักเรียนเห็นผล → ขอตรวจใหม่ → mastery → แบบฝึก → โมเดล
  - Phase 9: รายวิชา + หน่วย + แผน ผูกกับห้อง (การบ้านที่ไม่มีรายวิชาตอบ 422)
  - Phase 8: งานไม่ใช้ใบงาน → อ่านเฉลยจากรูปครั้งเดียว (ครั้งที่สองแคชเจอ ไม่เสียเงิน) → อนุมัติ rubric → **เสนอตัวชี้วัดจากแผน** → จับคู่ → อนุมัติเฉลย → ครูอัปโหลดรูปทั้งหน้าของนักเรียนคนหนึ่ง + **นักเรียนส่งงาน 2 หน้าในแอป** (งานที่ยังไม่อนุมัติเฉลยตอบ 409) → ตรวจหน้าละ call → ตรวจทาน → เผยแพร่ → นักเรียนเปิดภาพหน้าของตัวเองได้ (ไฟล์ตรงกับที่ส่ง)
  - กราฟ 8 endpoint ของครู + 3 ของนักเรียน (นักเรียนเปิดกราฟของครูได้ 403), **วิเคราะห์ตอนนี้ → แก้ → อนุมัติ → นักเรียนเห็นข้อความที่อนุมัติ** (ไม่เห็นฉบับครู)
  - Google ไม่ได้ตั้งค่า: ทุก route ของ Google ตอบ 503 `google_not_configured` (รวม route ใหม่ 6 ตัว)
  - **Google ปลอม** (`backend/tools/smoke-google.php`: request รันใน process เดียวกับ `Http::fake` ไม่มีอะไรออกไปถึง Google): เชื่อม → รายการคอร์ส → ตัวอย่างนำเข้า (เดาชั้น ป.5 ได้) → **นำเข้าห้อง** (นำเข้าซ้ำ 409) → ซิงก์รายชื่อได้นักเรียนใหม่ 1 คน → "ซิงก์ตอนนี้" ได้งานที่สร้างในเว็บ Classroom พร้อมเฉลยที่ AI ร่าง → งานที่ส่งก่อนอนุมัติรอ (`waiting_key`) → อนุมัติ → ตรวจ → เผยแพร่ → **ประกาศส่วนตัวถูกส่ง** (`INDIVIDUAL_STUDENTS` ถึงนักเรียนคนนั้นคนเดียว) และไม่ส่งคะแนนไปงานที่แอปไม่ได้สร้าง
- ข้อสังเกตของ smoke: รันหนึ่งรอบส่งเกิน 120 request/นาทีของครูหนึ่งคน (rate limit ทั่วไป) script จึงรอตาม `Retry-After` หนึ่งครั้ง ครูที่ใช้แอปจริงไม่ถึงเกณฑ์นี้ ทุกครั้งที่รันจะเพิ่มแถวทดสอบในฐานข้อมูล dev และ worker ของ smoke (`eduvision:queue-work`) อาจเริ่มรอบวิเคราะห์กลางคืนของวันนั้นในฐานข้อมูล dev ด้วย Gemini ปลอม

## 4. ยืนยันกับของจริงแล้ว / ยังไม่ได้ยืนยัน

| เรื่อง | สถานะ |
|---|---|
| ตรวจการบ้านด้วย Gemini จริง (ตอบสั้น, แสดงวิธีทำ, ตอบอิสระ, คำอธิบาย, prompt injection 5/5) | ✅ 27 ก.ย. (`gemini-3.8-flash`) |
| **calibration กับ Gemini จริง** (อ่านทีละข้อ, ทั้งหน้า และเอกสารเฉลย ที่ media resolution high/medium/low) | ✅ 30 ก.ย. ด้วย**ภาพสังเคราะห์** 100 รายการ อ่านถูก 100% ทุกระดับ token ต่อข้อที่ high/medium/low: ตอบสั้น 1,204/664/391, ทั้งหน้า 2,458/1,898/1,632 (ตาราง DESIGN §21.10) ยังไม่ลดระดับใน `.env` จนกว่าจะได้ลายมือจริง |
| prompt ใหม่อื่นๆ กับ Gemini จริง: ร่างเฉลย, อ่านเอกสารรายวิชา/แผน, เสนอตัวชี้วัด, วิเคราะห์รายคน | ❌ ทดสอบด้วย Gemini ปลอมเท่านั้น |
| Gemini Batch API (รอบกลางคืน): ชื่อ field และสถานะ | ❌ ทำตามเอกสารและทดสอบด้วย `Http::fake` ทั้งสองรูปแบบ ยังไม่เคยส่ง batch จริง |
| `GEMINI_MEDIA_PER_PART=true` (media resolution ต่อภาพ) | ❌ ใช้ค่าตั้งต้น `false` (ระดับเดียวต่อ call) ที่ใช้ได้แน่กับ `v1beta` |
| Google Classroom ทั้ง Phase 7 และ 8 (นำเข้า, ซิงก์, ดาวน์โหลดจาก Drive, ส่งคะแนน, **ประกาศส่วนตัว**) | ❌ ยังไม่มี OAuth client ทดสอบด้วย `Http::fake` และ smoke ที่ Google ปลอมเท่านั้น ข้อที่ต้องดูกับของจริง: ประกาศ `INDIVIDUAL_STUDENTS` เห็นเฉพาะนักเรียนคนนั้นไหม, ความยาวข้อความสูงสุด, Classroom ส่งอีเมลแจ้งนักเรียนหรือไม่ (DESIGN §19.7) |
| ลายมือจริง, กล้องกับกระดาษจริงบนมือถือ, hosting จริง | ❌ ยังไม่ได้ทำ (หัวข้อ 6) |

## 5. โมเดลอ่านตัวเลข v0.1.0

เทรนด้วยข้อมูลสังเคราะห์จาก MNIST/EMNIST วัดบน test set 2,400 ภาพที่แยกตามคนเขียน: CER 5.9%, ถูกทั้งสตริง 81.8% (1 หลัก 97%, 6 หลัก 68%) **ยังไม่เคยเจอลายมือจริง** การให้คะแนนเต็มจาก CNN โดยไม่เรียก Gemini (`GRADING_CNN_SKIP_ENABLED`) จึงยังปิดอยู่

## 6. สิ่งที่ทีมต้องทำต่อ (agent ทำแทนไม่ได้)

| # | งาน | ทำไม | อ้างอิง |
|---|---|---|---|
| 1 | ✅ **merge งาน Phase 8–9 แล้ว** (30 ก.ย.) ผ่าน PR และ CI ครบ | – | CLAUDE.md |
| 2 | ✅ **PR #1 (หน้าสแกนบนเว็บ) merge แล้ว** และหน้า "สแกนใบงานได้เฉพาะในแอป Android" มีปุ่ม "อัปโหลดรูปเพื่อตรวจ" (`/hand-ins/upload`) แล้ว | – | DESIGN §19.11 |
| 3 | Google Cloud OAuth (Web + Android) และ**เพิ่ม scope `classroom.announcements`** ใน consent screen แล้ว**ครูที่เคยเชื่อมไว้ต้องกด "เชื่อมใหม่"** (ตั้งค่า → Google Classroom) | ไม่มี scope นี้ ประกาศผลรายคนจะ "ส่งไม่สำเร็จ" และแอปขึ้นแบนเนอร์เชื่อมใหม่ | KICKOFF ส่วนที่ 6 (G3), HOSTING §6.3 |
| 4 | ลองวงจร Google กับคอร์สทดลองจริง (G7 + ย่อหน้า Phase 8): นำเข้าห้อง, สร้างงานในเว็บ Classroom, นักเรียนแนบรูป, ประกาศผล | ยังไม่เคยยืนยันกับ Google จริง (หัวข้อ 4) | KICKOFF G7 |
| 5 | **ใส่ไฟล์ตัวชี้วัดจริงของหลักสูตร** (CSV ตาม `docs/curriculum/template.csv`) แล้ว import (`eduvision:import-skills` หรือหน้า Filament) | ตอนนี้มีแค่ตัวอย่างไม่กี่แถว รายวิชา การจับคู่ตัวชี้วัด และกราฟต้องใช้ตัวชี้วัดจริง | `docs/curriculum/README.md`, HOSTING §8 |
| 6 | **ลายมือจริงจากทีม** (ไม่ใช่ของนักเรียนจริง) ตามชุด calibration แล้วรัน `eduvision:calibrate-gemini` | ตัดสินว่าลด media resolution ได้ไหม (token ขาเข้าต่อข้อลดราว 30–70% ตามตาราง DESIGN §21.10) และเปิด CNN skip ได้ไหม ตอนนี้วัดแค่ภาพสังเคราะห์ | DESIGN §21.10, `docs/fixtures/calibration/README.md` |
| 7 | **ทดสอบกล้องกับกระดาษจริงบนมือถือ Android** | ArUco/crop/อ่าน QR ทดสอบด้วยภาพสังเคราะห์เท่านั้น | DESIGN §15 Phase 1 |
| 8 | ออก SSL + รัน `tools/hosting-probe.php` แล้ว **deploy ตาม `docs/HOSTING.md`** (ค่า PHP ใหม่: `upload_max_filesize` ≥ 10M, `post_max_size` ≥ 55M, `max_execution_time` ≥ 90) | ใช้งานผ่านเน็ต | HOSTING §4.1 |
| 9 | Firebase: `google-services.json` + service account | แจ้งเตือนบนมือถือ | HOSTING §6.2 |
| 10 | ตรวจราคาของ Gemini ใน `GEMINI_PRICE_INPUT_PER_M`/`OUTPUT_PER_M` กับหน้าราคาของ Google ก่อนใช้จริง | ราคาที่แอปแสดงก่อนอ่านเอกสารคำนวณจากค่านี้ (Flash 3.x ขึ้นราคา 1 ม.ค. 2570) | `backend/.env.example` |
| 11 | **เปลี่ยน Gemini API key** หลังนำเสนอ และจำกัด key ให้ใช้ได้เฉพาะ Generative Language API | key ถูกส่งผ่านแชต | DESIGN §10.1 |
| 12 | ปิด Antigravity IDE ในโฟลเดอร์นี้ | เคยสร้าง commit ปนกับงาน | — |

## 7. ข้อที่ยังต้องตัดสินใจ

- **เคสแสดงวิธีทำที่คำตอบใกล้เคียง** (เช่น 38 แทน 36) ยังได้ระดับ "มั่นใจ" และอนุมัติแบบกลุ่มได้ ข้อเสนอ: บังคับอย่างน้อยระดับ "ควรดู" (DESIGN §11.8)
- **ระดับ media resolution**: ผ่านเกณฑ์ที่ `low` กับภาพสังเคราะห์ แต่ควรรอผลกับลายมือจริง (หัวข้อ 6 ข้อ 6) ก่อนลด

## 8. รันในเครื่อง

```bash
# server (ถ้า .env ตั้ง GEMINI_FAKE=false = ใช้ Gemini จริงและเสียเงินทุกครั้งที่ตรวจ)
docker start eduvision-mariadb
cd backend && php artisan serve            # http://127.0.0.1:8000  admin ที่ /admin
php artisan eduvision:queue-work           # รันทุกครั้งที่มีงานในคิว (production ใช้ cron ทุกนาที)
SMOKE_PORT=8010 bash tools/smoke.sh        # ทดสอบวงจรเต็มด้วย Gemini และ Google ปลอม (port อื่นถ้า 8000 ถูกใช้)
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=eduvision_test \
  DB_USERNAME=eduvision DB_PASSWORD=eduvision php artisan test   # suite บน MariaDB เหมือน CI

# app
cd app && flutter run -d chrome --dart-define=API_BASE_URL=http://127.0.0.1:8000   # พรีวิว UI
cd app && flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000              # มือถือจริง (ใช้ adb reverse tcp:8000 tcp:8000)
cd app && flutter test --coverage && dart run tool/check_coverage.dart --min 70

# ml
cd ml && uv run pytest
```

รายละเอียดอยู่ใน `backend/README.md`, `app/README.md`, `ml/README.md`
