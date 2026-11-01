# เริ่มที่นี่: คู่มือเพื่อนร่วมพัฒนา Krucheck

**เริ่มจากหน้างานของตัวเองบน GitHub:** korn-daengthong ดู #247 และ Suwimon249 ดู #248 หน้านั้นบอกลำดับงานทั้งหมด

คู่มือนี้อยู่ใน repo ที่ `docs/team/` มี 5 ไฟล์ แบบตัวการ์ตูนในโฟลเดอร์ `mascot/` โค้ดแก้แชทในโฟลเดอร์ `chat-fixes/` และ hook กัน key รั่วในโฟลเดอร์ `hooks/` เมื่อไฟล์ task พูดถึงสามโฟลเดอร์นี้ หมายถึงของใต้ `docs/team/` อ่านตามลำดับ:

| ไฟล์ | สำหรับใคร |
|---|---|
| `00-START-HERE.md` (ไฟล์นี้) | ทุกคน: แบ่งงาน, ส่วนที่ห้ามแตะ, เครื่อง RAM 4 GB, วิธีทำงานกับ AI agent |
| `RUN-GUIDE.md` | ทุกคน: ลงโปรแกรมและรันโปรเจกต์บนเครื่อง |
| `TASK-3-chat-mascot.md` | korn-daengthong (เครื่อง RAM 4 GB): แยกโค้ดแชท AI + ใส่ตัวการ์ตูนน้องเช็ค + แก้แชทบอท |
| `TASK-2-live-class-feed.md` | Suwimon249: ฟีดห้องเรียนและเช็คชื่อแบบสด (Laravel + Flutter, REST, Firestore) |
| `TASK-1-onboarding-about.md` | ไม่บังคับ: หน้าแนะนำการใช้งานครั้งแรก + หน้าเกี่ยวกับ korn-daengthong ทำต่อจากงานที่ 3 เมื่อมีเวลา |

ข้อกำหนดของวิชา: คนละ 1 ฟีเจอร์ และคนละอย่างน้อย 10 commit ไฟล์ task แต่ละไฟล์แบ่งงานไว้ 16 commit ขึ้นไปแล้ว

งานที่ 1 กับส่วน D ของงานที่ 3 คืองานเดียวกันและใช้ branch เดียวกัน ห้ามแยกให้คนละคน

### เลข issue บน GitHub

| งาน | คนทำ | issue | branch |
|---|---|---|---|
| งานที่ 3 ส่วน A | korn-daengthong | #89 | `refactor/app-assistant-split` |
| งานที่ 3 ส่วน B | korn-daengthong | #90 | `feat/app-assistant-mascot` |
| งานที่ 3 ส่วน C | korn-daengthong | #91 | `fix/app-assistant-chat` |
| งานที่ 3 ส่วน D (ถ้ามีเวลา) | korn-daengthong | #92 | `feat/app-onboarding-about` |
| งานที่ 2 C1–C8 | Suwimon249 | #93 | `feat/live-class-feed` |
| งานที่ 2 C9–C14 | Suwimon249 | #94 | `feat/live-class-feed` |
| งานที่ 2 C15–C18 | Suwimon249 | #95 | `feat/live-class-feed` |

- แต่ละ commit มี issue ย่อยของตัวเอง: korn-daengthong #249–#285 และ Suwimon249 #286–#303 เลขของแต่ละข้ออยู่ในตารางความคืบหน้าของไฟล์ task
- ใส่บรรทัด `Closes #เลขของ commit นั้น` ท้ายข้อความ commit issue ย่อยจะปิดเองเมื่อ PR merge และ board จะย้ายไป Done เอง
- commit ที่ไม่มี issue ย่อย เช่น commit แก้ CI และงานส่วน D ให้ใส่ `Refs #เลข` ตามตารางด้านบนแทน
- ใน PR เขียน `Closes #เลข` ตามตารางด้านบน ซึ่งงานที่ 2 มี PR เดียว จึงเขียนครบทั้ง 3 เลข

---

## 0. ห้ามแตะส่วนผู้ดูแลระบบ (admin)

งานของเพื่อนทุกคนต้องไม่แก้ ไม่เพิ่ม และไม่ใช้ส่วน admin ทั้งหมด

| ห้ามแก้ | คืออะไร |
|---|---|
| `app/lib/features/admin/` และ route `/admin-home` | หน้าผู้ดูแลในแอป |
| `backend/app/Filament/` | แผง `/admin` และ `/qa` |
| route ที่ใช้ `role:admin` และ `auth/admin-handoff` | API ของผู้ดูแล |
| `backend/app/Domain/Accounts/` และ `PurgeLiveUserJob` | การลบและกู้บัญชีที่ผู้ดูแลสั่ง |
| `AdminSeeder` และค่า `ADMIN_*` | บัญชีผู้ดูแล |
| test ของส่วนนี้ เช่น `app/test/flows/admin_flow_test.dart` | ไม่ต้องแก้ แต่ต้องผ่านเหมือนเดิม |

- ทดสอบด้วยบัญชีครูและนักเรียนตัวอย่างเท่านั้น ไม่ต้องสร้างหรือใช้บัญชีผู้ดูแล
- ไม่ต้องสมัครบัญชีครูใหม่ เพราะบัญชีใหม่ต้องรอผู้ดูแลอนุมัติ
- ถ้างานดูเหมือนต้องแก้ส่วนนี้ ให้หยุดแล้วถาม phuwishpk

**ตรวจก่อนเปิด PR ทุกครั้ง** คำสั่งนี้ต้องไม่มีผลลัพธ์:
```bash
git fetch origin && git diff --name-only origin/main... | grep -iE 'admin|Filament|Domain/Accounts|PurgeLiveUser'
```

---

## 1. เครื่อง RAM 4 GB ทำได้ไหม

**รันทั้งระบบบนเครื่องเดียวไม่ไหว ทำได้แค่รันแอปบน Chrome แล้วชี้ไปที่ server ทดสอบ และจะช้า** korn-daengthong ใช้เครื่อง 4 GB จึงรับงานที่ 3 ซึ่งแก้แอปอย่างเดียว ส่วนงานที่ 2 ไม่แนะนำบนเครื่องนี้ ถ้าเครื่องค้างบ่อยให้ย้ายไปใช้ Codespaces

### RAM ที่โปรแกรมแต่ละตัวใช้ (ตัวเลขประมาณ ยังไม่ได้วัดบนเครื่อง 4 GB จริง)

| โปรแกรม | RAM โดยประมาณ | เครื่อง 4 GB |
|---|---|---|
| Windows 10/11 เอง | 2–2.5 GB | เหลือให้ใช้ราว 1.5–2 GB |
| macOS เอง | 1.5–2 GB | |
| VS Code + ปลั๊กอิน Dart | 0.6–1 GB | ใช้ได้ |
| Dart analyzer และ `flutter run` (เว็บ) | 1.5–3 GB | ตึงมาก ช้า และอาจค้าง |
| Chrome ที่เปิดแอป (2–3 แท็บ) | 0.5–1 GB | ใช้ได้ |
| Claude Code (CLI) | 0.2–0.4 GB | ใช้ได้ |
| `flutter test` ทั้งชุด | 1.5 GB ขึ้นไป | ไม่แนะนำ ปล่อยให้ CI รันแทน |
| Android Studio | 2–3 GB | **ไม่แนะนำ** |
| เครื่องจำลอง Android (AVD) | 2–4 GB | **ใช้ไม่ได้** |
| Docker Desktop + MariaDB | 1.5–2.5 GB | **ไม่แนะนำ** |
| PHP + `php artisan serve` | 0.1–0.3 GB | ใช้ได้ |

### ถ้าใช้เครื่อง 4 GB ให้ทำแบบนี้

1. **รับงานที่ 3** เพราะแก้แอปอย่างเดียว ไม่ต้องรัน backend หรือ Docker
2. **ใช้ VS Code** ไม่ต้องใช้ Android Studio ส่วน Android SDK จำเป็นเฉพาะตอนลองบนมือถือจริงเท่านั้น
3. **ทดสอบบน Chrome** (`flutter run -d chrome`) ห้ามเปิดเครื่องจำลอง ถ้าจะลองบน Android ให้ต่อมือถือจริงด้วยสาย USB
4. **ไม่ต้องรัน backend เอง** ให้ชี้แอปไปที่ server ทดสอบ (ขอที่อยู่จาก phuwishpk):
   `flutter run -d chrome --web-port 5173 --dart-define=API_BASE_URL=<ที่อยู่ server ทดสอบ>` (ใส่แค่โดเมน ไม่ต้องต่อท้ายด้วย `/api/v1`)
5. **ปิดโปรแกรมอื่น** เช่น LINE, Discord, Spotify และแท็บ Chrome ที่ไม่ใช้
6. **รัน test เฉพาะโฟลเดอร์ที่แก้** เช่น `flutter test test/assistant/` แล้วปล่อยให้ CI ใน Pull Request รันทั้งชุดและ build APK แทน
7. **ถ้าเครื่องค้างบ่อย** ให้ใช้ GitHub Codespaces (หัวข้อถัดไป)

### ทางเลือก: GitHub Codespaces (ไม่ใช้ RAM ของเครื่องเรา)

Codespaces คือเครื่องเสมือนบน cloud ของ GitHub เปิดผ่านเบราว์เซอร์ได้เลย เครื่อง 2-core ให้ RAM 8 GB บัญชีฟรีใช้ได้ประมาณ 60 ชั่วโมงต่อเดือน (บัญชีนักศึกษาผ่าน GitHub Education ได้มากกว่านี้)

- เปิดที่หน้า repo → ปุ่ม **Code** → แท็บ **Codespaces** → **Create codespace on main**
- **งานที่ 2:** รัน PHP, Composer และ test ใน Codespaces ได้ดี
- **Flutter web:** รันด้วย `flutter run -d web-server --web-port 5173` แล้วเปิดผ่านพอร์ตที่ Codespaces forward ให้ แต่ Google Sign-in จะใช้ไม่ได้ เพราะที่อยู่ไม่ใช่ `localhost` ให้ใช้การล็อกอินด้วยอีเมลแทน
- **ปิด codespace ทุกครั้งที่เลิกงาน** ไม่อย่างนั้นชั่วโมงฟรีจะหมดไปเรื่อยๆ

### ถ้าคนที่มี RAM 4 GB ต้องทำงานที่ 2

ใช้ **SQLite แทน MariaDB** จะได้ไม่ต้องเปิด Docker (test ของโปรเจกต์รันบน SQLite อยู่แล้ว) ให้แก้ `backend/.env` ดังนี้:
```
DB_CONNECTION=sqlite
DB_DATABASE=/ที่อยู่เต็ม/Teacherhelper/backend/database/database.sqlite
```
แล้วรัน `touch database/database.sqlite` (Windows ใช้ `type nul > database\database.sqlite`) ตามด้วย `php artisan migrate --seed`

ถ้า migrate ติดปัญหา ให้ย้ายไปทำใน Codespaces

---

## 2. วิธีทำงานกับ AI agent (Claude Code)

ไฟล์ task เขียนให้ทั้ง **คน** และ **agent** อ่านได้ แต่ละ commit มีครบทั้ง: เป้าหมาย ไฟล์ที่ต้องแก้ สิ่งที่ต้องเขียน วิธีตรวจ ข้อความ commit และป้ายบอกว่าใครควรทำ

| ป้าย | ความหมาย |
|---|---|
| 🧑 **ทำเองได้** | งานเล็ก เช่น ข้อความ UI หรือข้อมูลทีม ฝึกทำเองได้ ใช้ agent ช่วยดูได้ |
| 🤖 **ให้ agent ทำ** | งานที่ต้องเข้าใจโค้ดเดิมหลายไฟล์ ให้ agent เขียน แล้วเราอ่านและลองเอง |
| 🧑🤖 **ทำร่วมกัน** | agent เขียนโครง เราเติมเนื้อหาหรือปรับหน้าตาเอง |

### คำสั่งเริ่มต้นสำหรับ agent

เปิด Claude Code ที่โฟลเดอร์ repo แล้วพิมพ์ (เปลี่ยนชื่อไฟล์ task ให้ตรงกับงานของตัวเอง):

```
อ่าน CLAUDE.md, docs/DESIGN.md §6.1 §27 §38, หัวข้อ 0 ของ docs/team/00-START-HERE.md และไฟล์ docs/team/TASK-1-onboarding-about.md
ทำทีละ commit ตามลำดับในไฟล์ task เริ่มจาก commit แรกที่ยังไม่ได้ติ๊ก [x]
แต่ละ commit: เขียนโค้ด → รันคำสั่งในหัวข้อ "ตรวจ" ของ commit นั้น → ให้ผมดู diff ก่อน
ห้าม commit เอง ผมจะ commit เอง ห้ามแก้ไฟล์นอกรายการ "ไฟล์" ของ commit นั้นโดยไม่ถาม ยกเว้นการติ๊ก [x] ในไฟล์ task
ห้ามแตะส่วน admin ตามหัวข้อ 0 ถ้างานต้องแตะส่วนนั้นให้หยุดแล้วถามผม
ถ้า commit นั้นมีป้าย 🧑 ให้อธิบายว่าต้องทำอะไรแล้วรอผมทำเอง
```

### กติกาสำคัญ

- **ต้อง commit เอง** ด้วยบัญชี GitHub ของตัวเอง (`git config user.email` ต้องตรงกับบัญชี) agent ช่วยเขียนได้ แต่คนกด commit ต้องเป็นเรา
- **ห้ามมีบรรทัด `Co-Authored-By: Claude`** ในข้อความ commit ถ้า agent ใส่มา ให้ลบออกก่อน
- **ทำทีละ commit** เขียนเสร็จ ตรวจผ่าน แล้วค่อย commit อย่ารวมหลายข้อไว้ใน commit เดียว
- **เข้าใจทุกบรรทัดก่อน commit** ตอนนำเสนออาจารย์อาจถามว่าโค้ดตรงนี้ทำอะไร ถ้าไม่เข้าใจ ให้ถาม agent ว่า "อธิบายโค้ดนี้ทีละบรรทัด"
- **ติ๊ก `[x]` ในตารางความคืบหน้า** ของไฟล์ task แล้ว `git add` ไฟล์นั้นไปใน commit เดียวกัน agent จะได้รู้ว่าต้องทำข้อไหนต่อ
- **push บ่อยๆ** อย่างน้อยวันละครั้ง ถ้าเครื่องเสียงานจะไม่หาย
- **ต้องไม่ squash:** ถ้า squash merge commit ทั้งหมดของ PR จะรวมเหลือ 1 commit บน main ให้ phuwishpk เลือก **Create a merge commit** ทุกครั้งที่ merge PR ของเพื่อน
- **เปิด Pull Request** ตั้งแต่ commit แรก แล้วเขียนชื่อ PR ว่า "WIP: …" CI จะรันทุกครั้งที่ push ทำให้รู้ปัญหาเร็ว

### ถ้า CI ล้ม

1. เปิด PR → แท็บ **Checks** → กดดูขั้นที่เป็นสีแดง
2. copy ข้อความ error ไปให้ agent: "CI ล้มด้วย error นี้ แก้ให้หน่อย"
3. แก้แล้ว commit ใหม่เป็น `fix(app): ...` หรือ `fix(backend): ...` (นับเป็น commit ด้วย)
