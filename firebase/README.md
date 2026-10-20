# Firestore (ชั้นข้อมูลสด, DESIGN §38)

โฟลเดอร์นี้เก็บ **กฎความปลอดภัยของ Firestore** ที่ใช้กับ Firebase project ของ Krucheck (project เดียวกับ FCM, docs/HOSTING.md §6.2) ไม่ใช้ Firebase CLI: วางกฎลง console ด้วยมือ ขั้นตอนทั้งหมดอยู่ใน docs/HOSTING.md §6.5

| ไฟล์ | ใช้ทำอะไร |
|---|---|
| `firestore.rules` | กฎที่ต้องวางใน Firebase console > Build > Firestore Database > Rules > Publish ทุกครั้งที่ไฟล์นี้เปลี่ยน |

หลักการสั้น ๆ

- MariaDB ยังเป็นแหล่งความจริง backend เขียน Firestore ด้วย service account (กฎไม่บังคับกับ service account) แอปอ่านอย่างเดียวใต้ `users/{uid}` ของตัวเอง `uid` คือ `u{users.id}`
- แอปเข้าสู่ระบบ Firebase ด้วย custom token จาก `POST /api/v1/auth/firebase-token` จึงต้องเปิด **Authentication** ใน console (ไม่ต้องเปิด provider ใด)
- ทุก document มี `expires_at` (+90 วัน) แต่ Firestore ลบให้เองก็ต่อเมื่อตั้ง **TTL policy** ที่ field นั้นของ collection group `notices`, `assignments`, `submissions` ใน Google Cloud console (docs/HOSTING.md §6.5 ข้อ 4) **ยังไม่ได้ตั้ง** (21 ต.ค. 2569) ไม่บังคับ แต่ต้องตั้งก่อนใช้กับนักเรียนจริง ตั้งภายหลังได้ เอกสารเก่าจะถูกลบย้อนหลังเอง
- โควตา Spark: อ่าน 50,000 และเขียน 20,000 ครั้งต่อวัน listener นับหนึ่งอ่านต่อ document ที่เปลี่ยน ดูการใช้งานที่ Firestore Database > Usage
- ไฟล์ service account และ `google-services.json` ห้ามอยู่ใน repo (gitignored อยู่แล้ว)
