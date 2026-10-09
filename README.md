# Krucheck

แพลตฟอร์ม AI ตรวจการบ้านลายมือและติดตามผู้เรียน สำหรับครูและนักเรียนระดับประถม-มัธยม
(repo ชื่อ Teacherhelper ส่วนชื่อแอปคือ Krucheck เดิมชื่อ EduVision ชื่อทางเทคนิคเช่น `com.eduvision.app` และคำสั่ง `eduvision:*` ยังเป็นชื่อเดิม ดู DESIGN §28)

## เอกสาร
- [docs/DESIGN.md](docs/DESIGN.md) เอกสารออกแบบระบบ (สถาปัตยกรรม, schema, API, fuzzy, prompt)
- [docs/KICKOFF.md](docs/KICKOFF.md) แผนขึ้นโปรเจกต์และกติกาของ repo
- [docs/DEPLOY-CHECKLIST.md](docs/DEPLOY-CHECKLIST.md) ขั้นตอนขึ้นเว็บครั้งแรกแบบทำตามทีละข้อ และ [docs/HOSTING.md](docs/HOSTING.md) รายละเอียดการ deploy บน Plesk
- [docs/SELFHOST.md](docs/SELFHOST.md) คู่มือ deploy เครื่อง self-hosted (ชุดทดสอบด้วย Docker แยกจาก Plesk)
- [docs/STATUS.md](docs/STATUS.md) ความคืบหน้าล่าสุด
- รายงานและสไลด์: <ลิงก์ Google Drive>

## โครงสร้าง
| โฟลเดอร์ | เนื้อหา | เริ่มใช้งาน |
|---|---|---|
| `app/` | แอป Flutter (Android และเว็บแอปที่ `/app/`) | ดู `app/README.md` |
| `backend/` | Laravel API + Filament admin | ดู `backend/README.md` |
| `ml/` | โค้ด Python สำหรับเทรน CNN (uv) | `cd ml && uv sync` |
| `docs/` | เอกสารเทคนิคและ CSV ตัวชี้วัด | แก้ผ่านหน้าเว็บ GitHub ได้ |
| `tools/` | สคริปต์ช่วยงาน เช่น hosting probe และ `build-web.sh` (build เว็บแอป) | |

## ทีมและรายวิชา
- ผู้พัฒนา: phuwishpk (โค้ดทั้งหมด) และสมาชิกทีม: <ชื่อ> (เอกสาร, ตัวชี้วัด, fixture, usability test)
- ส่งเป็นโปรเจกต์ของวิชา 03376133 Mobile Devices Software Development และ 03376132 AI in Education
- milestone ปัจจุบัน: M0 ดู [GitHub Project](<ลิงก์ Project>) กติกาการทำงานอยู่ใน `CLAUDE.md` และ repo นี้เป็น public ห้าม commit ไฟล์ลับ
