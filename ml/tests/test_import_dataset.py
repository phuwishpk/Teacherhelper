"""Importing the team's real dataset: validation, conversion and merge."""

import csv
from pathlib import Path

import cv2
import numpy as np

from train import import_dataset
from train.dataset import read_labels_csv


def write_crop(path: Path, ink: bool = True, size=(60, 200)):
    gray = np.full(size, 245, np.uint8)
    if ink:
        cv2.putText(gray, "3.14", (20, 45), cv2.FONT_HERSHEY_SIMPLEX, 1.4, 20, 3)
    cv2.imwrite(str(path), gray)


def test_import_accepts_valid_rows_and_rejects_the_rest(tmp_path: Path):
    src = tmp_path / "team"
    src.mkdir()
    write_crop(src / "a.png")
    write_crop(src / "b.png")
    write_crop(src / "blank.png", ink=False)
    (src / "labels.csv").write_text(
        "path,label,writer_key\n"
        "a.png,3.14,alice\n"
        "b.png,-27,bob\n"
        "missing.png,5,bob\n"
        "a.png,๓.๑๔,alice\n"
        "blank.png,7,carol\n"
        "b.png,12,\n",
        encoding="utf-8",
    )
    out = tmp_path / "real"
    report = import_dataset.import_csv(src / "labels.csv", "team2026", out)
    assert [s.label for s in report.accepted] == ["3.14", "-27"]
    assert [s.writer_key for s in report.accepted] == ["team2026:alice", "team2026:bob"]
    reasons = [r for _, _, r in report.rejected]
    assert reasons[0] == "file not found"
    assert "must be 1-12 characters" in reasons[1]
    assert reasons[2] == "blank image (no ink)"
    assert reasons[3] == "empty writer_key"
    stats = report.stats()
    assert stats["accepted"] == 2 and stats["rejected"] == 4 and stats["writers"] == 2
    assert stats["formats"] == {"decimal": 1, "negative": 1}
    # converted images are canvas-sized and the merged CSV loads with the normal reader
    merged = read_labels_csv(out / "labels.csv")
    assert len(merged) == 2
    image = cv2.imread(str(merged[0].path), cv2.IMREAD_UNCHANGED)
    assert image.shape == (32, 128)
    with (out / "rejected" / "team2026.csv").open(newline="", encoding="utf-8") as fh:
        assert len(list(csv.DictReader(fh))) == 4


def test_second_import_is_merged_with_the_first(tmp_path: Path):
    for name in ("one", "two"):
        src = tmp_path / name
        src.mkdir()
        write_crop(src / "x.png")
        (src / "labels.csv").write_text("path,label,writer_key\nx.png,8,w\n", encoding="utf-8")
        import_dataset.import_csv(src / "labels.csv", name, tmp_path / "real")
    merged = read_labels_csv(tmp_path / "real" / "labels.csv")
    assert sorted(s.writer_key for s in merged) == ["one:w", "two:w"]


def test_dry_run_writes_nothing(tmp_path: Path):
    src = tmp_path / "team"
    src.mkdir()
    write_crop(src / "a.png")
    (src / "labels.csv").write_text("path,label,writer_key\na.png,1/2,w\n", encoding="utf-8")
    report = import_dataset.import_csv(src / "labels.csv", "dry", None)
    assert len(report.accepted) == 1
    assert not (tmp_path / "data").exists()
