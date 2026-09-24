"""Threshold sweep and recommendation on the validation set (DESIGN 12.2: tune the 0.8 cut-off). No TensorFlow needed."""

import numpy as np
import pytest

from train.charset import BLANK, NUM_CLASSES
from train.evaluate import DEFAULT_SWEEP, evaluate_predictions, recommend_threshold, threshold_sweep
from train.model import TIMESTEPS


def sample(indices, p: float) -> np.ndarray:
    """(32, 14) probabilities whose greedy path is ``indices`` then blanks, with max prob ``p`` at every timestep."""
    probs = np.full((TIMESTEPS, NUM_CLASSES), (1.0 - p) / (NUM_CLASSES - 1), np.float32)
    for t in range(TIMESTEPS):
        probs[t, indices[t] if t < len(indices) else BLANK] = p
    return probs


LABELS = ["4", "8", "1", "3"]
PROBS = np.stack([sample([4], 0.992), sample([7], 0.91), sample([1], 0.72), sample([2], 0.61)])  # right, wrong, right, wrong (off the sweep points: probs are float32)


def test_threshold_sweep_reports_abstain_and_accuracy_per_threshold():
    rows = threshold_sweep(LABELS, PROBS, thresholds=(0.5, 0.8, 0.95, 0.995))
    assert [r["threshold"] for r in rows] == [0.5, 0.8, 0.95, 0.995]
    assert rows[0] == {"threshold": 0.5, "n_answered": 4, "abstain_rate": 0.0, "accuracy_when_answered": 0.5}
    assert rows[1]["n_answered"] == 2 and rows[1]["abstain_rate"] == 0.5 and rows[1]["accuracy_when_answered"] == 0.5
    assert rows[2]["n_answered"] == 1 and rows[2]["abstain_rate"] == 0.75 and rows[2]["accuracy_when_answered"] == 1.0
    assert rows[3]["n_answered"] == 0 and rows[3]["abstain_rate"] == 1.0 and rows[3]["accuracy_when_answered"] is None


def test_recommend_threshold_is_the_smallest_that_reaches_the_target():
    rows = threshold_sweep(LABELS, PROBS, thresholds=(0.5, 0.8, 0.95, 0.995))
    assert recommend_threshold(rows, target_accuracy=0.95) == 0.95
    assert recommend_threshold(rows, target_accuracy=0.5) == 0.5
    assert recommend_threshold(rows, target_accuracy=1.01) is None
    assert recommend_threshold([]) is None


def test_evaluate_predictions_includes_the_sweep_and_the_design_threshold():
    result = evaluate_predictions(LABELS, PROBS, threshold=0.8)
    assert result["threshold"] == 0.8
    assert result["abstain_rate"] == pytest.approx(0.5)
    assert [r["threshold"] for r in result["threshold_sweep"]] == list(DEFAULT_SWEEP)
    assert 0.8 in DEFAULT_SWEEP
    assert result["recommended_threshold"] == 0.95
    assert evaluate_predictions(LABELS, PROBS, sweep=None)["threshold_sweep"] is None


def test_sweep_rejects_empty_input():
    with pytest.raises(ValueError):
        evaluate_predictions([], np.zeros((0, TIMESTEPS, NUM_CLASSES), np.float32))
