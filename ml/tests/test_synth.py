"""Synthetic generator: label formats, PNG/CSV output, writer keys (DESIGN 12.3)."""

import csv
import re
from pathlib import Path

import cv2
import numpy as np
import pytest

from train import synth
from train.charset import is_valid_label

FORMAT_RE = {
    "digits": re.compile(r"^\d{1,6}$"),
    "decimal": re.compile(r"^\d{1,3}\.\d{1,3}$"),
    "negative": re.compile(r"^-\d{1,4}(\.\d{1,3})?$"),
    "fraction": re.compile(r"^-?\d{1,2}/\d{1,2}$"),
}


@pytest.fixture(scope="module")
def bank() -> synth.GlyphBank:
    """A tiny fake glyph bank (random blobs) so the tests never download MNIST."""
    rng = np.random.default_rng(0)
    images = []
    labels = []
    for digit in range(10):
        for _ in range(6):
            img = np.zeros((28, 28), np.uint8)
            cv2.circle(img, (14 + digit % 3, 14), 8 + digit % 4, 255, 2)
            cv2.line(img, (6, 22), (22, 6 + digit), 255, 2)
            images.append(img)
            labels.append(digit)
    return synth.GlyphBank(np.stack(images), np.array(labels))


def test_random_label_matches_its_format_and_length():
    rng = np.random.default_rng(7)
    for fmt in synth.FORMATS:
        for _ in range(500):
            label = synth.random_label(rng, fmt)
            assert FORMAT_RE[fmt].match(label), (fmt, label)
            assert len(label) <= synth.MAX_LEN
            assert is_valid_label(label)


def test_random_label_covers_short_and_long_strings():
    rng = np.random.default_rng(3)
    lengths = {len(synth.random_label(rng, "digits")) for _ in range(2000)}
    assert {1, 2, 3, 4, 5, 6} <= lengths


def test_writers_get_disjoint_exemplars(bank):
    rng = np.random.default_rng(0)
    writers = synth.make_writers(bank, 3, rng, exemplars_per_digit=2)
    assert [w.key for w in writers] == ["synth_w0000", "synth_w0001", "synth_w0002"]
    for digit in range(10):
        sets = [set(w.exemplars[digit].tolist()) for w in writers]
        assert not (sets[0] & sets[1]) and not (sets[1] & sets[2]) and not (sets[0] & sets[2])


def test_render_sample_is_a_32x128_paper_image(bank):
    rng = np.random.default_rng(1)
    writer = synth.make_writers(bank, 1, rng, exemplars_per_digit=3)[0]
    for label in ["7", "3.14", "-27", "12/25", "-0.5"]:
        image = synth.render_sample(label, writer, bank, rng)
        assert image.shape == (32, 128) and image.dtype == np.uint8
        assert image.mean() > 100  # mostly paper
        assert image.min() < 120  # some ink


def test_generate_writes_shards_and_csv(bank, tmp_path: Path):
    csv_path = synth.generate(tmp_path / "out", 25, 4, seed=5, bank=bank, exemplars_per_digit=2, shard_size=10)
    assert csv_path == tmp_path / "out" / "labels.csv"
    with csv_path.open(newline="", encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    assert len(rows) == 25
    assert list(rows[0].keys()) == ["path", "label", "writer_key"]
    assert sorted(p.name for p in (tmp_path / "out" / "images").iterdir()) == ["shard_000", "shard_001", "shard_002"]
    assert {r["writer_key"] for r in rows} == {f"synth_w{i:04d}" for i in range(4)}
    for row in rows:
        assert is_valid_label(row["label"])
        assert any(rx.match(row["label"]) for rx in FORMAT_RE.values())
        image = cv2.imread(str(tmp_path / "out" / row["path"]), cv2.IMREAD_UNCHANGED)
        assert image is not None and image.shape == (32, 128)


def test_generate_is_deterministic_for_a_seed(bank, tmp_path: Path):
    a = synth.generate(tmp_path / "a", 12, 3, seed=9, bank=bank, exemplars_per_digit=2)
    b = synth.generate(tmp_path / "b", 12, 3, seed=9, bank=bank, exemplars_per_digit=2)
    assert a.read_text() == b.read_text()
    assert np.array_equal(cv2.imread(str(tmp_path / "a/images/shard_000/0000003.png"), 0), cv2.imread(str(tmp_path / "b/images/shard_000/0000003.png"), 0))
