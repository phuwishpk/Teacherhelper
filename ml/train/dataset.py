"""``labels.csv`` loader and the writer-based split (DESIGN 12.3).

Both the synthetic output of :mod:`train.synth` and the team's real dataset
(imported by :mod:`train.import_dataset`) use the same CSV::

    path,label,writer_key
    images/shard_000/0000000.png,3.14,synth_w0007

``path`` is relative to the CSV's directory (absolute paths are accepted).
The split is **by writer**: a writer's samples are all in exactly one of
train / val / test, never spread across them (DESIGN 12.3).
"""

from __future__ import annotations

import csv
import json
from collections.abc import Iterable, Sequence
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

from train.charset import MAX_LABEL_LEN, is_valid_label, pad_labels
from train.preprocess import IMAGE_HEIGHT, IMAGE_WIDTH, normalize, to_gray

CSV_COLUMNS = ("path", "label", "writer_key")


@dataclass(frozen=True)
class Sample:
    path: Path
    label: str
    writer_key: str


def validate_row(row: dict[str, str], csv_dir: Path) -> tuple[Sample | None, str | None]:
    """Return ``(sample, None)`` or ``(None, reason)`` for one CSV row (file existence is not checked)."""
    missing = [c for c in CSV_COLUMNS if c not in row or row[c] is None]
    if missing:
        return None, f"missing column(s) {missing}"
    raw_path = row["path"].strip()
    label = row["label"].strip()
    writer_key = row["writer_key"].strip()
    if not raw_path:
        return None, "empty path"
    if not label:
        return None, "empty label"
    if not is_valid_label(label):
        return None, f"label {label!r} must be 1-{MAX_LABEL_LEN} characters from 0-9 . - /"
    if not writer_key:
        return None, "empty writer_key"
    path = Path(raw_path)
    if not path.is_absolute():
        path = csv_dir / path
    return Sample(path, label, writer_key), None


def read_labels_csv(csv_path: Path | str) -> list[Sample]:
    """Read one labels CSV; raises ``ValueError`` naming the first invalid row."""
    csv_path = Path(csv_path)
    csv_dir = csv_path.parent
    samples: list[Sample] = []
    with csv_path.open(newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        if reader.fieldnames is None or any(c not in reader.fieldnames for c in CSV_COLUMNS):
            raise ValueError(f"{csv_path}: header must contain {','.join(CSV_COLUMNS)}")
        for number, row in enumerate(reader, start=2):
            sample, reason = validate_row(row, csv_dir)
            if sample is None:
                raise ValueError(f"{csv_path}:{number}: {reason}")
            samples.append(sample)
    return samples


def read_many(csv_paths: Iterable[Path | str]) -> list[Sample]:
    samples: list[Sample] = []
    for path in csv_paths:
        samples.extend(read_labels_csv(path))
    return samples


@dataclass
class Split:
    train: list[Sample]
    val: list[Sample]
    test: list[Sample]

    def part(self, name: str) -> list[Sample]:
        return getattr(self, name)

    @staticmethod
    def writers(samples: Sequence[Sample]) -> list[str]:
        return sorted({s.writer_key for s in samples})

    def to_json(self, seed: int | None = None, val_frac: float | None = None, test_frac: float | None = None) -> dict:
        return {
            "seed": seed,
            "val_frac": val_frac,
            "test_frac": test_frac,
            "counts": {"train": len(self.train), "val": len(self.val), "test": len(self.test)},
            "train_writers": self.writers(self.train),
            "val_writers": self.writers(self.val),
            "test_writers": self.writers(self.test),
        }


def split_by_writer(
    samples: Sequence[Sample], val_frac: float = 0.10, test_frac: float = 0.10, seed: int = 0
) -> Split:
    """Deterministic writer-disjoint split, filling test then val until their sample fractions are met."""
    if not 0 <= val_frac < 1 or not 0 <= test_frac < 1 or val_frac + test_frac >= 1:
        raise ValueError("fractions must be in [0, 1) and sum to less than 1")
    by_writer: dict[str, list[Sample]] = {}
    for s in samples:
        by_writer.setdefault(s.writer_key, []).append(s)
    writers = sorted(by_writer)
    if not writers:
        return Split([], [], [])
    order = [writers[i] for i in np.random.default_rng(seed).permutation(len(writers))]
    total = len(samples)
    test_w: list[str] = []
    val_w: list[str] = []
    train_w: list[str] = []
    taken = 0
    for w in order:
        if test_frac > 0 and (not test_w or taken < test_frac * total) and len(order) - len(test_w) - len(val_w) > 1:
            test_w.append(w)
            taken += len(by_writer[w])
        elif val_frac > 0 and (not val_w or taken < (test_frac + val_frac) * total) and len(order) - len(test_w) - len(val_w) > 1:
            val_w.append(w)
            taken += len(by_writer[w])
        else:
            train_w.append(w)
    if not train_w:  # never leave train empty when there is more than one writer
        train_w.append((val_w or test_w).pop())

    def collect(keys: list[str]) -> list[Sample]:
        return [s for k in sorted(keys) for s in by_writer[k]]

    return Split(collect(train_w), collect(val_w), collect(test_w))


def split_from_writers(samples: Sequence[Sample], val_writers: Iterable[str], test_writers: Iterable[str]) -> Split:
    """Rebuild a split from the writer lists saved in ``split.json`` (unknown writers go to train)."""
    val_set, test_set = set(val_writers), set(test_writers)
    if val_set & test_set:
        raise ValueError("a writer cannot be in both val and test")
    split = Split([], [], [])
    for s in samples:
        (split.test if s.writer_key in test_set else split.val if s.writer_key in val_set else split.train).append(s)
    return split


def load_split_json(path: Path | str) -> dict:
    with Path(path).open(encoding="utf-8") as fh:
        return json.load(fh)


def load_images(samples: Sequence[Sample]) -> np.ndarray:
    """Read the PNGs with OpenCV into ``float32 (N, 32, 128, 1)`` model inputs (numpy path, no TF)."""
    batch = np.zeros((len(samples), IMAGE_HEIGHT, IMAGE_WIDTH, 1), dtype=np.float32)
    for i, s in enumerate(samples):
        image = cv2.imread(str(s.path), cv2.IMREAD_UNCHANGED)
        if image is None:
            raise FileNotFoundError(f"cannot read image {s.path}")
        gray = to_gray(image)
        if gray.shape != (IMAGE_HEIGHT, IMAGE_WIDTH):
            gray = cv2.resize(gray, (IMAGE_WIDTH, IMAGE_HEIGHT), interpolation=cv2.INTER_AREA)
        batch[i] = normalize(gray)
    return batch


def make_dataset(samples: Sequence[Sample], batch_size: int, shuffle: bool = False, seed: int = 0):
    """``tf.data`` pipeline yielding ``(image float32 (B,32,128,1), label int32 (B, MAX_LABEL_LEN))``."""
    import tensorflow as tf  # lazy: plain `uv sync` has no TensorFlow

    paths = tf.constant([str(s.path) for s in samples])
    labels = tf.constant(pad_labels([s.label for s in samples]))

    def load(path, label):
        image = tf.io.decode_png(tf.io.read_file(path), channels=1)
        image = tf.image.resize(image, [IMAGE_HEIGHT, IMAGE_WIDTH])  # identity for canvas-sized PNGs
        image = 1.0 - tf.cast(image, tf.float32) / 255.0
        return tf.ensure_shape(image, [IMAGE_HEIGHT, IMAGE_WIDTH, 1]), label

    ds = tf.data.Dataset.from_tensor_slices((paths, labels))
    if shuffle:
        ds = ds.shuffle(min(len(samples), 50_000), seed=seed, reshuffle_each_iteration=True)
    ds = ds.map(load, num_parallel_calls=tf.data.AUTOTUNE)
    return ds.batch(batch_size).prefetch(tf.data.AUTOTUNE)
