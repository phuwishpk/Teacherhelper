#!/usr/bin/env python3
"""Keep a ``.py`` script (percent format) and its ``.ipynb`` twin in sync.

The notebooks under ``ml/notebooks`` are authored as plain Python in the
*percent* cell format understood by VS Code, Spyder and jupytext::

    # %% [markdown]
    # ## A heading
    # Some prose.

    # %%
    print("a code cell")

The ``.py`` file is the source of truth (it diffs well and runs headless);
the ``.ipynb`` is generated from it so that DESIGN 14.4's
``ml/notebooks/bkt_vs_ewma.ipynb`` opens in Jupyter. No jupytext dependency:
the ipynb JSON is written directly with deterministic cell ids.

Usage (from ``ml/``):
    uv run python tools/sync_notebook.py notebooks/bkt_vs_ewma.py            # write the .ipynb
    uv run python tools/sync_notebook.py notebooks/bkt_vs_ewma.py --check    # exit 1 if out of sync
    uv run python tools/sync_notebook.py notebooks/bkt_vs_ewma.py --from-ipynb  # edited in Jupyter: rewrite the .py
"""

from __future__ import annotations

import argparse
import json
import sys
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path

MARKER = "# %%"
MARKDOWN_MARKER = "# %% [markdown]"
NOTEBOOK_METADATA = {
    "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
    "language_info": {"name": "python", "version": "3.12"},
}


@dataclass(frozen=True)
class Cell:
    kind: str  # "markdown" | "code"
    source: str  # without a trailing newline


def _strip_blank_edges(lines: list[str]) -> list[str]:
    start, end = 0, len(lines)
    while start < end and not lines[start].strip():
        start += 1
    while end > start and not lines[end - 1].strip():
        end -= 1
    return lines[start:end]


def parse_percent(text: str) -> list[Cell]:
    """Split percent-format Python into cells; text before the first marker is a code cell if not blank."""
    cells: list[Cell] = []
    kind: str | None = None
    buffer: list[str] = []

    def flush() -> None:
        body = _strip_blank_edges(buffer)
        if kind == "markdown":
            body = [line[2:] if line.startswith("# ") else ("" if line.strip() == "#" else line) for line in body]
        if body or kind is not None and kind != "prelude":
            if body:
                cells.append(Cell("markdown" if kind == "markdown" else "code", "\n".join(body)))

    for line in text.splitlines():
        stripped = line.rstrip()
        if stripped == MARKDOWN_MARKER or stripped == MARKER or stripped.startswith(MARKER + " "):
            flush()
            kind = "markdown" if stripped.startswith(MARKDOWN_MARKER) else "code"
            buffer = []
        else:
            buffer.append(line.rstrip("\n"))
    flush()
    return cells


def to_percent(cells: Sequence[Cell]) -> str:
    chunks: list[str] = []
    for cell in cells:
        if cell.kind == "markdown":
            body = "\n".join(f"# {line}" if line else "#" for line in cell.source.split("\n"))
            chunks.append(f"{MARKDOWN_MARKER}\n{body}")
        else:
            chunks.append(f"{MARKER}\n{cell.source}")
    return "\n\n".join(chunks) + "\n"


def to_ipynb(cells: Sequence[Cell]) -> dict:
    """A clean nbformat 4.5 notebook (no outputs, deterministic ids)."""
    out = []
    for i, cell in enumerate(cells):
        lines = cell.source.split("\n")
        source = [line + "\n" for line in lines[:-1]] + [lines[-1]]
        record: dict = {"cell_type": cell.kind, "id": f"cell-{i:02d}", "metadata": {}, "source": source}
        if cell.kind == "code":
            record["execution_count"] = None
            record["outputs"] = []
        out.append(record)
    return {"cells": out, "metadata": NOTEBOOK_METADATA, "nbformat": 4, "nbformat_minor": 5}


def from_ipynb(notebook: dict) -> list[Cell]:
    cells = []
    for record in notebook["cells"]:
        if record["cell_type"] not in ("markdown", "code"):
            continue
        source = record["source"]
        text = "".join(source) if isinstance(source, list) else str(source)
        cells.append(Cell(record["cell_type"], text.rstrip("\n")))
    return cells


def dump_ipynb(notebook: dict) -> str:
    return json.dumps(notebook, indent=1, ensure_ascii=False) + "\n"


def sync(py_path: Path, check: bool = False, from_ipynb_file: bool = False) -> bool:
    """Return True when the twins are (now) in sync."""
    ipynb_path = py_path.with_suffix(".ipynb")
    if from_ipynb_file:
        cells = from_ipynb(json.loads(ipynb_path.read_text(encoding="utf-8")))
        py_path.write_text(to_percent(cells), encoding="utf-8")
        return True
    cells = parse_percent(py_path.read_text(encoding="utf-8"))
    expected = dump_ipynb(to_ipynb(cells))
    if check:
        if not ipynb_path.exists():
            return False
        current = json.loads(ipynb_path.read_text(encoding="utf-8"))
        return from_ipynb(current) == cells
    ipynb_path.write_text(expected, encoding="utf-8")
    return True


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("script", type=Path, help="the percent-format .py file (its .ipynb twin sits next to it)")
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--check", action="store_true", help="only verify; exit 1 when the .ipynb is stale or missing")
    group.add_argument("--from-ipynb", action="store_true", help="regenerate the .py from the .ipynb")
    args = parser.parse_args(argv)
    ok = sync(args.script, args.check, args.from_ipynb)
    twin = args.script.with_suffix(".ipynb")
    if args.check:
        print(f"{twin}: {'in sync' if ok else 'OUT OF SYNC (run tools/sync_notebook.py to regenerate)'}", file=sys.stderr)
        return 0 if ok else 1
    print(f"wrote {args.script if args.from_ipynb else twin}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
