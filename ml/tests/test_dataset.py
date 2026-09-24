"""labels.csv loading and the writer-based split (DESIGN 12.3)."""

from pathlib import Path

import numpy as np
import pytest

from train import dataset
from train.dataset import Sample


def make_samples(writers: dict[str, int]) -> list[Sample]:
    return [Sample(Path(f"/x/{w}_{i}.png"), "42", w) for w, n in writers.items() for i in range(n)]


def test_split_by_writer_keeps_each_writer_in_one_part():
    samples = make_samples({f"w{i:02d}": 10 + i for i in range(20)})
    split = dataset.split_by_writer(samples, val_frac=0.1, test_frac=0.1, seed=3)
    parts = {"train": set(split.writers(split.train)), "val": set(split.writers(split.val)), "test": set(split.writers(split.test))}
    assert parts["train"] and parts["val"] and parts["test"]
    assert not (parts["train"] & parts["val"]) and not (parts["train"] & parts["test"]) and not (parts["val"] & parts["test"])
    assert len(split.train) + len(split.val) + len(split.test) == len(samples)
    assert 0.05 <= len(split.test) / len(samples) <= 0.25
    assert 0.05 <= len(split.val) / len(samples) <= 0.25


def test_split_is_deterministic_and_changes_with_seed():
    samples = make_samples({f"w{i}": 5 for i in range(30)})
    a = dataset.split_by_writer(samples, seed=1)
    b = dataset.split_by_writer(samples, seed=1)
    c = dataset.split_by_writer(samples, seed=2)
    assert a.writers(a.test) == b.writers(b.test)
    assert a.writers(a.test) != c.writers(c.test)


def test_split_with_three_writers_gives_one_each():
    samples = make_samples({"a": 4, "b": 4, "c": 4})
    split = dataset.split_by_writer(samples, 0.1, 0.1, seed=0)
    assert len(split.writers(split.train)) == 1 and len(split.writers(split.val)) == 1 and len(split.writers(split.test)) == 1


def test_split_rejects_bad_fractions():
    with pytest.raises(ValueError):
        dataset.split_by_writer(make_samples({"a": 1}), val_frac=0.6, test_frac=0.5)


def test_split_from_writers_round_trips_split_json():
    samples = make_samples({f"w{i}": 3 for i in range(12)})
    split = dataset.split_by_writer(samples, seed=4)
    info = split.to_json(seed=4, val_frac=0.1, test_frac=0.1)
    again = dataset.split_from_writers(samples, info["val_writers"], info["test_writers"])
    assert again.writers(again.val) == split.writers(split.val)
    assert again.writers(again.test) == split.writers(split.test)
    assert len(again.train) == len(split.train)
    assert info["counts"] == {"train": len(split.train), "val": len(split.val), "test": len(split.test)}


def test_read_labels_csv_resolves_relative_paths(tmp_path: Path):
    csv_path = tmp_path / "labels.csv"
    csv_path.write_text("path,label,writer_key\nimages/a.png,3.14,w1\n/abs/b.png,-2,w2\n", encoding="utf-8")
    samples = dataset.read_labels_csv(csv_path)
    assert samples[0] == Sample(tmp_path / "images/a.png", "3.14", "w1")
    assert samples[1].path == Path("/abs/b.png")


@pytest.mark.parametrize(
    "row, message",
    [
        ("images/a.png,,w1", "empty label"),
        ("images/a.png,๓,w1", "must be 1-12 characters"),
        ("images/a.png,12,", "empty writer_key"),
        (",12,w1", "empty path"),
    ],
)
def test_read_labels_csv_reports_the_bad_row(tmp_path: Path, row, message):
    csv_path = tmp_path / "labels.csv"
    csv_path.write_text(f"path,label,writer_key\nimages/ok.png,1,w0\n{row}\n", encoding="utf-8")
    with pytest.raises(ValueError, match=f":3: {message}"):
        dataset.read_labels_csv(csv_path)


def test_read_labels_csv_requires_the_header(tmp_path: Path):
    csv_path = tmp_path / "labels.csv"
    csv_path.write_text("file,text\na.png,1\n", encoding="utf-8")
    with pytest.raises(ValueError, match="header"):
        dataset.read_labels_csv(csv_path)


def test_load_images_normalizes_to_model_input(tmp_path: Path):
    import cv2

    canvas = np.full((32, 128), 255, np.uint8)
    canvas[10:20, 10:30] = 0
    cv2.imwrite(str(tmp_path / "a.png"), canvas)
    batch = dataset.load_images([Sample(tmp_path / "a.png", "1", "w")])
    assert batch.shape == (1, 32, 128, 1) and batch.dtype == np.float32
    assert batch[0, 15, 15, 0] == 1.0 and batch[0, 0, 0, 0] == 0.0
