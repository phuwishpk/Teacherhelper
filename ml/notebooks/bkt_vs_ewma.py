# %% [markdown]
# # BKT เทียบกับ EWMA: ทำนายผลครั้งถัดไปของนักเรียน (DESIGN §14.4)
#
# ในแอป EduVision ระดับความเข้าใจ (mastery) ใช้ **EWMA** ตาม §14.2 เพราะอธิบายให้ครูเข้าใจง่าย
# notebook นี้ทำเพื่อรายงานวิชา AI: เทียบว่า **Bayesian Knowledge Tracing (BKT)** ทำนาย "ครั้งถัดไปจะถูกไหม"
# ได้แม่นกว่า EWMA แค่ไหน วัดด้วย AUC และ RMSE บนนักเรียนที่กันไว้ (hold-out by student)
#
# **ข้อมูล**
# - ถ้ามีไฟล์ export ของตาราง `skill_observations` (§8.5) จาก admin ไว้ที่ `ml/data/skill_observations.csv`
#   (หรือชี้ด้วยตัวแปรแวดล้อม `EDUVISION_OBS_CSV`) จะใช้ข้อมูลจริง
# - ถ้ายังไม่มี จะ **จำลอง** observation ด้วยกระบวนการ BKT ที่มีความต่างระหว่างนักเรียน (ดู cell ถัดไป)
#   เพื่อให้ pipeline ทั้งหมดรันได้ก่อนมีข้อมูลจริง (ผลจากข้อมูลจำลองใช้ทดสอบ pipeline เท่านั้น ห้ามใส่ในรายงานว่าเป็นผลจริง)
#
# ไฟล์นี้มี 2 ฉบับที่เนื้อหาตรงกัน: `bkt_vs_ewma.ipynb` (เปิดด้วย Jupyter) และ `bkt_vs_ewma.py` (รันด้วย
# `uv run python notebooks/bkt_vs_ewma.py` จากโฟลเดอร์ `ml/`) ต้องติดตั้งด้วย `uv sync --extra notebook` ก่อน

# %%
from __future__ import annotations

import json
import os
from pathlib import Path

import matplotlib
import numpy as np
import pandas as pd
from sklearn.metrics import roc_auc_score

if not os.environ.get("MPLBACKEND") and "get_ipython" not in globals():
    matplotlib.use("Agg")  # the .py twin runs headless; Jupyter keeps its inline backend
import matplotlib.pyplot as plt

ML_DIR = Path.cwd() if (Path.cwd() / "pyproject.toml").exists() else Path.cwd().parent
DATA_PATH = Path(os.environ.get("EDUVISION_OBS_CSV", ML_DIR / "data" / "skill_observations.csv"))
OUT_DIR = Path(os.environ.get("EDUVISION_NOTEBOOK_OUT", ML_DIR / "notebooks" / "out"))
OUT_DIR.mkdir(parents=True, exist_ok=True)
SEED = 20260924
ALPHA = {"homework": 0.30, "practice": 0.15}  # DESIGN 14.2
CORRECT_THRESHOLD = 0.5  # DESIGN 14.4: score_ratio >= 0.5 counts as correct
print("ml dir:", ML_DIR, "| data:", DATA_PATH if DATA_PATH.exists() else "(simulated)")

# %% [markdown]
# ## 1. โหลด export จริง หรือจำลอง observation
#
# ข้อมูลจำลอง: นักเรียน 240 คน × ทักษะ 6 ทักษะ แต่ละคู่มี 6–18 observation
# แต่ละทักษะมีพารามิเตอร์ BKT ของตัวเอง (prior, learn, guess, slip) และนักเรียนแต่ละคนมี "ability"
# ที่ขยับ guess/slip เล็กน้อย เพื่อไม่ให้ข้อมูลเข้าข้าง BKT จนเกินไป คะแนน `score_ratio` สุ่มจากถูก/ผิด
# (ถูก → 0.5–1.0, ผิด → 0.0–0.5) และ `source` เป็น homework 70% / practice 30%


# %%
def simulate_observations(n_students: int = 240, n_skills: int = 6, seed: int = SEED) -> pd.DataFrame:
    rng = np.random.default_rng(seed)
    skills = pd.DataFrame(
        {
            "skill_id": np.arange(1, n_skills + 1),
            "prior": rng.uniform(0.15, 0.55, n_skills),
            "learn": rng.uniform(0.06, 0.28, n_skills),
            "guess": rng.uniform(0.10, 0.30, n_skills),
            "slip": rng.uniform(0.05, 0.20, n_skills),
        }
    )
    ability = rng.normal(0.0, 0.06, n_students + 1)  # per-student shift of guess/slip
    rows = []
    t0 = pd.Timestamp("2026-06-01T01:00:00Z")
    for student in range(1, n_students + 1):
        for skill in skills.itertuples(index=False):
            learned = rng.random() < skill.prior
            guess = float(np.clip(skill.guess + ability[student], 0.02, 0.6))
            slip = float(np.clip(skill.slip - ability[student], 0.02, 0.5))
            for step in range(int(rng.integers(6, 19))):
                p_correct = (1.0 - slip) if learned else guess
                correct = rng.random() < p_correct
                score = rng.uniform(0.5, 1.0) if correct else rng.uniform(0.0, 0.5)
                rows.append(
                    {
                        "student_id": student,
                        "skill_id": int(skill.skill_id),
                        "source": "homework" if rng.random() < 0.7 else "practice",
                        "score_ratio": round(float(score), 3),
                        "observed_at": t0 + pd.Timedelta(days=step * 3 + int(skill.skill_id), hours=int(student % 7)),
                    }
                )
                if not learned and rng.random() < skill.learn:
                    learned = True
    df = pd.DataFrame(rows)
    df.attrs["true_params"] = skills
    return df


if DATA_PATH.exists():
    obs = pd.read_csv(DATA_PATH, parse_dates=["observed_at"])
    obs = obs[["student_id", "skill_id", "source", "score_ratio", "observed_at"]]
    DATA_SOURCE = f"export {DATA_PATH.name}"
else:
    obs = simulate_observations()
    DATA_SOURCE = "simulated"
obs = obs.sort_values(["student_id", "skill_id", "observed_at"], kind="stable").reset_index(drop=True)
obs["correct"] = (obs["score_ratio"] >= CORRECT_THRESHOLD).astype(int)
obs["order"] = np.arange(len(obs))
obs["skill"] = "skill_" + obs["skill_id"].astype(str)  # pyBKT wants a name
print(DATA_SOURCE, "|", len(obs), "observations,", obs.student_id.nunique(), "students,", obs.skill_id.nunique(), "skills")
print("correct rate:", round(obs.correct.mean(), 3), "| by source:", obs.groupby("source").correct.mean().round(3).to_dict())
obs.head()

# %% [markdown]
# ## 2. แบ่งนักเรียนเป็น train / test
#
# แบ่ง **ตามนักเรียน** (80/20) ไม่ใช่ตามแถว เพราะจะวัดว่าโมเดลที่ fit จากนักเรียนกลุ่มหนึ่ง
# ทำนายนักเรียนใหม่ได้แค่ไหน ทั้ง BKT และ EWMA ทำนายผล observation ที่ t จาก observation ก่อนหน้า
# ของนักเรียนคนเดียวกันในทักษะเดียวกันเท่านั้น (one-step-ahead)

# %%
students = np.sort(obs.student_id.unique())
test_students = set(np.random.default_rng(SEED).permutation(students)[: max(1, len(students) // 5)])
train = obs[~obs.student_id.isin(test_students)].copy()
test = obs[obs.student_id.isin(test_students)].copy()
print(f"train: {train.student_id.nunique()} students / {len(train)} rows; test: {test.student_id.nunique()} students / {len(test)} rows")

# %% [markdown]
# ## 3. EWMA (สูตรของแอป, §14.2)
#
# `m₁ = s₁`, `mₜ = αₜ·sₜ + (1 − αₜ)·mₜ₋₁` โดย α = 0.30 (การบ้าน) หรือ 0.15 (แบบฝึกซ่อม)
# คำทำนายสำหรับ observation ที่ t คือ `mₜ₋₁` (mastery ก่อนเห็นผลครั้งนั้น) ครั้งแรกของคู่ (นักเรียน, ทักษะ)
# ยังไม่มี m จึงใช้อัตราตอบถูกเฉลี่ยของทักษะนั้นใน train เป็น prior


# %%
def ewma_predictions(df: pd.DataFrame, prior_by_skill: dict[int, float]) -> np.ndarray:
    preds = np.empty(len(df))
    for _, group in df.groupby(["student_id", "skill_id"], sort=False):
        m = prior_by_skill.get(int(group.skill_id.iloc[0]), 0.5)
        for i, (idx, row) in enumerate(group.iterrows()):
            preds[df.index.get_loc(idx)] = m
            s = row.score_ratio
            m = s if i == 0 else ALPHA[row.source] * s + (1 - ALPHA[row.source]) * m
    return preds


skill_prior = train.groupby("skill_id").correct.mean().to_dict()
test = test.reset_index(drop=True)
test["p_ewma"] = ewma_predictions(test, skill_prior)
test["p_baseline"] = test.skill_id.map(skill_prior)  # always predict the skill's train mean
test[["student_id", "skill_id", "source", "score_ratio", "correct", "p_ewma"]].head(8)

# %% [markdown]
# ## 4. BKT ด้วย pyBKT
#
# fit พารามิเตอร์ (prior, learn, guess, slip) ต่อทักษะจากนักเรียนกลุ่ม train แล้ว `predict` บนกลุ่ม test
# ค่า `correct_predictions` ของ pyBKT คือ P(ถูก) แบบ one-step-ahead จาก observation ก่อนหน้าของนักเรียนคนนั้น

# %%
from pyBKT.models import Model  # noqa: E402

BKT_COLUMNS = {"user_id": "student_id", "skill_name": "skill", "correct": "correct", "order_id": "order"}
bkt = Model(seed=SEED, num_fits=3)
bkt.fit(data=train, defaults=BKT_COLUMNS)
fitted = bkt.params().reset_index()
fitted_table = fitted.pivot_table(index="skill", columns="param", values="value", aggfunc="first")[["prior", "learns", "guesses", "slips"]]
if "true_params" in obs.attrs:
    truth = obs.attrs["true_params"].assign(skill="skill_" + obs.attrs["true_params"].skill_id.astype(str)).set_index("skill")
    fitted_table = fitted_table.join(truth[["prior", "learn", "guess", "slip"]].add_prefix("true_"))
print(fitted_table.round(3))
bkt_pred = bkt.predict(data=test, defaults=BKT_COLUMNS)
test["p_bkt"] = bkt_pred["correct_predictions"].to_numpy()

# %% [markdown]
# ## 5. เปรียบเทียบ AUC และ RMSE
#
# - **AUC** วัดว่าเรียงลำดับ "ใครน่าจะถูก" ได้ดีแค่ไหน (0.5 = เดาสุ่ม)
# - **RMSE** วัดว่าค่าความน่าจะเป็นที่ให้ตรงกับผลจริงแค่ไหน (ต่ำกว่าดีกว่า)
# - baseline = ทำนายด้วยอัตราตอบถูกเฉลี่ยของทักษะเสมอ


# %%
def score(name: str, p: np.ndarray, y: np.ndarray) -> dict:
    p = np.clip(np.asarray(p, dtype=float), 0.0, 1.0)
    return {"model": name, "auc": roc_auc_score(y, p), "rmse": float(np.sqrt(np.mean((p - y) ** 2))), "n": int(len(y))}


y_test = test.correct.to_numpy()
results = pd.DataFrame([score("BKT", test.p_bkt, y_test), score("EWMA", test.p_ewma, y_test), score("baseline", test.p_baseline, y_test)])
results["first_obs_excluded_auc"] = [
    roc_auc_score(y_test[mask], test[col][mask]) for col in ("p_bkt", "p_ewma", "p_baseline") for mask in [test.groupby(["student_id", "skill_id"]).cumcount().to_numpy() > 0]
]
print(results.round(4).to_string(index=False))
results.round(4).assign(data=DATA_SOURCE).to_json(OUT_DIR / "bkt_vs_ewma.json", orient="records", indent=2)

# %%
COLORS = {"BKT": "#2a78d6", "EWMA": "#eb6834", "baseline": "#1baf7a"}  # fixed categorical order
fig, axes = plt.subplots(1, 2, figsize=(8, 3.2))
for ax, metric, better in zip(axes, ("auc", "rmse"), ("higher is better", "lower is better")):
    bars = ax.bar(results.model, results[metric], width=0.55, color=[COLORS[m] for m in results.model])
    ax.bar_label(bars, fmt="%.3f", padding=3, fontsize=9, color="#333")
    ax.set_title(f"{metric.upper()} ({better})", fontsize=10, loc="left")
    ax.set_ylim(0, max(1.0 if metric == "auc" else 0.6, results[metric].max() * 1.15))
    ax.spines[["top", "right"]].set_visible(False)
    ax.grid(axis="y", color="#e5e5e5", linewidth=0.8)
    ax.set_axisbelow(True)
    ax.tick_params(length=0)
fig.suptitle(f"ทำนายผลครั้งถัดไป (test students, {DATA_SOURCE})", fontsize=11, x=0.01, ha="left")
fig.tight_layout()
fig.savefig(OUT_DIR / "bkt_vs_ewma.png", dpi=150)
plt.show()

# %% [markdown]
# ## 6. Calibration: ค่าที่ทำนายตรงกับสัดส่วนที่ถูกจริงไหม
#
# จับกลุ่มค่าทำนายเป็น 10 ช่วง แล้วดูว่าในแต่ละช่วงนักเรียนตอบถูกจริงกี่เปอร์เซ็นต์ เส้นทแยงคือสมบูรณ์แบบ

# %%
fig, ax = plt.subplots(figsize=(4.2, 4))
bins = np.linspace(0, 1, 11)
for name, col in (("BKT", "p_bkt"), ("EWMA", "p_ewma")):
    cut = pd.cut(test[col].clip(0, 1), bins, include_lowest=True)
    grouped = test.groupby(cut, observed=True).agg(pred=(col, "mean"), actual=("correct", "mean"), n=("correct", "size"))
    grouped = grouped[grouped.n >= 20]
    ax.plot(grouped.pred, grouped.actual, marker="o", markersize=5, linewidth=2, color=COLORS[name], label=name)
ax.plot([0, 1], [0, 1], color="#bbb", linewidth=1, linestyle="--")
ax.set_xlabel("ค่าที่ทำนาย P(ถูก)")
ax.set_ylabel("สัดส่วนที่ถูกจริง")
ax.set_xlim(0, 1)
ax.set_ylim(0, 1)
ax.spines[["top", "right"]].set_visible(False)
ax.legend(frameon=False)
fig.tight_layout()
fig.savefig(OUT_DIR / "calibration.png", dpi=150)
plt.show()

# %% [markdown]
# ## 7. สรุปสำหรับรายงาน
#
# - ตาราง `results` ด้านบนคือตัวเลขที่นำไปใส่รายงาน (คอลัมน์ `first_obs_excluded_auc` ตัด observation แรกของแต่ละคู่
#   ออก เพราะทั้งสองวิธียังไม่มีข้อมูลของนักเรียนคนนั้นเลย จึงเป็นการวัด "การเรียนรู้จากประวัติ" ล้วน ๆ)
# - ในแอปยังใช้ EWMA ต่อไปตาม DESIGN §14.4: ครูเข้าใจง่าย คำนวณใหม่ได้ทันทีเมื่อคะแนนเปลี่ยน และไม่ต้อง fit
#   พารามิเตอร์ต่อทักษะ ผลของ notebook นี้ใช้เฉพาะในรายงานวิชา AI
# - เมื่อมี export จริง (`skill_observations.csv`) ให้รันซ้ำ ผลจะเขียนไว้ที่ `notebooks/out/bkt_vs_ewma.json`
#   และรูปที่ `notebooks/out/*.png`
print(json.dumps(results.round(4).to_dict(orient="records"), ensure_ascii=False, indent=1))
