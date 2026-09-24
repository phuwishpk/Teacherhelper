#!/usr/bin/env python3
"""Generate the four ArUco corner markers of the worksheet (DESIGN 5.2, 5.3).

Writes ``{id}.png`` for DICT_4X4_50 ids 0-3 plus a ``manifest.json`` describing
the geometry into ``backend/resources/worksheet/aruco/`` (override with
``--out``). The backend embeds these PNGs in the PDF; the app detects them with
the same dictionary.

Geometry of one PNG (default 600 x 600 px):

    +---------------------------+  <- 1 cell of white quiet zone on every side
    |  +---------------------+  |
    |  |  black border (1)   |  |
    |  |  4 x 4 data bits    |  |     marker = 6 cells = 450 px
    |  |                     |  |     quiet  = 1 cell  =  75 px
    |  +---------------------+  |     cell   = 600 / 8 =  75 px
    +---------------------------+

The quiet zone is part of the PNG, so to print the marker (black square) at
``size_mm = 12`` the image must be placed at 16 mm with its centre where the
marker centre belongs. The layout frame of 5.3 is measured between marker
centres. Corner convention: id 0 top-left, 1 top-right, 2 bottom-right,
3 bottom-left (clockwise from the top-left corner of the page).

Usage:
    uv run tools/gen_aruco.py            # writes ../backend/resources/worksheet/aruco/
    uv run tools/gen_aruco.py --out /tmp/aruco --size 300
"""

from __future__ import annotations

import argparse
import json
import sys
from collections.abc import Sequence
from pathlib import Path
from typing import Any

import cv2
import numpy as np

DICTIONARY_NAME = "DICT_4X4_50"
DICTIONARY_ID = cv2.aruco.DICT_4X4_50
MARKER_BITS = 4
BORDER_BITS = 1
MARKER_CELLS = MARKER_BITS + 2 * BORDER_BITS  # 6

DEFAULT_IDS: tuple[int, ...] = (0, 1, 2, 3)
DEFAULT_SIZE_PX = 600
DEFAULT_QUIET_CELLS = 1
DEFAULT_MARKER_MM = 12.0  # DESIGN 5.3 marker.size_mm

CORNER_OF_ID: dict[int, str] = {
    0: "top_left",
    1: "top_right",
    2: "bottom_right",
    3: "bottom_left",
}

MANIFEST_NAME = "manifest.json"

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_OUT_DIR = REPO_ROOT / "backend" / "resources" / "worksheet" / "aruco"


def geometry(size_px: int = DEFAULT_SIZE_PX, quiet_cells: int = DEFAULT_QUIET_CELLS) -> dict[str, int]:
    """Pixel geometry of one PNG; raises when ``size_px`` does not divide into whole cells."""
    if quiet_cells < 1:
        raise ValueError("quiet zone must be at least one cell (OpenCV needs a white border)")
    total_cells = MARKER_CELLS + 2 * quiet_cells
    if size_px <= 0 or size_px % total_cells != 0:
        raise ValueError(f"size {size_px} px must be a positive multiple of {total_cells} cells")
    cell_px = size_px // total_cells
    return {
        "image_px": size_px,
        "cell_px": cell_px,
        "marker_px": MARKER_CELLS * cell_px,
        "quiet_zone_px": quiet_cells * cell_px,
        "total_cells": total_cells,
    }


def render_marker(
    marker_id: int,
    size_px: int = DEFAULT_SIZE_PX,
    quiet_cells: int = DEFAULT_QUIET_CELLS,
) -> np.ndarray:
    """Return a ``size_px`` x ``size_px`` uint8 grayscale image: marker centred in a white quiet zone."""
    dictionary = cv2.aruco.getPredefinedDictionary(DICTIONARY_ID)
    if not 0 <= marker_id < dictionary.bytesList.shape[0]:
        raise ValueError(f"id {marker_id} is outside {DICTIONARY_NAME} (0-{dictionary.bytesList.shape[0] - 1})")
    geo = geometry(size_px, quiet_cells)
    marker = cv2.aruco.generateImageMarker(dictionary, marker_id, geo["marker_px"], borderBits=BORDER_BITS)
    canvas = np.full((size_px, size_px), 255, dtype=np.uint8)
    offset = geo["quiet_zone_px"]
    canvas[offset : offset + geo["marker_px"], offset : offset + geo["marker_px"]] = marker
    return canvas


def build_manifest(
    ids: Sequence[int],
    size_px: int,
    quiet_cells: int,
    marker_mm: float,
) -> dict[str, Any]:
    geo = geometry(size_px, quiet_cells)
    mm_per_cell = marker_mm / MARKER_CELLS
    return {
        "dictionary": DICTIONARY_NAME,
        "marker_bits": MARKER_BITS,
        "border_bits": BORDER_BITS,
        "image_px": geo["image_px"],
        "cell_px": geo["cell_px"],
        "marker_px": geo["marker_px"],
        "quiet_zone_px": geo["quiet_zone_px"],
        "print": {
            "marker_size_mm": marker_mm,
            "image_size_mm": round(geo["total_cells"] * mm_per_cell, 3),
            "quiet_zone_mm": round(quiet_cells * mm_per_cell, 3),
            "note": "Place the image so its centre sits on the layout frame corner; "
            "the image is larger than marker_size_mm because the quiet zone is inside the PNG.",
        },
        "markers": [
            {"id": marker_id, "file": f"{marker_id}.png", "corner": CORNER_OF_ID.get(marker_id)}
            for marker_id in ids
        ],
        "generator": "ml/tools/gen_aruco.py",
    }


def write_markers(
    out_dir: Path,
    ids: Sequence[int] = DEFAULT_IDS,
    size_px: int = DEFAULT_SIZE_PX,
    quiet_cells: int = DEFAULT_QUIET_CELLS,
    marker_mm: float = DEFAULT_MARKER_MM,
) -> list[Path]:
    """Write ``{id}.png`` for every id plus ``manifest.json``; returns the written paths."""
    if len(set(ids)) != len(ids):
        raise ValueError("ids must be unique")
    out_dir.mkdir(parents=True, exist_ok=True)
    written: list[Path] = []
    for marker_id in ids:
        path = out_dir / f"{marker_id}.png"
        image = render_marker(marker_id, size_px, quiet_cells)
        if not cv2.imwrite(str(path), image):
            raise OSError(f"could not write {path}")
        written.append(path)
    manifest_path = out_dir / MANIFEST_NAME
    manifest_path.write_text(
        json.dumps(build_manifest(ids, size_px, quiet_cells, marker_mm), indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    written.append(manifest_path)
    return written


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT_DIR, help=f"output directory (default: {DEFAULT_OUT_DIR})")
    parser.add_argument("--ids", type=int, nargs="+", default=list(DEFAULT_IDS), help="marker ids (default: 0 1 2 3)")
    parser.add_argument("--size", type=int, default=DEFAULT_SIZE_PX, help="PNG side in px incl. quiet zone (default: 600)")
    parser.add_argument("--quiet-cells", type=int, default=DEFAULT_QUIET_CELLS, help="quiet zone width in cells (default: 1)")
    parser.add_argument("--marker-mm", type=float, default=DEFAULT_MARKER_MM, help="printed marker size for the manifest (default: 12)")
    args = parser.parse_args(argv)
    try:
        written = write_markers(args.out, args.ids, args.size, args.quiet_cells, args.marker_mm)
    except (ValueError, OSError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    for path in written:
        print(path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
