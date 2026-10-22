# ข้อมูลตัวอย่างสำหรับ seed (ใช้ในเครื่อง)

- `skills_sample.csv` — **ตัวอย่าง** ตัวชี้วัด 2 วิชา (ค, ว) ระดับ ป.5 13 แถว ในรูปแบบ CSV ของ DESIGN §20.2
  (`subject_code,level,code,parent_code,grade_level,name`, UTF-8 ไม่มี BOM) ใช้ทดสอบระบบเท่านั้น
  ข้อความตัวชี้วัดย่อจากหลักสูตรแกนกลางฯ 2551 (ฉบับปรับปรุง 2560) ไม่ใช่ข้อความทางการครบถ้วน
  ไฟล์จริงให้ admin import ผ่าน Filament (หน้าทักษะ > นำเข้า CSV) หรือ
  `php artisan eduvision:import-skills path/to/file.csv` วิธีเตรียมไฟล์ทั้งหลักสูตรอยู่ที่ [docs/curriculum/README.md](../../../docs/curriculum/README.md)
- แถว `ค 1.1` เป็นมาตรฐาน (`standard`) และตัวชี้วัด `ค 1.1 ป.5/…` อยู่ใต้มาตรฐานนั้น แถวอื่นเป็นตัวชี้วัดที่ไม่ได้ใส่มาตรฐานไว้ในไฟล์ตัวอย่าง
