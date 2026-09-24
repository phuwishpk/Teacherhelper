"""Plot the membership functions and output surfaces of DESIGN section 11.

Writes PNGs into ``ml/fuzzy/plots/`` (override with ``--out``):

    membership_sets.png     the three set families (low/high, few/medium/many, mismatch/close/match)
    surface_show_work.png   score_ratio over (F, S) per strictness, plus u
    surface_open.png        score_ratio over (K, R) per strictness, plus u
    curve_short.png         score_ratio and u against M per strictness
    surface_priority.png    p over (D, L) at B = 0, 0.5, 1

Usage:
    uv run python -m fuzzy.plot_membership
    uv run python -m fuzzy.plot_membership --out /tmp/plots
"""

from __future__ import annotations

import argparse
from collections.abc import Callable, Sequence
from pathlib import Path

import matplotlib

matplotlib.use("Agg")

import matplotlib.pyplot as plt  # noqa: E402  (backend must be set first)
import numpy as np  # noqa: E402
from matplotlib.colors import LinearSegmentedColormap  # noqa: E402

from fuzzy.membership import build  # noqa: E402
from fuzzy.rulesets import (  # noqa: E402
    FEW_MEDIUM_MANY,
    LOW_HIGH,
    OPEN,
    REVIEW_PRIORITY,
    SHORT,
    SHOW_WORK,
    STRICTNESS_LEVELS,
    RuleSet,
    Strictness,
)

DEFAULT_OUT_DIR = Path(__file__).resolve().parent / "plots"

# Palette: fixed categorical order (never cycled) and one single-hue sequential ramp.
SERIES = ("#2a78d6", "#eb6834", "#1baf7a")  # blue, orange, aqua
SEQUENTIAL = ("#cde2fb", "#9ec5f4", "#6da7ec", "#3987e5", "#256abf", "#184f95", "#0d366b")
SURFACE = "#fcfcfb"
TEXT_PRIMARY = "#0b0b0b"
TEXT_SECONDARY = "#52514e"
GRID = "#e6e5e1"

BLUE_RAMP = LinearSegmentedColormap.from_list("eduvision_blue", SEQUENTIAL)

GRID_POINTS = 101
DPI = 150

STRICTNESS_LABEL: dict[Strictness, str] = {"lenient": "lenient", "normal": "normal", "strict": "strict"}


def _style() -> None:
    plt.rcParams.update(
        {
            "figure.facecolor": SURFACE,
            "axes.facecolor": SURFACE,
            "axes.edgecolor": GRID,
            "axes.labelcolor": TEXT_SECONDARY,
            "axes.titlecolor": TEXT_PRIMARY,
            "axes.titlesize": 11,
            "axes.labelsize": 10,
            "axes.grid": True,
            "grid.color": GRID,
            "grid.linewidth": 0.8,
            "xtick.color": TEXT_SECONDARY,
            "ytick.color": TEXT_SECONDARY,
            "xtick.labelsize": 9,
            "ytick.labelsize": 9,
            "legend.frameon": False,
            "legend.fontsize": 9,
            "lines.linewidth": 2,
            "font.family": "DejaVu Sans",
        }
    )


def _line_family(ax: plt.Axes, sets: dict, title: str, xlabel: str) -> None:
    xs = np.linspace(0.0, 1.0, GRID_POINTS)
    for color, (label, spec) in zip(SERIES, sets.items(), strict=False):
        fn = build(spec)
        ys = [fn(float(x)) for x in xs]
        ax.plot(xs, ys, color=color, label=label)
    ax.set_title(title)
    ax.set_xlabel(xlabel)
    ax.set_ylabel("membership")
    ax.set_xlim(0, 1)
    ax.set_ylim(-0.02, 1.05)
    ax.legend(loc="center right")


def plot_membership_sets(out_dir: Path) -> Path:
    fig, axes = plt.subplots(1, 3, figsize=(12, 3.4), constrained_layout=True)
    _line_family(axes[0], LOW_HIGH, "Two-valued: F, K, D, L, B", "x")
    _line_family(axes[1], FEW_MEDIUM_MANY, "Steps / criteria: S, R", "ratio of valid steps")
    _line_family(axes[2], SHORT.variables["M"], "Key match: M", "M")
    fig.suptitle("Membership functions (DESIGN 11.3-11.5, 11.8)", color=TEXT_PRIMARY)
    path = out_dir / "membership_sets.png"
    fig.savefig(path, dpi=DPI)
    plt.close(fig)
    return path


def _surface(ruleset: RuleSet, x_var: str, y_var: str, output: str, strictness: Strictness, fixed: dict[str, float] | None = None) -> np.ndarray:
    engine = ruleset.engine(strictness)
    xs = np.linspace(0.0, 1.0, GRID_POINTS)
    grid = np.empty((GRID_POINTS, GRID_POINTS))
    for i, y in enumerate(xs):
        for j, x in enumerate(xs):
            inputs = {x_var: float(x), y_var: float(y), **(fixed or {})}
            result = engine.evaluate(inputs)
            grid[i, j] = np.nan if result.is_degenerate else result.outputs[output]
    return grid


def _draw_surface(ax: plt.Axes, grid: np.ndarray, title: str, xlabel: str, ylabel: str) -> plt.cm.ScalarMappable:
    image = ax.imshow(grid, origin="lower", extent=(0, 1, 0, 1), cmap=BLUE_RAMP, vmin=0, vmax=1, aspect="equal")
    axis = np.linspace(0, 1, GRID_POINTS)
    # dark lines on the light end of the ramp, light lines on the dark end
    for levels, color in ((np.arange(0.1, 0.5, 0.1), TEXT_SECONDARY), (np.arange(0.5, 1.0, 0.1), SURFACE)):
        contours = ax.contour(axis, axis, grid, levels=levels, colors=color, linewidths=0.8)
        ax.clabel(contours, fmt="%.1f", fontsize=7, colors=color)
    ax.set_title(title)
    ax.set_xlabel(xlabel)
    ax.set_ylabel(ylabel)
    ax.grid(False)
    return image


def _surface_figure(
    ruleset: RuleSet,
    x_var: str,
    y_var: str,
    xlabel: str,
    ylabel: str,
    title: str,
    example: tuple[float, float] | None,
    path: Path,
) -> Path:
    fig, axes = plt.subplots(1, 4, figsize=(15, 3.9), constrained_layout=True)
    for ax, strictness in zip(axes[:3], STRICTNESS_LEVELS, strict=True):
        grid = _surface(ruleset, x_var, y_var, "score_ratio", strictness)
        image = _draw_surface(ax, grid, f"score_ratio ({STRICTNESS_LABEL[strictness]})", xlabel, ylabel)
        if example is not None:
            ax.plot(*example, marker="o", markersize=8, markerfacecolor=SURFACE, markeredgecolor=TEXT_PRIMARY, markeredgewidth=1.5)
    grid_u = _surface(ruleset, x_var, y_var, "u", "normal")
    _draw_surface(axes[3], grid_u, "u (all strictness levels)", xlabel, ylabel)
    if example is not None:
        axes[3].plot(*example, marker="o", markersize=8, markerfacecolor=SURFACE, markeredgecolor=TEXT_PRIMARY, markeredgewidth=1.5)
    colorbar = fig.colorbar(image, ax=axes, shrink=0.85, pad=0.02)
    colorbar.set_label("output", color=TEXT_SECONDARY)
    colorbar.outline.set_edgecolor(GRID)
    fig.suptitle(title, color=TEXT_PRIMARY)
    fig.savefig(path, dpi=DPI)
    plt.close(fig)
    return path


def plot_show_work(out_dir: Path) -> Path:
    return _surface_figure(
        SHOW_WORK, "F", "S", "F (final answer)", "S (valid steps)",
        "show_work (DESIGN 11.3); marker = worked example F=0, S=0.75",
        (0.0, 0.75), out_dir / "surface_show_work.png",
    )


def plot_open(out_dir: Path) -> Path:
    return _surface_figure(
        OPEN, "K", "R", "K (core criterion)", "R (other criteria)",
        "open (DESIGN 11.5)", None, out_dir / "surface_open.png",
    )


def plot_short(out_dir: Path) -> Path:
    xs = np.linspace(0.0, 1.0, GRID_POINTS)
    fig, axes = plt.subplots(1, 2, figsize=(10, 3.6), constrained_layout=True)
    for color, strictness in zip(SERIES, STRICTNESS_LEVELS, strict=True):
        engine = SHORT.engine(strictness)
        results = [engine.evaluate({"M": float(x)}) for x in xs]
        axes[0].plot(xs, [r.outputs.get("score_ratio", np.nan) for r in results], color=color, label=STRICTNESS_LABEL[strictness])
        if strictness == "normal":
            axes[1].plot(xs, [r.outputs.get("u", np.nan) for r in results], color=SERIES[0], label="u")
    axes[0].axvline(0.6, color=TEXT_SECONDARY, linewidth=1, linestyle=":")
    axes[0].text(0.59, 0.97, "numeric near-miss cap (M <= 0.6)", ha="right", va="top", color=TEXT_SECONDARY, fontsize=8)
    axes[0].set_title("score_ratio against M")
    axes[1].set_title("u against M")
    for ax in axes:
        ax.set_xlabel("M (match with key)")
        ax.set_xlim(0, 1)
        ax.set_ylim(-0.02, 1.05)
    axes[0].set_ylabel("score_ratio")
    axes[1].set_ylabel("u")
    axes[0].legend(loc="lower right", title="strictness")
    fig.suptitle("short (DESIGN 11.4)", color=TEXT_PRIMARY)
    path = out_dir / "curve_short.png"
    fig.savefig(path, dpi=DPI)
    plt.close(fig)
    return path


def plot_priority(out_dir: Path) -> Path:
    fig, axes = plt.subplots(1, 3, figsize=(12, 3.9), constrained_layout=True)
    image = None
    for ax, b in zip(axes, (0.0, 0.5, 1.0), strict=True):
        grid = _surface(REVIEW_PRIORITY, "D", "L", "p", "normal", fixed={"B": b})
        image = _draw_surface(ax, grid, f"p at B = {b:.1f}", "D (readers disagree)", "L (hard to read)")
        if b == 0.0:
            ax.plot(0.0, 0.4, marker="o", markersize=8, markerfacecolor=SURFACE, markeredgecolor=TEXT_PRIMARY, markeredgewidth=1.5)
    assert image is not None
    colorbar = fig.colorbar(image, ax=axes, shrink=0.85, pad=0.02)
    colorbar.set_label("p (review priority)", color=TEXT_SECONDARY)
    colorbar.outline.set_edgecolor(GRID)
    fig.suptitle("review priority (DESIGN 11.8); marker = worked example D=0, L=0.4, B=0", color=TEXT_PRIMARY)
    path = out_dir / "surface_priority.png"
    fig.savefig(path, dpi=DPI)
    plt.close(fig)
    return path


PLOTS: tuple[Callable[[Path], Path], ...] = (
    plot_membership_sets,
    plot_show_work,
    plot_open,
    plot_short,
    plot_priority,
)


def render_all(out_dir: Path = DEFAULT_OUT_DIR) -> list[Path]:
    _style()
    out_dir.mkdir(parents=True, exist_ok=True)
    return [plot(out_dir) for plot in PLOTS]


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT_DIR, help=f"output directory (default: {DEFAULT_OUT_DIR})")
    args = parser.parse_args(argv)
    for path in render_all(args.out):
        print(path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
