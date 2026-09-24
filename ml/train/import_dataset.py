#!/usr/bin/env python3
"""Validate and merge the team's real handwriting dataset (DESIGN 12.3, items 2-3).

Input: a CSV with columns ``path,label,writer_key`` (paths relative to the CSV
or absolute) pointing at answer-frame crops, e.g. the export of the app's
collection-sheet mode or of the backend's ``training_samples`` table.

Each accepted crop is tight-cropped and fitted to the 32 x 128 canvas of
:mod:`train.preprocess` and written under ``<out>/images/<name>/``; the rows
go to ``<out>/sources/<name>.csv`` with the writer key prefixed
``<name>:`` so writers of different imports never collide, and
``<out>/labels.csv`` is regenerated from all sources. Rejected rows are listed
in ``<out>/rejected/<name>.csv`` with a reason.

Usage:
    uv run python -m train.import_dataset --csv ~/team/labels.csv --name team2026 --out data/real
    uv run python -m train.import_dataset --csv ~/team/labels.csv --name team2026 --dry-run
"""

from __future__ import annotations

import argparse
import csv
import json
import re
from collections import Counter
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

from train.dataset import CSV_COLUMNS, Sample, validate_row
from train.preprocess import fit_to_canvas, tight_crop, to_gray

SAFE_NAME = re.compile(r"^[A-Za-z0-9_-]{1,40}$")


@dataclass
class ImportReport:
    name: str
    accepted: list[Sample]
    rejected: list[tuple[int, str, str]]  # (row number, path, reason)

    def stats(self) -> dict:
        writers = Counter(s.writer_key for s in self.accepted)
        per_writer = sorted(writers.values())
        lengths = Counter(len(s.label) for s in self.accepted)
        forms = Counter(_form(s.label) for s in self.accepted)
        return {
            "name": self.name,
            "accepted": len(self.accepted),
            "rejected": len(self.rejected),
            "writers": len(writers),
            "samples_per_writer": {
                "min": per_writer[0] if per_writer else 0,
                "median": int(np.median(per_writer)) if per_writer else 0,
                "max": per_writer[-1] if per_writer else 0,
            },
            "label_lengths": {str(k): v for k, v in sorted(lengths.items())},
            "formats": dict(forms.most_common()),
            "rejection_reasons": dict(Counter(reason for _, _, reason in self.rejected).most_common()),
        }


def _form(label: str) -> str:
    if "/" in label:
        return "fraction"
    if label.startswith("-"):
        return "negative"
    if "." in label:
        return "decimal"
    return "digits"


def _read_rows(csv_path: Path) -> list[dict[str, str]]:
    with csv_path.open(newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        if reader.fieldnames is None or any(c not in reader.fieldnames for c in CSV_COLUMNS):
            raise SystemExit(f"{csv_path}: header must contain {','.join(CSV_COLUMNS)}")
        return list(reader)


def import_csv(
    csv_path: Path, name: str, out_dir: Path | None, tight: bool = True, margin: float = 0.10
) -> ImportReport:
    """Validate every row; when ``out_dir`` is given also convert the images and write the source CSV."""
    if not SAFE_NAME.match(name):
        raise SystemExit("--name must be 1-40 characters of letters, digits, _ or -")
    csv_path = Path(csv_path)
    rows = _read_rows(csv_path)
    accepted: list[Sample] = []
    rejected: list[tuple[int, str, str]] = []
    images_dir = out_dir / "images" / name if out_dir else None
    if images_dir:
        images_dir.mkdir(parents=True, exist_ok=True)
    for number, row in enumerate(rows, start=2):
        sample, reason = validate_row(row, csv_path.parent)
        if sample is None:
            rejected.append((number, row.get("path", "") or "", reason or "invalid"))
            continue
        if not sample.path.is_file():
            rejected.append((number, str(sample.path), "file not found"))
            continue
        image = cv2.imread(str(sample.path), cv2.IMREAD_UNCHANGED)
        if image is None or image.size == 0:
            rejected.append((number, str(sample.path), "unreadable image"))
            continue
        gray = to_gray(image)
        if gray.std() < 2.0:
            rejected.append((number, str(sample.path), "blank image (no ink)"))
            continue
        canvas = fit_to_canvas(tight_crop(gray, margin) if tight else gray)
        new_path = sample.path
        if images_dir:
            rel = Path("images") / name / f"{len(accepted):06d}.png"
            new_path = out_dir / rel
            if not cv2.imwrite(str(new_path), canvas):
                raise OSError(f"could not write {new_path}")
            new_path = rel
        accepted.append(Sample(new_path, sample.label, f"{name}:{sample.writer_key}"))
    report = ImportReport(name, accepted, rejected)
    if out_dir:
        sources = out_dir / "sources"
        sources.mkdir(parents=True, exist_ok=True)
        with (sources / f"{name}.csv").open("w", newline="", encoding="utf-8") as fh:
            writer = csv.writer(fh)
            writer.writerow(CSV_COLUMNS)
            for s in accepted:
                writer.writerow([Path(s.path).as_posix(), s.label, s.writer_key])
        rejected_dir = out_dir / "rejected"
        rejected_dir.mkdir(parents=True, exist_ok=True)
        with (rejected_dir / f"{name}.csv").open("w", newline="", encoding="utf-8") as fh:
            writer = csv.writer(fh)
            writer.writerow(("row", "path", "reason"))
            writer.writerows(rejected)
        merge_sources(out_dir)
    return report


def merge_sources(out_dir: Path) -> Path:
    """Regenerate ``<out>/labels.csv`` from every ``<out>/sources/*.csv``."""
    out_dir = Path(out_dir)
    merged = out_dir / "labels.csv"
    with merged.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh)
        writer.writerow(CSV_COLUMNS)
        for source in sorted((out_dir / "sources").glob("*.csv")):
            with source.open(newline="", encoding="utf-8") as src:
                for row in csv.DictReader(src):
                    writer.writerow([row["path"], row["label"], row["writer_key"]])
    return merged


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--csv", type=Path, required=True, help="path,label,writer_key CSV to import")
    parser.add_argument("--name", required=True, help="source name, e.g. team2026 (prefixes writer keys)")
    parser.add_argument("--out", type=Path, default=Path("data/real"), help="merged dataset directory")
    parser.add_argument("--dry-run", action="store_true", help="validate only, write nothing")
    parser.add_argument("--no-tight-crop", action="store_true", help="keep the crop as is (only fit to canvas)")
    parser.add_argument("--margin", type=float, default=0.10, help="tight-crop margin as a fraction of ink height")
    args = parser.parse_args(argv)
    report = import_csv(args.csv, args.name, None if args.dry_run else args.out, not args.no_tight_crop, args.margin)
    print(json.dumps(report.stats(), indent=2, ensure_ascii=False))
    for number, path, reason in report.rejected[:20]:
        print(f"  rejected row {number}: {reason} ({path})")
    if len(report.rejected) > 20:
        print(f"  ... {len(report.rejected) - 20} more (see rejected/{args.name}.csv)")
    if not args.dry_run:
        print(f"merged -> {args.out / 'labels.csv'}")
    return 0 if report.accepted else 1


if __name__ == "__main__":
    raise SystemExit(main())
