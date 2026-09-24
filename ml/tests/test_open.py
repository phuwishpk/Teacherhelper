"""System 1 for open questions (DESIGN §11.5): core criterion K vs the rest R."""

import itertools

import pytest

from fuzzy.grading import grade_open
from fuzzy.rulesets import STRICTNESS_LEVELS

GRID = [0.0, 0.25, 0.5, 0.75, 1.0]


def test_full_marks_when_core_and_rest_are_met():
    result = grade_open(1.0, 1.0)
    assert result.score_ratio == pytest.approx(1.0)
    assert result.understanding == "good"


def test_zero_when_nothing_is_met():
    result = grade_open(0.0, 0.0)
    assert result.score_ratio == pytest.approx(0.0)
    assert result.understanding == "not_yet"


def test_missing_the_core_idea_costs_more_than_missing_details():
    # O4 (core low, rest high) must score below O3 (core high, rest low)
    assert grade_open(0.0, 1.0).score_ratio < grade_open(1.0, 0.0).score_ratio


def test_singleton_values_match_design_table_at_the_corners():
    assert grade_open(1.0, 0.0).score_ratio == pytest.approx(0.60)  # O3 normal
    assert grade_open(0.0, 1.0).score_ratio == pytest.approx(0.45)  # O4 normal
    assert grade_open(1.0, 0.0, "strict").score_ratio == pytest.approx(0.50)
    assert grade_open(0.0, 1.0, "lenient").score_ratio == pytest.approx(0.55)


def test_lenient_ge_normal_ge_strict_everywhere():
    for k, r in itertools.product(GRID, GRID):
        lenient = grade_open(k, r, "lenient").score_ratio
        normal = grade_open(k, r, "normal").score_ratio
        strict = grade_open(k, r, "strict").score_ratio
        assert lenient >= normal - 1e-9 >= strict - 2e-9


def test_understanding_does_not_depend_on_strictness():
    for k, r in itertools.product(GRID, GRID):
        values = {grade_open(k, r, level).u for level in STRICTNESS_LEVELS}
        assert max(values) - min(values) < 1e-9


def test_score_is_monotone_when_the_other_input_is_crisp():
    # Same caveat as show_work: min-AND + weighted average is only guaranteed
    # monotone along an axis while the other input is a crisp 0 or 1.
    for r in (0.0, 1.0):
        scores = [grade_open(k, r).score_ratio for k in GRID]
        assert all(b >= a - 1e-9 for a, b in itertools.pairwise(scores))
    for k in (0.0, 1.0):
        scores = [grade_open(k, r).score_ratio for r in GRID]
        assert all(b >= a - 1e-9 for a, b in itertools.pairwise(scores))


def test_partial_inputs_never_dip_more_than_a_few_points():
    # e.g. K=0.25: R 0.75 → 1.0 gives 0.600 → 0.588 (O4 gains weight, O2 loses it)
    for k in GRID:
        scores = [grade_open(k, r).score_ratio for r in GRID]
        assert all(b >= a - 0.03 for a, b in itertools.pairwise(scores))
    for r in GRID:
        scores = [grade_open(k, r).score_ratio for k in GRID]
        assert all(b >= a - 0.03 for a, b in itertools.pairwise(scores))


def test_never_degenerate_on_grid():
    for k, r in itertools.product(GRID, GRID):
        assert grade_open(k, r).grading_state == "scored"
