# EduVision: คู่มือ deploy เครื่อง self-hosted (สำหรับ agent)

- **สำหรับ:** agent หรือผู้พัฒนาที่ได้รับคำสั่งให้ deploy หรืออัปเดต EduVision บน**เครื่อง server ของผู้พัฒนาเอง** (Docker) อ่าน §1 และ §2 ก่อน แล้วทำตามหัวข้อที่ตรงกับงาน
- **ไม่ใช่:** server จริงบน Hostatom (Plesk) ซึ่งใช้ [HOSTING.md](HOSTING.md) และ [DEPLOY-CHECKLIST.md](DEPLOY-CHECKLIST.md) สองที่นี้แยกกันทั้งวิธี deploy ฐานข้อมูล key และบัญชีผู้ใช้ คำสั่งในไฟล์นี้ใช้กับ Hostatom ไม่ได้ และข้อจำกัดของ Hostatom (ไม่มี Docker ไม่มี process ค้าง) ไม่ใช้กับเครื่องนี้
- **สถานะ:** ติดตั้งครั้งแรก 9 ต.ค. 2569 เป็นชุดทดสอบ (DESIGN §26, #73) สคริปต์ทุกตัวใน `tools/selfhost/` ผ่านการลองติดตั้งจากศูนย์แล้ว
- **กติกาของไฟล์นี้:** อยู่ใน repo สาธารณะ ห้ามใส่รหัสผ่าน key IP หรือที่อยู่สาธารณะของเครื่อง

## 1. กติกาสำหรับ agent

1. **ห้ามแสดง secret** ห้าม `cat` หรือ `grep` ค่าใน `app.env`, `.env` หรือ `admin-first-login.txt` ออกมาในแชตหรือ log (ชื่อตัวแปรแสดงได้ ค่าแสดงไม่ได้ ยกเว้น `APP_URL`) ถ้าเจ้าของต้องการรหัส ให้บอกคำสั่งให้เขาอ่านเอง
2. **การเปิดเครื่องออกอินเทอร์เน็ตเป็นการตัดสินใจของเจ้าของทุกครั้ง** รัน `tunnel.sh up` ได้เมื่อเจ้าของสั่งในบทสนทนานั้นเท่านั้น คำว่า "deploy" หรือ "อัปเดต" ไม่รวมการเปิดสาธารณะ
3. **เครื่องนี้มีโปรเจกต์อื่นของเจ้าของ** แตะเฉพาะของที่ชื่อขึ้นต้นด้วย `eduvision` (container, volume, network และโฟลเดอร์ `~/eduvision`) ห้ามรัน `docker system prune` ห้ามแก้ Tailscale, SSH, firewall, crontab หรือ sudo
4. **ข้อมูลอยู่ใน volume `eduvision_db` และ `eduvision_storage`** ห้ามรัน `down -v` ห้ามลบ volume ห้ามรัน `install.sh` ทับของเดิม และห้ามสร้าง `APP_KEY` หรือ `QR_SIGNING_KEY` ใหม่ (ค่าที่เข้ารหัสไว้จะอ่านไม่ออก และใบงานที่พิมพ์แล้วจะสแกนไม่ได้)
5. **ไม่ต้องใช้ sudo** ผู้ใช้บนเครื่องอยู่ในกลุ่ม `docker` แล้ว ถ้างานใดต้องใช้ sudo ให้หยุดแล้วถามเจ้าของ
6. **ถ้า ssh เตือนว่า host key เปลี่ยน ให้หยุด** อย่าลบ `known_hosts` เอง ถามเจ้าของก่อน เครื่องอาจถูกลง OS ใหม่ ซึ่งแปลว่าต้องติดตั้งใหม่ตาม §6
7. **ทำเสร็จต้องตรวจตาม §7** แล้วรายงานผลที่เห็นจริง รวมถึงข้อที่ไม่ผ่าน

## 2. เครื่องและโครงสร้าง

- **เข้าเครื่อง:** `ssh phuwish115` (alias ใน `~/.ssh/config` ของเครื่องผู้พัฒนา ใช้ SSH key) ใช้ได้เมื่ออยู่วง LAN เดียวกับเครื่อง นอกบ้านใช้ alias `phuwish115-ts` ซึ่งวิ่งผ่าน Tailscale
- **ระบบ:** Ubuntu, Docker กับ Compose plugin, git, openssl, curl

โฟลเดอร์ `~/eduvision/` บนเครื่อง:

| path | คืออะไร |
|---|---|
| `repo/` | clone ของ repo นี้ที่ branch `main` สคริปต์อยู่ใน `repo/tools/selfhost/` |
| `app.env` | `.env` ของ Laravel (ลับ สิทธิ์ 600) |
| `.env` | รหัสผ่าน database ที่ Compose ใช้ (ลับ สิทธิ์ 600) |
| `web/app/` | เว็บแอป คือผล build ของ Flutter (สิทธิ์ต้องอ่านได้ทุกคน) |
| `admin-first-login.txt` | login แรกของ admin มีหลังติดตั้งใหม่จนกว่าเจ้าของจะลบ |

container ทั้งหมด (Compose project `eduvision`):

| container | หน้าที่ |
|---|---|
| `eduvision-app` | Apache + PHP 8.3 และโค้ด `backend/` ฟังที่ `127.0.0.1:8085` ของเครื่องเท่านั้น |
| `eduvision-db` | MariaDB 11.8 |
| `eduvision-worker` | วน `eduvision:queue-work` ทุกราวครึ่งนาที และ `eduvision:purge-images` คืนละครั้ง แทน Scheduled Tasks ของ Plesk |
| `eduvision-tunnel` | Cloudflare quick tunnel มีเฉพาะตอนเปิดสาธารณะ (§5) ไม่อยู่ใน Compose |

สคริปต์ใน `tools/selfhost/`:

| สคริปต์ | รันที่ไหน | ทำอะไร |
|---|---|---|
| `deploy.sh` | server | อัปเดตเป็นโค้ดล่าสุด (§3) |
| `push-web.sh <url>` | เครื่องผู้พัฒนา | build เว็บแอปแล้วส่งขึ้น server (§4) |
| `tunnel.sh up\|url\|down` | server | เปิด ดู หรือปิดที่อยู่สาธารณะ (§5) |
| `set-url.sh <url>` | server | ตั้งที่อยู่สาธารณะใน `app.env` แล้ว restart (§5) |
| `install.sh <อีเมล admin>` | server | ติดตั้งครั้งแรก (§6) |
| `set-env.sh` | server | ตั้งค่า `KEY=VALUE` จาก stdin ลง `app.env` แล้ว restart (§8) |
| `compose.sh ...` | server | `docker compose` ของชุดนี้ ใช้แทนการพิมพ์ `docker compose` เอง (§8) |

## 3. อัปเดตเป็นโค้ดล่าสุด

งานที่ทำบ่อยที่สุด โค้ดที่จะ deploy ต้องอยู่บน `origin/main` แล้ว

```bash
ssh phuwish115 '~/eduvision/repo/tools/selfhost/deploy.sh'
```

สคริปต์ทำตามลำดับ: `git pull --ff-only` → build image → restart container → `migrate --force` → พิมพ์ผล health บรรทัดสุดท้ายต้องเป็น `deployed <commit>: {"status":"ok","db":"ok",...}`

- image ถูก build ก่อน restart ถ้า build ล้ม ระบบเดิมยังรันอยู่
- ระหว่าง restart เว็บหยุดไม่กี่วินาที
- ถ้า commit ที่ deploy แก้โค้ดใน `app/` ให้ทำ §4 ต่อ
- ถ้า `backend/.env.example` มีตัวแปรใหม่ที่ต้องตั้งค่า ให้เพิ่มด้วย `set-env.sh` (§8) ตัวแปรที่ไม่ตั้งจะใช้ค่าตั้งต้นในโค้ด
- ที่อยู่สาธารณะไม่เปลี่ยนเมื่อ deploy เพราะ tunnel เป็น container แยก

## 4. อัปเดตเว็บแอป

ทำเมื่อโค้ดใน `app/` เปลี่ยน หรือที่อยู่สาธารณะเปลี่ยน รันบนเครื่องผู้พัฒนา จาก commit เดียวกับที่ deploy บน server

```bash
URL=$(ssh phuwish115 '~/eduvision/repo/tools/selfhost/tunnel.sh url')
tools/selfhost/push-web.sh "$URL"
```

สคริปต์เรียก `tools/build-web.sh` (ที่อยู่ของ API ถูกฝังใน build) แล้ว `rsync` ไปที่ `~/eduvision/web/app/` บรรทัดสุดท้ายแสดง commit ของ build ที่ server เสิร์ฟอยู่ ไม่ต้อง restart container

## 5. ที่อยู่สาธารณะ

เน็ตของเครื่องอยู่หลัง NAT รวมของผู้ให้บริการ (CGNAT) จึงตั้ง port forward ไม่ได้ เจ้าของเลือกใช้ **Cloudflare quick tunnel** (9 ต.ค. 2569) ซึ่งได้ที่อยู่ `https://<คำสุ่ม>.trycloudflare.com` โดยไม่ต้องมีบัญชีหรือโดเมน

ข้อจำกัดที่ต้องบอกเจ้าของทุกครั้ง:

- ที่อยู่**เปลี่ยนทุกครั้งที่ tunnel เริ่มใหม่**
- tunnel **ไม่กลับมาเองหลังเครื่อง reboot** (ตั้งใจ เพราะที่อยู่ใหม่จะไม่ตรงกับที่ตั้งไว้ในระบบ)
- Cloudflare ไม่รับประกันการใช้งานของลิงก์แบบนี้ เหมาะกับการทดลองเท่านั้น

**เปิด (เมื่อเจ้าของสั่ง)** บน server:

```bash
cd ~/eduvision/repo/tools/selfhost
URL=$(./tunnel.sh up) && ./set-url.sh "$URL"
```

จากนั้นบนเครื่องผู้พัฒนา รัน `tools/selfhost/push-web.sh <URL>` (§4) แล้วตรวจตาม §7

`set-url.sh` พิมพ์ redirect URI สองบรรทัด ส่งให้เจ้าของเพิ่มใน Google Cloud Console เอง (HOSTING §6.3 และ §6.4) จนกว่าจะเพิ่ม การเข้าสู่ระบบด้วย Google และการเชื่อม Classroom จะได้ `redirect_uri_mismatch` ส่วน login ด้วยรหัสผ่านและ PIN ใช้ได้ทันที

**ดูที่อยู่ปัจจุบัน:** `./tunnel.sh url` (ถ้าตอบว่า tunnel ไม่ได้รัน แปลว่าตอนนี้เข้าจากข้างนอกไม่ได้)

**ปิด:** `./tunnel.sh down`

ถ้าเจ้าของต้องการชื่อที่คงที่ ต้องใช้ named tunnel กับโดเมนที่ DNS อยู่บน Cloudflare ซึ่งยังไม่ได้ทำ ให้ถามเจ้าของก่อนและแก้ไฟล์นี้กับ DESIGN §26 ใน PR เดียวกัน

## 6. ติดตั้งใหม่จากศูนย์

ใช้เมื่อเครื่องถูกลง OS ใหม่หรือย้ายเครื่อง เครื่องต้องมี Docker กับ Compose plugin, git, openssl และ curl และผู้ใช้ต้องอยู่ในกลุ่ม `docker` ถามอีเมลของ admin คนแรกจากเจ้าของ

```bash
ssh phuwish115
mkdir -p ~/eduvision && cd ~/eduvision
git clone https://github.com/phuwishpk/Teacherhelper.git repo
repo/tools/selfhost/install.sh <อีเมล admin คนแรก>
```

`install.sh` สร้าง `app.env` จาก `backend/.env.example` พร้อม key และรหัสผ่านชุดใหม่ สร้าง `.env` ของ Compose แล้ว build, start, migrate, สร้างโรงเรียนแรกและ admin คนแรก login ของ admin ถูกย้ายไปที่ `~/eduvision/admin-first-login.txt` ให้เจ้าของอ่านเอง:

```bash
ssh phuwish115 'cat ~/eduvision/admin-first-login.txt'    # เจ้าของรันเอง แล้วเปลี่ยนรหัสใน /admin และลบไฟล์นี้
```

ขั้นต่อไปหลังติดตั้ง:

1. **ค่า Google** คัดลอกจาก `backend/.env` ของเครื่องผู้พัฒนาโดยไม่แสดงค่า (รันที่ root ของ repo บนเครื่องผู้พัฒนา):

   ```bash
   grep -E '^(GOOGLE_OAUTH_CLIENT_ID|GOOGLE_OAUTH_CLIENT_SECRET|GOOGLE_SIGNIN_CLIENT_IDS|GOOGLE_SIGNIN_CLIENT_SECRET)=' backend/.env \
     | ssh phuwish115 '~/eduvision/repo/tools/selfhost/set-env.sh'
   ```

2. **ที่อยู่สาธารณะและเว็บแอป** ตาม §5 ถ้าเจ้าของต้องการ ก่อนถึงขั้นนี้ระบบเข้าได้จากในเครื่องเท่านั้น และเว็บแอปยังไม่มี

ข้อควรรู้:

- ติดตั้งใหม่ได้**ฐานข้อมูลว่างและ key ชุดใหม่** บัญชีและข้อมูลเดิมไม่กลับมา เว้นแต่กู้จากไฟล์ backup (§8) พร้อม `app.env` เดิม (ต้องใช้ `APP_KEY` เดิม)
- ถ้า volume `eduvision_db` ยังอยู่แต่ `app.env` หาย `install.sh` จะไม่ทำงาน ให้ถามเจ้าของ อย่าลบ volume เอง

## 7. ตรวจหลังทำ

บน server:

```bash
~/eduvision/repo/tools/selfhost/compose.sh ps
curl -s http://127.0.0.1:8085/api/v1/health
```

| ตรวจ | ต้องได้ |
|---|---|
| `compose.sh ps` | `eduvision-app`, `eduvision-db` (healthy) และ `eduvision-worker` เป็น Up |
| health | `"status":"ok"` และ `"db":"ok"` ค่า `queue_last_run_at` ขยับเมื่อเรียกซ้ำหลัง 1 นาที |

ถ้าเปิดสาธารณะอยู่ ตรวจจากเครื่องผู้พัฒนาด้วย (`URL` จาก `tunnel.sh url`):

```bash
curl -s "$URL/api/v1/health"
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' "$URL/"       # 302 ไป .../app
curl -s "$URL/app/build-commit.txt"                                    # commit ของเว็บแอป
curl -s -o /dev/null -w '%{http_code}\n' "$URL/.env"                   # ต้องไม่ใช่ 200
curl -s "$URL/api/v1/auth/google/config"                               # enabled และ web_flow เป็น true เมื่อตั้งค่า Google แล้ว
```

แล้วเปิด `$URL` ในเบราว์เซอร์ ต้องเห็นหน้าเข้าสู่ระบบ ถ้ามีบัญชีทดสอบให้ลอง login หนึ่งครั้ง

## 8. งานดูแล

ทุกคำสั่งรันบน server และใช้ `compose.sh` ซึ่งรับ argument เหมือน `docker compose`

```bash
cd ~/eduvision/repo/tools/selfhost

./compose.sh logs --tail 100 app                 # หรือ worker, db
./compose.sh exec -T -u www-data app php artisan migrate:status
./compose.sh stop                                # หยุดทั้งชุด ข้อมูลอยู่ครบ
./compose.sh up -d                               # เริ่มใหม่
```

- **artisan ต้องมี `-u www-data` เสมอ** ถ้ารันเป็น root ไฟล์ใน `storage` จะกลายเป็นของ root แล้วเว็บเขียนไม่ได้
- **log ของ Laravel** อยู่ใน volume: `./compose.sh exec -T app sh -c 'tail -n 50 storage/logs/laravel-$(date -u +%F).log'`
- **แก้ค่าใน `app.env`** ใช้ `set-env.sh` ซึ่ง restart ให้และไม่แสดงค่า:

  ```bash
  printf 'GEMINI_MODEL=gemini-3.8-flash\n' | ./set-env.sh
  ```

- **สำรองฐานข้อมูล** ก่อนงานที่เสี่ยง ไฟล์นี้มีข้อมูลนักเรียน เก็บไว้บนเครื่องนั้น ห้ามคัดลอกเข้า repo:

  ```bash
  ./compose.sh exec -T db sh -c 'mariadb-dump -ueduvision -p"$MARIADB_PASSWORD" --single-transaction eduvision' \
    | gzip > ~/eduvision-backup-$(date +%F).sql.gz
  ```

- **บัญชีทดสอบ** เจ้าของสร้างได้ที่ `/admin` > ผู้ใช้และครู > เพิ่มผู้ใช้ ถ้าเจ้าของสั่งให้ agent สร้าง ใช้ลำดับเดียวกับ `backend/tools/smoke.sh` (สมัครครู, อนุมัติ, สร้างห้อง, เพิ่มนักเรียน) และอย่าใส่รหัสของบัญชีเหล่านั้นใน repo

## 9. ปัญหาที่เจอบ่อย

| อาการ | สาเหตุ | แก้ |
|---|---|---|
| `EDUVISION_REPO ... run this through tools/selfhost/compose.sh` | เรียก `docker compose` ตรง ๆ | ใช้ `compose.sh` |
| `deploy.sh` หยุดที่ `git pull` | clone ไม่ได้อยู่ที่ `main` หรือมีไฟล์ถูกแก้บน server | `git -C ~/eduvision/repo status` แล้วทำให้ clone สะอาด อย่าแก้โค้ดบน server |
| `eduvision-app` restart วนซ้ำ | `app.env` หาย หรือมีบรรทัดที่อ่านไม่ได้ | `./compose.sh logs --tail 40 app` |
| health เป็น `degraded` | worker ไม่ได้รัน | `./compose.sh ps` แล้ว `./compose.sh up -d worker` |
| ลิงก์สาธารณะเปิดไม่ได้ | tunnel หยุด เช่น หลัง reboot | §5 (ได้ที่อยู่ใหม่ ต้องทำครบสามขั้น) |
| หน้าเว็บขึ้นแต่ login ไม่ได้ และ DevTools เห็นเรียก API ไปที่อยู่เก่า | เว็บแอป build ไว้สำหรับที่อยู่อื่น | §4 ด้วยที่อยู่ปัจจุบัน |
| `/app/` ตอบ 403 | โฟลเดอร์ `web/app` อ่านไม่ได้จาก container | `chmod -R a+rX ~/eduvision/web` |
| ไม่มีปุ่ม "เข้าสู่ระบบด้วย Google" | `GOOGLE_SIGNIN_CLIENT_IDS` ว่าง หรือยังไม่ได้รัน `set-url.sh` | `curl $URL/api/v1/auth/google/config` แล้วทำ §6 ข้อ 1 หรือ §5 |
| Google ตอบ `redirect_uri_mismatch` | เจ้าของยังไม่ได้เพิ่ม URI ของที่อยู่ปัจจุบัน | ส่ง URI จาก `set-url.sh` ให้เจ้าของ |
| DevTools แสดงว่า CSP บล็อก `accounts.google.com/gsi/client` | ตั้งใจ เว็บใช้ทาง redirect ผ่าน server (DESIGN §25.3) | ไม่ต้องแก้ |
