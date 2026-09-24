# ml/ — เครื่องมือ ML และโค้ดอ้างอิงของ EduVision

จัดการด้วย [uv](https://docs.astral.sh/uv/) และ Python 3.12 (TensorFlow ยังไม่รองรับ 3.13+)

```bash
cd ml
uv sync                 # ติดตั้ง dependency หลัก + pytest
uv run pytest           # รัน test ทั้งหมด
uv sync --extra train   # (Phase 5) เพิ่ม TensorFlow + tensorflow-metal สำหรับเทรน CRNN
```

## มีอะไรในนี้

| โฟลเดอร์ | หน้าที่ | ใช้โดย |
|---|---|---|
| `tools/gen_aruco.py` | สร้าง ArUco marker `DICT_4X4_50` id 0–3 เป็น PNG 600 px (มี quiet zone) พร้อม `manifest.json` ลงใน `backend/resources/worksheet/aruco/` | backend ตอนวาดใบงาน PDF (DESIGN §5.2) |
| `fuzzy/` | implementation อ้างอิงของ Fuzzy Logic ตาม DESIGN §11 (membership แบบ linear, AND = min, defuzzify แบบ weighted average) ทั้งระบบที่ 1 (ให้คะแนน `show_work` / `short` / `open`, ปรนัย) และระบบที่ 2 (ลำดับให้ครูตรวจ) | รายงานวิชา AI และเป็น golden reference ให้ `FuzzyEngine` ฝั่ง Laravel ต้องให้ผลตรงกัน |
| `fuzzy/plot_membership.py` | วาดกราฟ membership และ surface ของกฎ ลง `fuzzy/plots/*.png` | สไลด์/รายงาน |
| `tests/` | ตัวอย่างใน DESIGN §11.3 (0.538 / 0.525), §11.8 (0.32), คุณสมบัติ monotone/strictness, และ round-trip ของ ArUco ผ่าน OpenCV | CI ภายหลัง |

```bash
uv run python tools/gen_aruco.py            # สร้าง marker ใหม่ (ค่าเริ่มต้นเขียนลง backend/)
uv run python -m fuzzy.plot_membership      # วาดกราฟลง fuzzy/plots/
```

## Phase 5 (ยังไม่ทำ)

โมเดล CRNN + CTC อ่านตัวเลขลายมือ (`0–9 . - /`) จะอยู่ใน `train/` และ `notebooks/` ตาม DESIGN §12
เทรนบน Apple Silicon ด้วย `tensorflow-metal` แล้ว export เป็น TFLite ให้แอปดาวน์โหลดจาก server
ข้อมูลเทรน: ชุดสังเคราะห์จาก MNIST/EMNIST ก่อน แล้วเทรนซ้ำด้วย dataset ของทีม (รูปแบบ `path,label,writer_key` แบ่ง train/test ตามคนเขียน)
