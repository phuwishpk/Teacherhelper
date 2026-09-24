"""System 2 (DESIGN §11.8): review priority for the teacher's queue."""

import pytest

from fuzzy.priority import (
    blank_disagreement,
    boundary_closeness,
    illegibility,
    priority_band,
    reader_disagreement,
    review_priority,
)


def test_design_example_readable_handwriting_lands_in_look_band():
    # D = 0, L = 0.4, B = 0: P2 fires with 0.4, P4 with 0.6 → p = 0.4·0.8 / 1.0 = 0.32
    result = review_priority(0.0, 0.4, 0.0)
    assert result.p == pytest.approx(0.32, abs=1e-9)
    assert result.band == "look"
    assert result.flag is None
    assert not result.bulk_approvable


def test_reader_disagreement_dominates():
    assert review_priority(1.0, 0.0, 0.0).p == pytest.approx(1.0)
    assert review_priority(1.0, 0.0, 0.0).band == "check"


def test_all_clear_is_confident_and_bulk_approvable():
    result = review_priority(0.0, 0.0, 0.0)
    assert result.p == pytest.approx(0.0)
    assert result.band == "confident"
    assert result.bulk_approvable


def test_suspicious_instruction_skips_fuzzy_and_tops_the_queue():
    result = review_priority(0.0, 0.0, 0.0, suspicious_instruction=True)
    assert result.p == 1.0 and result.flag == "suspicious" and result.band == "check"
    assert not result.bulk_approvable


def test_manual_sorts_before_suspicious_before_scored():
    manual = review_priority(0.0, 0.0, 0.0, grading_state="manual")
    suspicious = review_priority(0.0, 0.0, 0.0, suspicious_instruction=True)
    scored = review_priority(1.0, 1.0, 1.0)
    assert manual.sort_key() < suspicious.sort_key() < scored.sort_key()


@pytest.mark.parametrize(("p", "band"), [(0.5, "check"), (0.9, "check"), (0.2, "look"), (0.49, "look"), (0.19, "confident"), (0.0, "confident")])
def test_priority_bands(p, band):
    assert priority_band(p) == band


def test_reader_disagreement_inputs():
    assert reader_disagreement("125", "125", numeric=True) == 0.0
    assert reader_disagreement("125", "126", numeric=True) == 1.0
    assert reader_disagreement(None, "125", numeric=True) == 0.5  # CNN abstained
    assert reader_disagreement(None, "hello", numeric=False) == 0.0  # not a numeric box


def test_blank_claim_contradicted_by_ink():
    assert blank_disagreement(True, 0.05) == 1.0
    assert blank_disagreement(True, 0.01) == 0.0
    assert blank_disagreement(False, 0.5) == 0.0


def test_illegibility_from_legibility_and_unknown_marks():
    assert illegibility("clear") == 0.0
    assert illegibility("readable") == pytest.approx(0.4)
    assert illegibility("hard") == 1.0
    assert illegibility("clear", "ab[?]") > 0.0  # unreadable characters raise it even if Gemini says clear


def test_boundary_closeness_peaks_on_the_understanding_thresholds():
    assert boundary_closeness(0.4) == pytest.approx(1.0)
    assert boundary_closeness(0.75) == pytest.approx(1.0)
    assert boundary_closeness(0.575) == pytest.approx(0.0)  # 0.175 away from both thresholds
    assert 0.0 < boundary_closeness(0.45) < 1.0
