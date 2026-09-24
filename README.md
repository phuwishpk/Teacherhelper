# EduVision

แพลตฟอร์ม AI ตรวจการบ้านลายมือและติดตามผู้เรียน สำหรับครูและนักเรียนระดับประถม-มัธยม
(repo ชื่อ Teacherhelper ส่วนชื่อแอปคือ EduVision)

## เอกสาร
- [docs/DESIGN.md](docs/DESIGN.md) เอกสารออกแบบระบบ (สถาปัตยกรรม, schema, API, fuzzy, prompt)
- [docs/KICKOFF.md](docs/KICKOFF.md) แผนขึ้นโปรเจกต์และกติกาของ repo
- รายงานและสไลด์: <ลิงก์ Google Drive>

## โครงสร้าง
| โฟลเดอร์ | เนื้อหา | เริ่มใช้งาน |
|---|---|---|
| `app/` | แอป Flutter (Android) | ดู `app/README.md` |
| `backend/` | Laravel API + Filament admin | ดู `backend/README.md` |
| `ml/` | โค้ด Python สำหรับเทรน CNN (uv) | `cd ml && uv sync` |
| `docs/` | เอกสารเทคนิคและ CSV ตัวชี้วัด | แก้ผ่านหน้าเว็บ GitHub ได้ |
| `tools/` | สคริปต์ช่วยงาน เช่น hosting probe | |

## ทีมและรายวิชา
- ผู้พัฒนา: phuwishpk (โค้ดทั้งหมด) และสมาชิกทีม: <ชื่อ> (เอกสาร, ตัวชี้วัด, fixture, usability test)
- ส่งเป็นโปรเจกต์ของวิชา 03376133 Mobile Devices Software Development และ 03376132 AI in Education
- milestone ปัจจุบัน: M0 ดู [GitHub Project](<ลิงก์ Project>) กติกาการทำงานอยู่ใน `CLAUDE.md` และ repo นี้เป็น public ห้าม commit ไฟล์ลับ
