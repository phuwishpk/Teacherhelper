"""The BKT notebook (DESIGN 14.4) exists as a .py script and an .ipynb twin with identical cells."""

import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import sync_notebook  # noqa: E402

NOTEBOOKS = Path(__file__).resolve().parents[1] / "notebooks"
SCRIPT = NOTEBOOKS / "bkt_vs_ewma.py"
NOTEBOOK = NOTEBOOKS / "bkt_vs_ewma.ipynb"

SAMPLE = """\
# %% [markdown]
# # Title
#
# Prose with `code` and Thai: ทดสอบ

# %%
import math


def f(x):
    return x * 2


# %% [markdown]
# ## Second

# %%
print(f(2))
"""


def test_parse_percent_splits_markdown_and_code_cells():
    cells = sync_notebook.parse_percent(SAMPLE)
    assert [c.kind for c in cells] == ["markdown", "code", "markdown", "code"]
    assert cells[0].source == "# Title\n\nProse with `code` and Thai: ทดสอบ"
    assert cells[1].source == "import math\n\n\ndef f(x):\n    return x * 2"
    assert cells[3].source == "print(f(2))"


def test_percent_round_trip_is_stable():
    cells = sync_notebook.parse_percent(SAMPLE)
    again = sync_notebook.parse_percent(sync_notebook.to_percent(cells))
    assert again == cells
    notebook = sync_notebook.to_ipynb(cells)
    assert sync_notebook.from_ipynb(notebook) == cells
    assert [c["id"] for c in notebook["cells"]] == ["cell-00", "cell-01", "cell-02", "cell-03"]
    assert notebook["cells"][1]["outputs"] == [] and "outputs" not in notebook["cells"][0]


def test_sync_writes_and_checks_the_twin(tmp_path: Path):
    script = tmp_path / "nb.py"
    script.write_text(SAMPLE, encoding="utf-8")
    assert sync_notebook.sync(script, check=True) is False  # no twin yet
    assert sync_notebook.sync(script) is True
    assert sync_notebook.sync(script, check=True) is True
    script.write_text(SAMPLE + "\n# %%\nprint('new cell')\n", encoding="utf-8")
    assert sync_notebook.sync(script, check=True) is False
    sync_notebook.sync(script, from_ipynb_file=True)  # the .ipynb wins again
    assert sync_notebook.sync(script, check=True) is True
    assert sync_notebook.main([str(script), "--check"]) == 0


def test_bkt_notebook_twin_is_in_sync_with_its_script():
    assert SCRIPT.exists() and NOTEBOOK.exists()
    assert sync_notebook.sync(SCRIPT, check=True), "run: uv run python tools/sync_notebook.py notebooks/bkt_vs_ewma.py"
    cells = sync_notebook.parse_percent(SCRIPT.read_text(encoding="utf-8"))
    assert cells[0].kind == "markdown" and "BKT" in cells[0].source and "EWMA" in cells[0].source
    code = "\n".join(c.source for c in cells if c.kind == "code")
    assert "pyBKT" in code and "roc_auc_score" in code and "score_ratio" in code


def test_bkt_notebook_is_valid_nbformat():
    nbformat = pytest.importorskip("nbformat")
    notebook = nbformat.reads(NOTEBOOK.read_text(encoding="utf-8"), as_version=4)
    nbformat.validate(notebook)
    assert notebook.metadata.kernelspec.name == "python3"
    assert all(not c.get("outputs") for c in notebook.cells)  # committed clean, outputs live in notebooks/out


def test_generated_ipynb_is_deterministic(tmp_path: Path):
    script = tmp_path / "nb.py"
    script.write_text(SAMPLE, encoding="utf-8")
    sync_notebook.sync(script)
    first = json.loads(script.with_suffix(".ipynb").read_text(encoding="utf-8"))
    sync_notebook.sync(script)
    second = json.loads(script.with_suffix(".ipynb").read_text(encoding="utf-8"))
    assert first == second
