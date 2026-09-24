#!/usr/bin/env python3
"""Evaluate a Keras run or a TFLite model on the writer-held-out test set (DESIGN 12.3).

Metrics
    cer                     character error rate over all samples (abstentions count as empty)
    exact_match             fraction of samples read exactly right
    abstain_rate            fraction with confidence below the threshold (0.8, DESIGN 12.2)
    accuracy_when_answered  exact-match rate among the samples the reader did answer
    cer_when_answered       CER among answered samples

Usage:
    uv run python -m train.evaluate --data data/synth/labels.csv --split-file runs/smoke/split.json \
        --tflite models/digit_crnn/0.1.0/model.tflite --out runs/smoke/test_metrics.json
    uv run python -m train.evaluate --data ... --model runs/smoke/best.keras --part val
"""

from __future__ import annotations

import argparse
import json
import os
from collections.abc import Sequence
from pathlib import Path

import numpy as np

from train.charset import CONFIDENCE_THRESHOLD, character_error_rate, ctc_greedy_decode_batch, edit_distance
from train.dataset import (
    Sample,
    load_images,
    load_split_json,
    read_many,
    split_by_writer,
    split_from_writers,
)

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")


class TFLiteRunner:
    """Run the exported model with the TFLite interpreter bundled in TensorFlow."""

    def __init__(self, model: Path | str | bytes):
        import tensorflow as tf

        if isinstance(model, bytes):
            self.interpreter = tf.lite.Interpreter(model_content=model)
        else:
            self.interpreter = tf.lite.Interpreter(model_path=str(model))
        self.input = self.interpreter.get_input_details()[0]
        self.output = self.interpreter.get_output_details()[0]
        self.interpreter.allocate_tensors()

    def predict(self, images: np.ndarray, batch_size: int = 64) -> np.ndarray:
        out = []
        for start in range(0, len(images), batch_size):
            chunk = np.ascontiguousarray(images[start : start + batch_size], dtype=np.float32)
            if tuple(self.interpreter.get_input_details()[0]["shape"]) != chunk.shape:
                self.interpreter.resize_tensor_input(self.input["index"], chunk.shape, strict=False)
                self.interpreter.allocate_tensors()
            self.interpreter.set_tensor(self.input["index"], chunk)
            self.interpreter.invoke()
            out.append(np.array(self.interpreter.get_tensor(self.output["index"]), dtype=np.float32))
        return np.concatenate(out) if out else np.zeros((0,) + tuple(self.output["shape"][1:]), np.float32)


def evaluate_predictions(
    labels: Sequence[str], probs: np.ndarray, threshold: float = CONFIDENCE_THRESHOLD, max_examples: int = 25
) -> dict:
    decoded = ctc_greedy_decode_batch(probs)
    n = len(labels)
    if n == 0:
        raise ValueError("nothing to evaluate")
    texts = [d.text for d in decoded]
    confidences = np.array([d.confidence for d in decoded], dtype=np.float64)
    answered = confidences >= threshold
    correct = np.array([t == label for t, label in zip(texts, labels)], dtype=bool)
    n_answered = int(answered.sum())
    answered_pairs = [(label, t) for label, t, a in zip(labels, texts, answered) if a]
    by_length: dict[int, dict[str, float]] = {}
    for label, t, ok in zip(labels, texts, correct):
        bucket = by_length.setdefault(len(label), {"n": 0, "exact": 0})
        bucket["n"] += 1
        bucket["exact"] += int(ok)
    errors = sorted(
        (
            {"label": label, "read": t, "confidence": round(float(c), 4), "distance": edit_distance(label, t)}
            for label, t, c, ok in zip(labels, texts, confidences, correct)
            if not ok
        ),
        key=lambda e: -e["confidence"],
    )[:max_examples]
    return {
        "n": n,
        "threshold": threshold,
        "cer": character_error_rate(zip(labels, texts)),
        "exact_match": float(correct.mean()),
        "abstain_rate": float(1.0 - n_answered / n),
        "accuracy_when_answered": float(correct[answered].mean()) if n_answered else None,
        "cer_when_answered": character_error_rate(answered_pairs) if n_answered else None,
        "mean_confidence": float(confidences.mean()),
        "exact_match_by_length": {
            str(k): {"n": int(v["n"]), "exact_match": v["exact"] / v["n"]} for k, v in sorted(by_length.items())
        },
        "worst_confident_errors": errors,
    }


def select_part(samples: list[Sample], part: str, split_file: Path | None, seed: int, val_frac: float, test_frac: float):
    if split_file is not None:
        info = load_split_json(split_file)
        split = split_from_writers(samples, info["val_writers"], info["test_writers"])
    else:
        split = split_by_writer(samples, val_frac, test_frac, seed)
    return split.part(part)


def evaluate_samples(samples: Sequence[Sample], predictor, threshold: float = CONFIDENCE_THRESHOLD) -> dict:
    images = load_images(samples)
    probs = predictor(images)
    result = evaluate_predictions([s.label for s in samples], probs, threshold)
    result["writers"] = len({s.writer_key for s in samples})
    return result


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--data", action="append", required=True, type=Path, help="labels.csv (repeatable)")
    parser.add_argument("--part", choices=("train", "val", "test"), default="test")
    parser.add_argument("--split-file", type=Path, help="split.json of the training run (recommended)")
    parser.add_argument("--seed", type=int, default=0, help="split seed when no --split-file")
    parser.add_argument("--val-frac", type=float, default=0.10)
    parser.add_argument("--test-frac", type=float, default=0.10)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--model", type=Path, help="Keras .keras file")
    group.add_argument("--tflite", type=Path, help="model.tflite")
    parser.add_argument("--threshold", type=float, default=CONFIDENCE_THRESHOLD)
    parser.add_argument("--out", type=Path, help="write the metrics JSON here")
    args = parser.parse_args(argv)

    samples = select_part(read_many(args.data), args.part, args.split_file, args.seed, args.val_frac, args.test_frac)
    if args.tflite:
        predictor = TFLiteRunner(args.tflite).predict
    else:
        import keras

        from train.model import ctc_loss, predict_probs

        model = keras.models.load_model(args.model, custom_objects={"ctc_loss": ctc_loss}, compile=False)
        predictor = lambda images: predict_probs(model, images)  # noqa: E731
    result = evaluate_samples(samples, predictor, args.threshold)
    result["part"] = args.part
    text = json.dumps(result, indent=2, ensure_ascii=False)
    if args.out:
        args.out.parent.mkdir(parents=True, exist_ok=True)
        args.out.write_text(text + "\n", encoding="utf-8")
    print(text)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
