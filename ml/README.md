# ml/ — เครื่องมือ ML และโค้ดอ้างอิงของ EduVision

จัดการด้วย [uv](https://docs.astral.sh/uv/) และ Python 3.12 (TensorFlow ยังไม่รองรับ 3.13+)

```bash
cd ml
uv sync                    # ติดตั้ง dependency หลัก + pytest (ArUco, fuzzy, synth, dataset, import)
uv sync --extra train      # (Phase 5) เพิ่ม TensorFlow สำหรับเทรน/export CRNN
uv sync --extra notebook   # (Phase 6) pyBKT + pandas + Jupyter สำหรับ notebooks/bkt_vs_ewma
uv run pytest              # รัน test ทั้งหมด (test ที่ต้องใช้ TensorFlow จะ skip เองถ้าไม่ได้ติดตั้ง)
```

> **Apple Silicon:** เทรนบน CPU เท่านั้นโดยตั้งใจ `tensorflow-metal` รุ่นล่าสุด (1.2.0) รองรับแค่ TensorFlow 2.18
> แต่โปรเจกต์ใช้ 2.21 (`import tensorflow` จะพังที่ `libmetal_plugin.dylib` ถ้าติดตั้งคู่กัน)
> โมเดลมีแค่ ~0.44 M พารามิเตอร์ บน M-series CPU ใช้เวลา ~30–40 วินาที/epoch ที่ 16 000 ภาพ จึงพอสำหรับงานนี้
> ถ้าวันหนึ่ง tensorflow-metal รองรับ 2.21 ค่อยเพิ่มลงใน extra `train` แล้ว `uv lock`

## มีอะไรในนี้

| โฟลเดอร์ | หน้าที่ | ใช้โดย |
|---|---|---|
| `tools/gen_aruco.py` | สร้าง ArUco marker `DICT_4X4_50` id 0–3 เป็น PNG 600 px (มี quiet zone) พร้อม `manifest.json` ลงใน `backend/resources/worksheet/aruco/` | backend ตอนวาดใบงาน PDF (DESIGN §5.2) |
| `tools/sync_notebook.py` | แปลง notebook แบบ percent-format `.py` ↔ `.ipynb` (ไม่ต้องใช้ jupytext) และ `--check` ว่าตรงกัน | `notebooks/`, test |
| `fuzzy/` | implementation อ้างอิงของ Fuzzy Logic ตาม DESIGN §11 (membership แบบ linear, AND = min, defuzzify แบบ weighted average) ทั้งระบบที่ 1 (ให้คะแนน `show_work` / `short` / `open`, ปรนัย) และระบบที่ 2 (ลำดับให้ครูตรวจ) | รายงานวิชา AI และเป็น golden reference ให้ `FuzzyEngine` ฝั่ง Laravel ต้องให้ผลตรงกัน |
| `fuzzy/plot_membership.py` | วาดกราฟ membership และ surface ของกฎ ลง `fuzzy/plots/*.png` | สไลด์/รายงาน |
| `train/` | Phase 5: CRNN + CTC อ่านตัวเลขลายมือ (DESIGN §12) — ชุดข้อมูลสังเคราะห์, loader, โมเดล, เทรน, ประเมิน, export TFLite, นำเข้า dataset ของทีม | แอป (โมเดลบนมือถือ), backend (`model_versions`) |
| `models/digit_crnn/<version>/` | ผลลัพธ์ export: `metrics.json` + `model.tflite.sha256` (commit) และ `model.tflite` (gitignore, สร้างใหม่ได้จาก run) | backend อ่าน `metrics.json` เข้าตาราง `model_versions` (§8.6, §9.8) |
| `notebooks/` | `bkt_vs_ewma.py` / `.ipynb` เทียบ BKT กับ EWMA สำหรับรายงานวิชา AI (DESIGN §14.4) | รายงาน |
| `tests/` | ตัวอย่างใน DESIGN §11.3 (0.538 / 0.525), §11.8 (0.32), คุณสมบัติ monotone/strictness, round-trip ของ ArUco, CTC decode + นิยาม confidence, การแบ่งตามคนเขียน, รูปแบบ label สังเคราะห์, การแปลง TFLite, sha256/สัญญา/`created_at` ของโมเดลที่ export (export ซ้ำต้องได้ไฟล์เดิม), notebook twin | CI ภายหลัง |

```bash
uv run python tools/gen_aruco.py            # สร้าง marker ใหม่ (ค่าเริ่มต้นเขียนลง backend/)
uv run python -m fuzzy.plot_membership      # วาดกราฟลง fuzzy/plots/
```

## Phase 5: CRNN อ่านตัวเลข (`train/`)

โมเดลเป็น **ผู้อ่านคนที่สอง** ของกรอบคำตอบตัวเลข (`0–9 . - /`) ผลของมันเป็นค่า `D` ของ fuzzy ระบบที่ 2 เท่านั้น ไม่ได้ใช้ให้คะแนน (§12.1)

### pipeline ทั้งหมด (รันจากโฟลเดอร์ `ml/` หลัง `uv sync --extra train`)

```bash
# 1) ชุดข้อมูลสังเคราะห์: ตัวเลขจาก MNIST (ดาวน์โหลดครั้งเดียวไว้ที่ ~/.keras/datasets) + สัญลักษณ์ . - / วาดเป็นเส้น
#    สตริงยาว 1–6 ตัว (จำนวนเต็ม / ทศนิยม / ติดลบ / เศษส่วน) augment แล้วเขียนเป็น PNG 32x128 + labels.csv
uv run python -m train.synth --out data/synth --n 24000 --writers 60 --seed 1
#    (--source emnist ต้อง uv run --with tensorflow-datasets ... ดาวน์โหลด ~550 MB)

# 2) เทรน: แบ่ง train/val/test "ตามคนเขียน" (writer_key) เขียน split.json, history.csv, best.keras, summary.json
uv run python -m train.train --data data/synth/labels.csv --out runs/smoke --epochs 12 --subset 16000 --patience 4
#    ตัวเลือก: --rnn conv1d (fallback ถ้า BiLSTM แปลง TFLite ไม่ได้), --batch-size, --lr, --seed, --val-frac, --test-frac

# 3) export: float16 TFLite (builtin ops เท่านั้น, ต้อง <= 2 MB) + metrics.json + sha256 ลง models/digit_crnn/<version>/
uv run python -m train.export --run runs/smoke --version 0.1.0 --data data/synth/labels.csv

# 4) ประเมินซ้ำ (test ที่กันคนเขียนไว้ หรือ --part val) ด้วย TFLite หรือ .keras
uv run python -m train.evaluate --data data/synth/labels.csv --split-file runs/smoke/split.json \
    --tflite models/digit_crnn/0.1.0/model.tflite --out runs/smoke/test_metrics.json

# 5) นำเข้า dataset จริงของทีม (ใบเก็บข้อมูล §12.3 ข้อ 2 หรือ export จาก training_samples) แล้วเทรนต่อรวมกับชุดสังเคราะห์
uv run python -m train.import_dataset --csv ~/team/labels.csv --name team2026 --out data/real   # --dry-run = ตรวจอย่างเดียว
uv run python -m train.train --data data/synth/labels.csv --data data/real/labels.csv --out runs/v1 --epochs 40
```

รูปแบบ CSV เดียวกันทุกชุด: `path,label,writer_key` (`path` สัมพัทธ์กับไฟล์ CSV, `label` 1–12 ตัวจาก `0-9 . - /`,
`writer_key` = คนเขียน) ตัวนำเข้าจะ tight-crop + ปรับเป็น 32×128 ให้ และเติม `<name>:` หน้า `writer_key` กันชนกันระหว่างชุด

### สัญญากับแอป (อยู่ใน `metrics.json` ของทุกเวอร์ชัน)

- **input** `float32 (1, 32, 128, 1)`: crop ของกรอบ → `tight_crop` (Otsu + margin 10 % ของความสูงหมึก) → ย่อให้สูง 32 คงอัตราส่วน,
  pad ขวาด้วยสีกระดาษ (255) ให้กว้าง 128 (กว้างเกินให้บีบ) → `x = 1 - gray/255` (หมึก = 1) ดู `train/preprocess.py`
- **output** `float32 (1, 32, 14)` softmax: class 0–12 = `0123456789.-/`, class 13 = CTC blank
- **decode** greedy CTC: argmax ทุก timestep, ยุบตัวซ้ำ, ตัด blank; `confidence` (`decode.confidence` = `emitting_mean_max_prob`) =
  ค่าเฉลี่ยของ max probability **เฉพาะ timestep ที่ปล่อยตัวอักษรออกมา** (argmax ≠ blank และ ≠ argmax ของ timestep ก่อนหน้า
  คือ timestep แรกของแต่ละตัวที่ decode ได้) ถ้าไม่ได้ตัวอักษรเลย confidence = 0.0; ต่ำกว่า `decode.abstain_below` = ไม่ตอบ
  โค้ด Dart ต้องให้ผลตรงกับ `train/charset.py::ctc_greedy_decode` และต้อง **อ่าน `decode.abstain_below` จาก `metrics.json`**
  (ค่าเริ่มต้น 0.8 ตาม §12.2 แต่ §12.2 ให้จูนจาก validation set — เวอร์ชันถัดไปอาจ export ด้วย `--threshold` ค่าอื่น) ไม่ hard-code
  - ทำไมนับเฉพาะ timestep ที่ปล่อยตัวอักษร: ~26 จาก 32 timestep เป็น blank ที่โมเดลมั่นใจ ~1.0 ถ้าเฉลี่ยทั้ง 32 timestep
    confidence จะไม่ต่ำกว่า 0.8 เลย (0.1.0: ไม่ตอบ 0 % ทุก threshold ถึง 0.9, ข้อที่อ่านผิดมี confidence 0.998+) กฎ "ไม่ตอบ" ของ
    §12.2 จึงไม่ทำงาน การเฉลี่ย "ตามเส้นทางที่ decode ได้" ตามถ้อยคำ §12.2 จึงหมายถึงตัวอักษรที่ decode ออกมาจริง
  - ข้อจำกัด: confidence แบบนี้มองไม่เห็นตัวที่ **หายไป** (เช่น `3.5` → `35` ทั้งที่ทุกตัวที่อ่านได้มั่นใจ 0.999) จึงเป็นเหตุผลที่ผลของ CNN
    ใช้แค่เทียบกับ Gemini (ค่า `D` §11.7) ไม่ใช่ให้คะแนน
- แอปตรวจ `sha256` หลังดาวน์โหลด (§9.8) ค่าอยู่ใน `model.tflite.sha256` และ `metrics.json`

### เวอร์ชันที่ export แล้ว

| version | ข้อมูล | test (กันคนเขียน 6 คน, 2 400 ภาพ) | ขนาด | หมายเหตุ |
|---|---|---|---|---|
| 0.1.0 | สังเคราะห์ MNIST 16 000 ภาพ / 48 คนเขียน, 12 epoch (smoke ~8 นาที CPU) | CER 5.9 %, exact 81.8 %, abstain 3.3 % ที่ 0.8, ถูก 82.9 % เมื่อตอบ | 1.29 MB | ใช้ทดสอบ pipeline/แอปเท่านั้น ยังไม่ผ่านลายมือจริง |

`metrics.json` มี `val_metrics.threshold_sweep` (abstain rate และความแม่นเมื่อตอบ ที่ threshold 0.5–0.995 บน validation) และ
`recommended_threshold` = ค่าต่ำสุดที่ทำให้ตอบถูก ≥ 95 % ของที่ตอบ — 0.1.0 บน validation: ที่ 0.8 ไม่ตอบ 3.5 % / ถูก 87.3 % เมื่อตอบ,
ที่ 0.9 ไม่ตอบ 14.8 % / ถูก 90.0 %, `recommended_threshold` = 0.98 (ไม่ตอบ 44.9 % / ถูก 95.2 %) โมเดลสังเคราะห์ตัวนี้ยังไม่แม่นพอ
ที่จะใช้ค่านั้น จึง export ด้วยค่าเริ่มต้น 0.8 ตาม §12.2 เมื่อมี dataset จริงให้เลือก threshold จาก sweep แล้ว export ด้วย
`--threshold <ค่า>` ค่านั้นจะไปอยู่ใน `decode.abstain_below` ให้แอปอ่านเอง (ไม่ต้องแก้แอป) และแก้ DESIGN §12.2 ให้ตรงกันใน PR เดียว

**ลงทะเบียนโมเดลกับ backend:** เพิ่มแถวใน `model_versions` ด้วย `name`, `version`, `file_path` (ที่เก็บ `model.tflite` นอก document root),
`sha256` และ `metrics` = เนื้อหา `metrics.json` แล้วตั้ง `is_active` — แอปดึงจาก `GET /api/v1/ml/models/active?name=digit_crnn`

สิ่งที่ **ไม่ commit** (`.gitignore`): `data/`, `runs/`, `*.keras`, `*.tflite` — สร้างใหม่ได้จากคำสั่งข้างบนด้วย seed เดิม
การ export ซ้ำจาก run เดิมได้ไฟล์ที่ commit **เหมือนเดิมทุกไบต์**: การแปลง TFLite deterministic (sha256 เดิม) และ `created_at`
ใน `metrics.json` มาจาก `finished_at` ใน `summary.json` ของ run (run เก่าที่ไม่มีค่านี้ใช้ mtime ของ `best.keras`) หรือ `--created-at`
ไม่ใช่เวลาที่กด export — เวลาที่ลงทะเบียนจริงเก็บที่ `model_versions.created_at` ฝั่ง backend

## Phase 6: BKT เทียบ EWMA (`notebooks/`)

```bash
uv sync --extra notebook
uv run python notebooks/bkt_vs_ewma.py                       # ฉบับสคริปต์ (headless) ผลลง notebooks/out/
uv run jupyter notebook notebooks/bkt_vs_ewma.ipynb          # ฉบับ Jupyter เนื้อหาเดียวกัน
uv run jupyter nbconvert --to notebook --execute notebooks/bkt_vs_ewma.ipynb --output out/executed.ipynb
uv run python tools/sync_notebook.py notebooks/bkt_vs_ewma.py --check   # แก้ .py แล้วต้องรันแบบไม่มี --check เพื่อสร้าง .ipynb ใหม่
```

- ไม่มี export จริง → จำลอง observation ด้วยกระบวนการ BKT (ผลใช้ทดสอบ pipeline เท่านั้น) มี `skill_observations.csv`
  จาก admin แล้ววางที่ `data/skill_observations.csv` หรือชี้ด้วย `EDUVISION_OBS_CSV=...`
- แบ่งนักเรียน 80/20 แล้ววัด AUC/RMSE ของการทำนายครั้งถัดไป: BKT (pyBKT) vs EWMA ของแอป (§14.2) vs baseline
- `.py` เป็นต้นฉบับ `.ipynb` สร้างจากมันโดย `tools/sync_notebook.py` (commit แบบไม่มี output; test บังคับให้ตรงกัน)
- pyBKT 1.4.3 เก็บ log-likelihood เป็น array `(1,1)` ซึ่ง NumPy ≥ 2.4 ไม่ยอมใส่ลงช่อง scalar อีกแล้ว และการเปิด multiprocessing
  pool จากสคริปต์ที่ไม่มี `__main__` guard บน macOS จะ re-import notebook ทั้งไฟล์ในโปรเซสลูก — notebook จึง patch
  `pyBKT.fit.EM_fit.run` ให้คืน float และใช้ `Model(parallel=False)` (fit 6 ทักษะ × 3 ครั้งใช้เวลา ~10 วินาที)
