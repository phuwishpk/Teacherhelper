#!/usr/bin/env python3
"""Synthetic handwriting dataset for the digit reader (DESIGN 12.3, item 1).

Digits come from MNIST (or EMNIST digits), the symbols ``. - /`` are drawn
programmatically as strokes with random size, thickness and angle. Strings of
1-6 characters are composed in four formats (``digits``, ``decimal``,
``negative``, ``fraction``), then augmented: spacing, stroke width, slant,
rotation, perspective, blur, paper/ink tone, noise, speckles, stray frame lines
and contrast. Each sample is fitted to the 32 x 128 canvas of
:mod:`train.preprocess` and written as a grayscale PNG (white paper, dark ink).

A *synthetic writer* is a fixed style (stroke, slant, spacing, ...) plus a
private set of glyph exemplars per digit, disjoint from every other writer, so
the writer-based split of :mod:`train.dataset` really holds handwriting out.

Output layout (``--out ml/data/synth``)::

    labels.csv                       path,label,writer_key
    images/shard_000/0000000.png     32 x 128 grayscale
    images/shard_001/...

Usage:
    uv run python -m train.synth --out data/synth --n 24000 --writers 60 --seed 1
    uv run --with tensorflow-datasets python -m train.synth --source emnist ...
"""

from __future__ import annotations

import argparse
import csv
import math
import sys
import urllib.request
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

from train.charset import is_valid_label
from train.preprocess import IMAGE_HEIGHT, IMAGE_WIDTH, PAPER, fit_to_canvas

MNIST_URL = "https://storage.googleapis.com/tensorflow/tf-keras-datasets/mnist.npz"
MNIST_CACHE = Path.home() / ".keras" / "datasets" / "mnist.npz"

FORMATS: tuple[str, ...] = ("digits", "decimal", "negative", "fraction")
DEFAULT_FORMAT_WEIGHTS: dict[str, float] = {"digits": 0.40, "decimal": 0.25, "negative": 0.20, "fraction": 0.15}
MAX_LEN = 6
LINE = 64  # composition line height in px; downsampled to 32 at the end
WRITER_PREFIX = "synth"
CSV_HEADER = ("path", "label", "writer_key")


# --------------------------------------------------------------------------- glyph banks


@dataclass
class GlyphBank:
    """Digit images ``(N, H, W) uint8`` with bright ink on black (MNIST convention) and labels 0-9."""

    images: np.ndarray
    labels: np.ndarray

    def __post_init__(self) -> None:
        self.images = np.asarray(self.images, dtype=np.uint8)
        self.labels = np.asarray(self.labels).astype(np.int64)
        if self.images.ndim != 3 or len(self.images) != len(self.labels):
            raise ValueError("GlyphBank needs images (N, H, W) and N labels")
        if len(self.labels) and (self.labels.min() < 0 or self.labels.max() > 9):
            raise ValueError("GlyphBank labels must be digits 0-9")
        self._by_digit = {d: np.flatnonzero(self.labels == d) for d in range(10)}
        missing = [d for d, idx in self._by_digit.items() if len(idx) == 0]
        if missing:
            raise ValueError(f"GlyphBank has no exemplars for digits {missing}")

    def indices_of(self, digit: int) -> np.ndarray:
        return self._by_digit[digit]


def load_mnist(path: Path = MNIST_CACHE, download: bool = True) -> GlyphBank:
    """MNIST train + test (70 000 glyphs) from the Keras cache, downloaded once if absent."""
    path = Path(path)
    if not path.exists():
        if not download:
            raise FileNotFoundError(f"{path} not found (pass download=True or --mnist-path)")
        path.parent.mkdir(parents=True, exist_ok=True)
        print(f"downloading MNIST to {path} ...", file=sys.stderr)
        urllib.request.urlretrieve(MNIST_URL, path)  # noqa: S310 - fixed https URL
    with np.load(path) as data:
        images = np.concatenate([data["x_train"], data["x_test"]])
        labels = np.concatenate([data["y_train"], data["y_test"]])
    return GlyphBank(images, labels)


def load_emnist(split: str = "digits") -> GlyphBank:
    """EMNIST via ``tensorflow_datasets`` (optional, ~550 MB download; images are stored transposed)."""
    try:
        import tensorflow_datasets as tfds
    except ImportError as exc:  # pragma: no cover - optional dependency
        raise SystemExit("EMNIST needs tensorflow-datasets: uv run --with tensorflow-datasets ...") from exc
    ds = tfds.load(f"emnist/{split}", split="train+test", as_supervised=True, batch_size=-1)
    images, labels = tfds.as_numpy(ds)
    images = np.transpose(images[..., 0], (0, 2, 1))
    return GlyphBank(images, labels)


def load_glyphs(source: str, mnist_path: Path = MNIST_CACHE) -> GlyphBank:
    if source == "mnist":
        return load_mnist(mnist_path)
    if source == "emnist":
        return load_emnist()
    raise ValueError(f"unknown glyph source {source!r}")


# --------------------------------------------------------------------------- writers


@dataclass(frozen=True)
class WriterStyle:
    key: str
    exemplars: dict[int, np.ndarray]  # digit -> indices into the glyph bank, private to this writer
    stroke: int  # morphological delta in px at LINE scale (-1 thin ... 2 thick)
    slant: float  # shear factor (x shift per px of height), negative = backslant
    scale: float  # digit height as a fraction of LINE
    scale_jitter: float
    spacing: float  # gap between glyphs as a fraction of LINE
    spacing_jitter: float
    baseline_jitter: float  # fraction of LINE
    symbol_thickness: float  # stroke width of . - / as a fraction of LINE
    dot_size: float  # diameter of "." as a fraction of LINE
    dash_width: float  # width of "-" as a fraction of LINE
    slash_angle: float  # degrees from vertical for "/"
    ink_darkness: float  # 1.0 = black pen, lower = pencil


def make_writers(
    bank: GlyphBank,
    n_writers: int,
    rng: np.random.Generator,
    exemplars_per_digit: int = 40,
    prefix: str = WRITER_PREFIX,
) -> list[WriterStyle]:
    """Create ``n_writers`` styles, giving each a disjoint slice of the bank per digit."""
    if n_writers < 1:
        raise ValueError("n_writers must be >= 1")
    per_digit: dict[int, list[np.ndarray]] = {}
    for digit in range(10):
        pool = rng.permutation(bank.indices_of(digit))
        need = n_writers * exemplars_per_digit
        if len(pool) < need:  # small banks (tests): recycle exemplars, styles still differ
            pool = np.resize(pool, need)
        per_digit[digit] = [pool[w * exemplars_per_digit : (w + 1) * exemplars_per_digit] for w in range(n_writers)]
    writers = []
    for w in range(n_writers):
        writers.append(
            WriterStyle(
                key=f"{prefix}_w{w:04d}",
                exemplars={d: per_digit[d][w] for d in range(10)},
                stroke=int(rng.choice([-1, 0, 0, 1, 1, 2])),
                slant=float(rng.uniform(-0.15, 0.35)),
                scale=float(rng.uniform(0.55, 0.92)),
                scale_jitter=float(rng.uniform(0.02, 0.12)),
                spacing=float(rng.uniform(-0.04, 0.30)),
                spacing_jitter=float(rng.uniform(0.02, 0.10)),
                baseline_jitter=float(rng.uniform(0.0, 0.10)),
                symbol_thickness=float(rng.uniform(0.045, 0.11)),
                dot_size=float(rng.uniform(0.07, 0.16)),
                dash_width=float(rng.uniform(0.28, 0.65)),
                slash_angle=float(rng.uniform(18.0, 40.0)),
                ink_darkness=float(rng.uniform(0.55, 1.0)),
            )
        )
    return writers


# --------------------------------------------------------------------------- labels


def _digits(rng: np.random.Generator, length: int) -> str:
    if length == 1:
        return str(rng.integers(0, 10))
    first = str(rng.integers(1, 10))
    return first + "".join(str(d) for d in rng.integers(0, 10, size=length - 1))


def _decimal(rng: np.random.Generator, max_len: int) -> str:
    int_len = int(rng.integers(1, min(3, max_len - 2) + 1))
    frac_len = int(rng.integers(1, min(3, max_len - int_len - 1) + 1))
    int_part = "0" if int_len == 1 and rng.random() < 0.35 else _digits(rng, int_len)
    frac_part = "".join(str(d) for d in rng.integers(0, 10, size=frac_len))
    return f"{int_part}.{frac_part}"


def random_label(rng: np.random.Generator, fmt: str, max_len: int = MAX_LEN) -> str:
    """One label of the given format, at most ``max_len`` characters."""
    if fmt == "digits":
        length = int(rng.choice(range(1, min(6, max_len) + 1), p=_length_weights(min(6, max_len))))
        return _digits(rng, length)
    if fmt == "decimal":
        return _decimal(rng, max_len)
    if fmt == "negative":
        if rng.random() < 0.6:
            length = int(rng.integers(1, min(4, max_len - 1) + 1))
            return "-" + _digits(rng, length)
        return "-" + _decimal(rng, max_len - 1)
    if fmt == "fraction":
        sign = "-" if rng.random() < 0.15 else ""
        budget = max_len - len(sign) - 1
        num_len = int(rng.integers(1, min(2, budget - 1) + 1))
        den_len = int(rng.integers(1, min(2, budget - num_len) + 1))
        numerator = str(rng.integers(1, 10)) if num_len == 1 else _digits(rng, num_len)
        denominator = str(rng.integers(2, 10)) if den_len == 1 else _digits(rng, den_len)
        return f"{sign}{numerator}/{denominator}"
    raise ValueError(f"unknown format {fmt!r}")


def _length_weights(max_len: int) -> np.ndarray:
    weights = np.array([0.30, 0.30, 0.20, 0.10, 0.06, 0.04][:max_len], dtype=np.float64)
    return weights / weights.sum()


# --------------------------------------------------------------------------- rendering (ink domain)


def _tight(ink: np.ndarray, threshold: int = 40) -> np.ndarray:
    ys, xs = np.nonzero(ink > threshold)
    if len(ys) == 0:
        return ink
    return ink[ys.min() : ys.max() + 1, xs.min() : xs.max() + 1]


def _digit_glyph(bank: GlyphBank, writer: WriterStyle, digit: int, rng: np.random.Generator) -> np.ndarray:
    index = int(rng.choice(writer.exemplars[digit]))
    glyph = _tight(bank.images[index])
    target_h = max(8, int(round(LINE * writer.scale * (1.0 + rng.normal(0.0, writer.scale_jitter)))))
    target_w = max(3, int(round(glyph.shape[1] * target_h / glyph.shape[0] * rng.uniform(0.85, 1.15))))
    return cv2.resize(glyph, (target_w, target_h), interpolation=cv2.INTER_LINEAR)


def _symbol_glyph(ch: str, writer: WriterStyle, rng: np.random.Generator) -> tuple[np.ndarray, str]:
    """Return ``(ink, anchor)``: anchor ``line`` means the glyph is a full LINE-high strip placed as is."""
    thickness = max(1, int(round(LINE * writer.symbol_thickness * rng.uniform(0.8, 1.25))))
    if ch == ".":
        diameter = max(2, int(round(LINE * writer.dot_size * rng.uniform(0.8, 1.3))))
        w = max(diameter + 2, int(LINE * 0.2))
        canvas = np.zeros((LINE, w), np.uint8)
        cy = int(LINE * 0.86 - diameter / 2 + rng.normal(0, LINE * 0.02))
        axes = (max(1, diameter // 2), max(1, int(diameter / 2 * rng.uniform(0.8, 1.2))))
        cv2.ellipse(canvas, (w // 2, cy), axes, float(rng.uniform(-30, 30)), 0, 360, 255, -1)
        return canvas, "line"
    if ch == "-":
        w = max(6, int(round(LINE * writer.dash_width * rng.uniform(0.8, 1.2))))
        canvas = np.zeros((LINE, w), np.uint8)
        y = int(LINE * rng.uniform(0.40, 0.58))
        dy = int(rng.normal(0, LINE * 0.03))
        cv2.line(canvas, (2, y), (w - 3, y + dy), 255, thickness, cv2.LINE_AA)
        return canvas, "line"
    if ch == "/":
        h = int(LINE * rng.uniform(0.6, 0.9))
        dx = int(math.tan(math.radians(writer.slash_angle * rng.uniform(0.8, 1.2))) * h)
        w = dx + thickness + 4
        canvas = np.zeros((LINE, w), np.uint8)
        top = int(LINE * 0.88 - h)
        cv2.line(canvas, (2, top + h), (2 + dx, top), 255, thickness, cv2.LINE_AA)
        return canvas, "line"
    raise ValueError(f"not a symbol: {ch!r}")


def compose(label: str, writer: WriterStyle, bank: GlyphBank, rng: np.random.Generator) -> np.ndarray:
    """Lay the glyphs of ``label`` on one line; returns bright ink on black, height LINE."""
    if not is_valid_label(label):
        raise ValueError(f"invalid label {label!r}")
    glyphs: list[tuple[np.ndarray, int]] = []  # (ink, top y)
    baseline = LINE * 0.88
    for ch in label:
        if ch.isdigit():
            g = _digit_glyph(bank, writer, int(ch), rng)
            top = int(round(baseline - g.shape[0] + rng.normal(0, writer.baseline_jitter * LINE)))
            top = int(np.clip(top, 0, LINE - g.shape[0]))
            glyphs.append((g, top))
        else:
            g, _ = _symbol_glyph(ch, writer, rng)
            glyphs.append((g, 0))
    total_w = sum(g.shape[1] for g, _ in glyphs)
    canvas_w = int(total_w + LINE * (abs(writer.spacing) + writer.spacing_jitter + 0.6) * (len(label) + 1))
    canvas = np.zeros((LINE, canvas_w), np.uint8)
    x = int(LINE * 0.15)
    for g, top in glyphs:
        h, w = g.shape
        region = canvas[top : top + h, x : x + w]
        np.maximum(region, g[: region.shape[0], : region.shape[1]], out=region)
        gap = LINE * (writer.spacing + rng.normal(0, writer.spacing_jitter))
        x = int(max(x + 2, x + w + gap))
    return canvas


def augment(ink: np.ndarray, writer: WriterStyle, rng: np.random.Generator) -> np.ndarray:
    """Ink strip -> photographed paper strip (uint8, white paper, dark ink), still at LINE scale."""
    h, w = ink.shape
    # stroke width
    delta = writer.stroke + int(rng.integers(-1, 2))
    if delta > 0:
        kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (delta + 1, delta + 1))
        ink = cv2.dilate(ink, kernel)
    elif delta < 0:
        eroded = cv2.erode(ink, np.ones((2, 2), np.uint8))
        if eroded.max() > 128:
            ink = eroded
    # slant (shear) on a widened canvas so nothing is cut off
    slant = writer.slant + rng.normal(0, 0.04)
    extra = int(abs(slant) * h) + 4
    padded = cv2.copyMakeBorder(ink, 2, 2, extra, extra, cv2.BORDER_CONSTANT, value=0)
    ph, pw = padded.shape
    shear = np.float32([[1.0, slant, -slant * ph if slant > 0 else 0.0], [0.0, 1.0, 0.0]])
    sheared = cv2.warpAffine(padded, shear, (pw, ph), flags=cv2.INTER_LINEAR, borderValue=0)
    # rotation + mild perspective
    angle = rng.normal(0, 2.5)
    rot = cv2.getRotationMatrix2D((pw / 2, ph / 2), angle, 1.0)
    rotated = cv2.warpAffine(sheared, rot, (pw, ph), flags=cv2.INTER_LINEAR, borderValue=0)
    jitter = 0.05
    src = np.float32([[0, 0], [pw, 0], [pw, ph], [0, ph]])
    dst = src + np.float32(rng.uniform(-jitter, jitter, size=(4, 2))) * np.float32([pw, ph])
    warped = cv2.warpPerspective(rotated, cv2.getPerspectiveTransform(src, dst), (pw, ph), borderValue=0)
    # blur (pen bleed / focus)
    if rng.random() < 0.7:
        sigma = float(rng.uniform(0.3, 1.6))
        warped = cv2.GaussianBlur(warped, (0, 0), sigma)
    # tight crop with random margins
    strip = _tight(warped, threshold=24)
    mh = strip.shape[0]
    top, bottom = (int(rng.uniform(0.02, 0.18) * mh) for _ in range(2))
    left, right = (int(rng.uniform(0.02, 0.25) * mh) for _ in range(2))
    strip = cv2.copyMakeBorder(strip, top, bottom, left, right, cv2.BORDER_CONSTANT, value=0)
    sh, sw = strip.shape
    # paper and ink tones
    alpha = strip.astype(np.float32) / 255.0 * float(np.clip(writer.ink_darkness * rng.uniform(0.85, 1.05), 0.3, 1.0))
    paper_level = rng.uniform(195, 255)
    ramp = np.linspace(-1, 1, sw, dtype=np.float32)[None, :] * rng.uniform(-18, 18)
    ramp = ramp + np.linspace(-1, 1, sh, dtype=np.float32)[:, None] * rng.uniform(-10, 10)
    paper = np.clip(paper_level + ramp, 150, 255)
    ink_level = rng.uniform(0, 70)
    image = paper * (1.0 - alpha) + ink_level * alpha
    # noise, speckles, stray frame line
    image = image + rng.normal(0, rng.uniform(1.0, 10.0), size=image.shape).astype(np.float32)
    if rng.random() < 0.3 and sh >= 1 and sw >= 1:
        n = int(rng.integers(1, 12))
        for _ in range(n):
            cx, cy = int(rng.integers(0, sw)), int(rng.integers(0, sh))
            cv2.circle(image, (cx, cy), int(rng.integers(0, 2)), float(rng.uniform(40, 160)), -1)
    if rng.random() < 0.15 and sh >= 2 and sw >= 2:
        gray = float(rng.uniform(40, 150))
        if rng.random() < 0.5:
            band = max(1, sh // 8)
            y = int(rng.integers(0, band)) if rng.random() < 0.5 else int(rng.integers(sh - band, sh))
            cv2.line(image, (0, y), (sw - 1, y), gray, 1)
        else:
            band = max(1, sw // 10)
            x = int(rng.integers(0, band)) if rng.random() < 0.5 else int(rng.integers(sw - band, sw))
            cv2.line(image, (x, 0), (x, sh - 1), gray, 1)
    # contrast / brightness
    image = image * rng.uniform(0.8, 1.2) + rng.uniform(-15, 15)
    return np.clip(image, 0, 255).astype(np.uint8)


def render_sample(label: str, writer: WriterStyle, bank: GlyphBank, rng: np.random.Generator) -> np.ndarray:
    """Label -> ``uint8 (32, 128)`` paper image ready for training."""
    strip = augment(compose(label, writer, bank, rng), writer, rng)
    return fit_to_canvas(strip, IMAGE_HEIGHT, IMAGE_WIDTH, PAPER)


# --------------------------------------------------------------------------- dataset writer


def generate(
    out_dir: Path,
    n_samples: int,
    n_writers: int,
    seed: int,
    bank: GlyphBank,
    exemplars_per_digit: int = 40,
    shard_size: int = 1000,
    formats: Sequence[str] = FORMATS,
    format_weights: dict[str, float] | None = None,
    max_len: int = MAX_LEN,
) -> Path:
    """Write ``n_samples`` PNGs + ``labels.csv`` under ``out_dir``; returns the CSV path."""
    out_dir = Path(out_dir)
    rng = np.random.default_rng(seed)
    writers = make_writers(bank, n_writers, rng, exemplars_per_digit)
    weights = np.array([(format_weights or DEFAULT_FORMAT_WEIGHTS)[f] for f in formats], dtype=np.float64)
    weights /= weights.sum()
    images_dir = out_dir / "images"
    images_dir.mkdir(parents=True, exist_ok=True)
    csv_path = out_dir / "labels.csv"
    with csv_path.open("w", newline="", encoding="utf-8") as fh:
        writer_csv = csv.writer(fh)
        writer_csv.writerow(CSV_HEADER)
        for i in range(n_samples):
            style = writers[i % n_writers]
            fmt = str(formats[int(rng.choice(len(formats), p=weights))])
            label = random_label(rng, fmt, max_len)
            image = render_sample(label, style, bank, rng)
            shard = images_dir / f"shard_{i // shard_size:03d}"
            shard.mkdir(exist_ok=True)
            rel = Path("images") / shard.name / f"{i:07d}.png"
            if not cv2.imwrite(str(out_dir / rel), image):
                raise OSError(f"could not write {out_dir / rel}")
            writer_csv.writerow([rel.as_posix(), label, style.key])
            if (i + 1) % 5000 == 0:
                print(f"  {i + 1}/{n_samples}", file=sys.stderr)
    return csv_path


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--out", type=Path, default=Path("data/synth"), help="output directory (default data/synth)")
    parser.add_argument("--n", type=int, default=24000, help="number of samples")
    parser.add_argument("--writers", type=int, default=60, help="number of synthetic writers")
    parser.add_argument("--seed", type=int, default=1)
    parser.add_argument("--source", choices=("mnist", "emnist"), default="mnist")
    parser.add_argument("--mnist-path", type=Path, default=MNIST_CACHE, help="mnist.npz (downloaded if absent)")
    parser.add_argument("--exemplars", type=int, default=40, help="glyphs per digit per writer")
    parser.add_argument("--shard-size", type=int, default=1000)
    parser.add_argument("--max-len", type=int, default=MAX_LEN)
    args = parser.parse_args(argv)
    bank = load_glyphs(args.source, args.mnist_path)
    csv_path = generate(
        args.out, args.n, args.writers, args.seed, bank, args.exemplars, args.shard_size, max_len=args.max_len
    )
    print(f"wrote {args.n} samples from {args.writers} writers -> {csv_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
