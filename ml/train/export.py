#!/usr/bin/env python3
"""Export a training run to float16 TFLite with metrics and sha256 (DESIGN 12.2, 8.6, 9.8).

Writes ``models/<name>/<version>/``::

    model.tflite          float16 weights, builtin ops only, <= 2 MB (gitignored)
    model.tflite.sha256   "<sha256>  model.tflite" (the app checks it after download, 9.8)
    metrics.json          version, sha256, size, charset/blank/input/output/decode contract,
                          test-set metrics from the TFLite model, training summary (committed;
                          the backend imports it into model_versions.metrics)

Usage:
    uv run python -m train.export --run runs/smoke --version 0.1.0 --data data/synth/labels.csv
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from collections.abc import Sequence
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from train.charset import BLANK, CHARSET, CONFIDENCE_THRESHOLD, NUM_CLASSES
from train.dataset import load_images, load_split_json, read_many, split_from_writers
from train.evaluate import TFLiteRunner, evaluate_samples
from train.model import TIMESTEPS
from train.preprocess import DEFAULT_MARGIN, IMAGE_HEIGHT, IMAGE_WIDTH

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

MODEL_NAME = "digit_crnn"
MAX_TFLITE_BYTES = 2 * 1024 * 1024
DEFAULT_MODELS_DIR = Path(__file__).resolve().parents[1] / "models"


def convert_to_tflite(model, float16: bool = True) -> bytes:
    """Keras model -> TFLite flatbuffer using builtin ops only (no Flex delegate in the app)."""
    import tensorflow as tf

    converter = tf.lite.TFLiteConverter.from_keras_model(model)
    converter.target_spec.supported_ops = [tf.lite.OpsSet.TFLITE_BUILTINS]
    if float16:
        converter.optimizations = [tf.lite.Optimize.DEFAULT]
        converter.target_spec.supported_types = [tf.float16]
    return converter.convert()


def sha256_bytes(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(Path(path).read_bytes())


def verify_tflite(blob: bytes, model, images: np.ndarray) -> float:
    """Max abs difference between the TFLite and Keras probabilities on ``images``."""
    from train.model import predict_probs

    lite = TFLiteRunner(blob).predict(images)
    ref = predict_probs(model, images)
    if lite.shape != ref.shape:
        raise ValueError(f"TFLite output {lite.shape} != Keras output {ref.shape}")
    return float(np.abs(lite - ref).max())


def contract() -> dict:
    """The part of metrics.json the app relies on (mirrors train.preprocess and train.charset)."""
    return {
        "charset": CHARSET,
        "blank_index": BLANK,
        "num_classes": NUM_CLASSES,
        "timesteps": TIMESTEPS,
        "input": {
            "shape": [1, IMAGE_HEIGHT, IMAGE_WIDTH, 1],
            "dtype": "float32",
            "preprocess": [
                f"tight-crop the grayscale frame to the ink bounding box (Otsu) + margin {DEFAULT_MARGIN} x height",
                f"resize to height {IMAGE_HEIGHT} keeping the aspect ratio; squash to width {IMAGE_WIDTH} if wider",
                f"pad on the right with paper (255) to width {IMAGE_WIDTH}",
                "x = 1 - gray / 255 (ink = 1.0, paper = 0.0)",
            ],
        },
        "output": {"shape": [1, TIMESTEPS, NUM_CLASSES], "dtype": "float32", "activation": "softmax"},
        "decode": {
            "method": "ctc_greedy",
            "steps": "argmax per timestep, collapse repeats, drop blank",
            "confidence": "mean over timesteps of the max probability",
            "abstain_below": CONFIDENCE_THRESHOLD,
        },
    }


def export(
    run_dir: Path,
    version: str,
    data: Sequence[Path],
    models_dir: Path = DEFAULT_MODELS_DIR,
    name: str = MODEL_NAME,
    threshold: float = CONFIDENCE_THRESHOLD,
    allow_large: bool = False,
    float16: bool = True,
) -> Path:
    import keras
    import tensorflow as tf

    from train.model import ctc_loss

    run_dir = Path(run_dir)
    model_path = run_dir / "best.keras"
    model = keras.models.load_model(model_path, custom_objects={"ctc_loss": ctc_loss}, compile=False)
    split_info = load_split_json(run_dir / "split.json")
    split = split_from_writers(read_many(data), split_info["val_writers"], split_info["test_writers"])
    if not split.test:
        raise SystemExit("the split has no test writers; export needs a writer-held-out test set")

    blob = convert_to_tflite(model, float16)
    if len(blob) > MAX_TFLITE_BYTES and not allow_large:
        raise SystemExit(
            f"model.tflite is {len(blob) / 1e6:.2f} MB > 2 MB (DESIGN 12.2); retrain with --rnn conv1d or pass --allow-large"
        )
    max_diff = verify_tflite(blob, model, load_images(split.test[:64]))
    runner = TFLiteRunner(blob)
    metrics = evaluate_samples(split.test, runner.predict, threshold)
    val_metrics = evaluate_samples(split.val, runner.predict, threshold) if split.val else None

    out_dir = Path(models_dir) / name / version
    out_dir.mkdir(parents=True, exist_ok=True)
    tflite_path = out_dir / "model.tflite"
    tflite_path.write_bytes(blob)
    digest = sha256_bytes(blob)
    (out_dir / "model.tflite.sha256").write_text(f"{digest}  model.tflite\n", encoding="utf-8")

    summary = json.loads((run_dir / "summary.json").read_text(encoding="utf-8")) if (run_dir / "summary.json").exists() else {}
    record = {
        "name": name,
        "version": version,
        "file": "model.tflite",
        "sha256": digest,
        "size_bytes": len(blob),
        "created_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "tensorflow": tf.__version__,
        "keras": keras.__version__,
        "quantization": "float16" if float16 else "none",
        "tflite_vs_keras_max_abs_diff": round(max_diff, 6),
        **contract(),
        "metrics": {
            "cer": metrics["cer"],
            "exact_match": metrics["exact_match"],
            "abstain_rate": metrics["abstain_rate"],
            "accuracy_when_answered": metrics["accuracy_when_answered"],
            "cer_when_answered": metrics["cer_when_answered"],
            "threshold": threshold,
            "n_test": metrics["n"],
            "test_writers": metrics["writers"],
            "exact_match_by_length": metrics["exact_match_by_length"],
        },
        "val_metrics": (
            {k: val_metrics[k] for k in ("cer", "exact_match", "abstain_rate", "accuracy_when_answered", "n")}
            if val_metrics
            else None
        ),
        "training": {
            "run": run_dir.name,
            "rnn": summary.get("rnn"),
            "params": summary.get("params", int(model.count_params())),
            "epochs_run": summary.get("epochs_run"),
            "best_epoch": summary.get("best_epoch"),
            "best_val_loss": summary.get("best_val_loss"),
            "train_samples": summary.get("train_samples"),
            "train_seconds": summary.get("train_seconds"),
            "data": [Path(p).as_posix() for p in data],
            "split_seed": split_info.get("seed"),
            "train_writers": len(split_info.get("train_writers", [])),
            "val_writers": len(split_info.get("val_writers", [])),
            "test_writers": len(split_info.get("test_writers", [])),
        },
        "worst_confident_errors": metrics["worst_confident_errors"][:10],
    }
    (out_dir / "metrics.json").write_text(json.dumps(record, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({k: record[k] for k in ("version", "sha256", "size_bytes", "metrics")}, indent=2))
    print(f"exported -> {out_dir}")
    return out_dir


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--run", type=Path, required=True, help="training run directory (with best.keras, split.json)")
    parser.add_argument("--version", required=True, help="model version, e.g. 0.1.0 (model_versions.version)")
    parser.add_argument("--data", action="append", required=True, type=Path, help="labels.csv used for training")
    parser.add_argument("--models-dir", type=Path, default=DEFAULT_MODELS_DIR)
    parser.add_argument("--name", default=MODEL_NAME)
    parser.add_argument("--threshold", type=float, default=CONFIDENCE_THRESHOLD)
    parser.add_argument("--allow-large", action="store_true", help="export even if larger than 2 MB")
    parser.add_argument("--no-float16", action="store_true")
    args = parser.parse_args(argv)
    export(args.run, args.version, args.data, args.models_dir, args.name, args.threshold, args.allow_large, not args.no_float16)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
