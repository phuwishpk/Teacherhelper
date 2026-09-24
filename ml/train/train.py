#!/usr/bin/env python3
"""Train the CRNN digit reader into ``runs/<name>/`` (DESIGN 12.2, 12.3).

Writes into the run directory
    split.json        writer lists of train/val/test (reuse with --split-file in evaluate/export)
    train_config.json the CLI arguments
    history.csv       per-epoch loss, val_loss, val_cer, val_exact (CSVLogger)
    best.keras        weights of the best val_loss epoch (ModelCheckpoint)
    summary.json      epochs run, best epoch and its metrics, timing

Usage:
    uv run python -m train.train --data data/synth/labels.csv --out runs/smoke --epochs 8 --subset 12000
    uv run python -m train.train --data data/synth/labels.csv --data data/real/labels.csv --out runs/v1 --epochs 40
"""

from __future__ import annotations

import argparse
import json
import os
import time
from collections.abc import Sequence
from dataclasses import asdict, dataclass
from pathlib import Path

import numpy as np

from train.charset import pad_labels
from train.dataset import Sample, load_images, make_dataset, read_many, split_by_writer
from train.model import RNN_CHOICES, build_crnn, compile_model, make_sequence_metrics_callback

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")


@dataclass
class TrainConfig:
    data: list[str]
    out: str
    epochs: int = 30
    subset: int | None = None
    batch_size: int = 64
    learning_rate: float = 1e-3
    rnn: str = "lstm"
    rnn_units: int = 64
    seed: int = 0
    patience: int = 5
    val_frac: float = 0.10
    test_frac: float = 0.10
    val_cap: int = 2000


def _subset(samples: list[Sample], n: int | None, seed: int) -> list[Sample]:
    if n is None or n >= len(samples):
        return samples
    order = np.random.default_rng(seed).permutation(len(samples))[:n]
    return [samples[i] for i in sorted(order)]


def run(config: TrainConfig) -> dict:
    import keras

    keras.utils.set_random_seed(config.seed)
    out = Path(config.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "train_config.json").write_text(json.dumps(asdict(config), indent=2) + "\n", encoding="utf-8")

    samples = read_many(config.data)
    split = split_by_writer(samples, config.val_frac, config.test_frac, config.seed)
    (out / "split.json").write_text(
        json.dumps(split.to_json(config.seed, config.val_frac, config.test_frac), indent=2) + "\n", encoding="utf-8"
    )
    train_samples = _subset(split.train, config.subset, config.seed)
    if not train_samples or not split.val:
        raise SystemExit("need at least one train writer and one val writer (more writers or smaller fractions)")
    print(
        f"samples: train {len(train_samples)} (of {len(split.train)}), val {len(split.val)}, test {len(split.test)}; "
        f"writers: {len(split.writers(split.train))}/{len(split.writers(split.val))}/{len(split.writers(split.test))}"
    )

    train_ds = make_dataset(train_samples, config.batch_size, shuffle=True, seed=config.seed)
    val_ds = make_dataset(split.val, config.batch_size)
    val_metric_samples = _subset(split.val, config.val_cap, config.seed)
    val_images = load_images(val_metric_samples)
    val_labels = pad_labels([s.label for s in val_metric_samples])

    model = compile_model(build_crnn(config.rnn, config.rnn_units), config.learning_rate)
    model.summary(line_length=100)
    callbacks = [
        make_sequence_metrics_callback(val_images, val_labels),
        keras.callbacks.CSVLogger(str(out / "history.csv")),
        keras.callbacks.ModelCheckpoint(str(out / "best.keras"), monitor="val_loss", save_best_only=True),
        keras.callbacks.EarlyStopping(monitor="val_loss", patience=config.patience, restore_best_weights=True),
        keras.callbacks.ReduceLROnPlateau(monitor="val_loss", factor=0.5, patience=max(1, config.patience // 2)),
    ]
    started = time.time()
    history = model.fit(train_ds, validation_data=val_ds, epochs=config.epochs, callbacks=callbacks, verbose=2)
    seconds = time.time() - started

    losses = history.history.get("val_loss", [])
    best_epoch = int(np.argmin(losses)) + 1 if losses else 0
    summary = {
        "run": out.name,
        "rnn": config.rnn,
        "params": int(model.count_params()),
        "epochs_run": len(losses),
        "best_epoch": best_epoch,
        "best_val_loss": float(min(losses)) if losses else None,
        "best_val_cer": float(history.history["val_cer"][best_epoch - 1]) if losses else None,
        "best_val_exact": float(history.history["val_exact"][best_epoch - 1]) if losses else None,
        "train_samples": len(train_samples),
        "val_samples": len(split.val),
        "test_samples": len(split.test),
        "train_seconds": round(seconds, 1),
        "data": [str(p) for p in config.data],
    }
    (out / "summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    if not (out / "best.keras").exists():
        model.save(out / "best.keras")
    print(json.dumps(summary, indent=2))
    return summary


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--data", action="append", required=True, help="labels.csv (repeatable)")
    parser.add_argument("--out", required=True, help="run directory, e.g. runs/smoke")
    parser.add_argument("--epochs", type=int, default=30)
    parser.add_argument("--subset", type=int, help="use only N training samples (smoke runs)")
    parser.add_argument("--batch-size", type=int, default=64)
    parser.add_argument("--lr", dest="learning_rate", type=float, default=1e-3)
    parser.add_argument("--rnn", choices=RNN_CHOICES, default="lstm")
    parser.add_argument("--rnn-units", type=int, default=64)
    parser.add_argument("--seed", type=int, default=0)
    parser.add_argument("--patience", type=int, default=5)
    parser.add_argument("--val-frac", type=float, default=0.10)
    parser.add_argument("--test-frac", type=float, default=0.10)
    parser.add_argument("--val-cap", type=int, default=2000, help="val samples used for per-epoch CER")
    args = parser.parse_args(argv)
    run(TrainConfig(**vars(args)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
