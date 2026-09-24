"""System 1 `show_work` (DESIGN 11.3) including the golden worked example."""

import itertools

import pytest

from fuzzy.grading import grade_show_work
from fuzzy.rulesets import SHOW_WORK, STRICTNESS_LEVELS

GRID = [i / 20 for i in range(21)]


def test_golden_example_from_design_11_3():
    """3 of 4 steps valid, final answer wrong, 5 points, normal strictness."""
    result = grade_show_work(final_match=0.0, step_ratio=0.75, strictness="normal")

    assert result.grading_state == "scored"
    assert result.score_ratio == pytest.approx(0.538, abs=0.001)
    assert result.u == pytest.approx(0.525, abs=0.001)
    assert result.understanding == "partial"
    assert result.score(max_points=5) == 2.5

    trace = result.trace
    assert trace.memberships["F"] == pytest.approx({"wrong": 1.0, "correct": 0.0})
    assert trace.memberships["S"] == pytest.approx({"few": 0.0, "medium": 0.167, "many": 0.5}, abs=0.001)
    weights = {r.name: r.weight for r in trace.rules}
    assert weights["R4"] == pytest.approx(0.5)
    assert weights["R5"] == pytest.approx(0.167, abs=0.001)
    assert all(weights[name] == 0.0 for name in ("R1", "R2", "R3", "R6"))
    assert [r.name for r in trace.fired()] == ["R4", "R5"]
    assert trace.weight_sum == pytest.approx(0.667, abs=0.001)


# crisp corners: exactly one rule fires with weight 1, so the output is that rule's singleton
CORNERS = {
    ("R1", 1.0, 1.0): ((1.00, 1.00, 1.00), 1.0),
    ("R2", 1.0, 0.5): ((0.85, 0.80, 0.70), 0.7),
    ("R3", 1.0, 0.0): ((0.60, 0.50, 0.30), 0.4),
    ("R4", 0.0, 1.0): ((0.70, 0.60, 0.40), 0.6),
    ("R5", 0.0, 0.5): ((0.45, 0.35, 0.20), 0.3),
    ("R6", 0.0, 0.0): ((0.00, 0.00, 0.00), 0.0),
}


@pytest.mark.parametrize(("rule", "f", "s"), list(CORNERS))
@pytest.mark.parametrize("strictness", STRICTNESS_LEVELS)
def test_each_rule_alone_at_its_corner(rule, f, s, strictness):
    z_by_strictness, u = CORNERS[(rule, f, s)]
    result = grade_show_work(f, s, strictness)
    fired = result.trace.fired()
    assert [r.name for r in fired] == [rule]
    assert fired[0].weight == pytest.approx(1.0)
    assert result.score_ratio == pytest.approx(z_by_strictness[STRICTNESS_LEVELS.index(strictness)])
    assert result.u == pytest.approx(u)


def test_rule_table_matches_design():
    assert [r.name for r in SHOW_WORK.rules] == ["R1", "R2", "R3", "R4", "R5", "R6"]
    assert SHOW_WORK.outputs == ("score_ratio", "u")


def test_never_degenerate_on_grid():
    for f, s in itertools.product(GRID, GRID):
        assert grade_show_work(f, s).grading_state == "scored"


def test_lenient_ge_normal_ge_strict_everywhere():
    for f, s in itertools.product(GRID, GRID):
        lenient = grade_show_work(f, s, "lenient").score_ratio
        normal = grade_show_work(f, s, "normal").score_ratio
        strict = grade_show_work(f, s, "strict").score_ratio
        assert lenient >= normal - 1e-9
        assert normal >= strict - 1e-9


def test_u_does_not_depend_on_strictness():
    for f, s in itertools.product(GRID, GRID):
        values = {grade_show_work(f, s, level).u for level in STRICTNESS_LEVELS}
        assert max(values) - min(values) < 1e-9


def test_score_is_monotone_in_final_answer_and_steps():
    for s in GRID:
        scores = [grade_show_work(f, s).score_ratio for f in GRID]
        assert all(b >= a - 1e-9 for a, b in zip(scores, scores[1:], strict=True))
    for f in GRID:
        scores = [grade_show_work(f, s).score_ratio for s in GRID]
        assert all(b >= a - 1e-9 for a, b in zip(scores, scores[1:], strict=True))


def test_right_answer_with_no_work_scores_below_wrong_answer_with_full_work():
    """R3 (maybe guessed or copied) is worth less than R4 (method right, slipped at the end)."""
    assert grade_show_work(1.0, 0.0).score_ratio < grade_show_work(0.0, 1.0).score_ratio
