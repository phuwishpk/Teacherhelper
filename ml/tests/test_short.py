"""System 1 `short` (DESIGN 11.4) and the answer-key matching that feeds it."""

import pytest

from fuzzy.grading import grade_short
from fuzzy.matching import (
    final_answer_value,
    levenshtein,
    normalize_text,
    numeric_match,
    parse_number,
    short_match,
    similarity,
    text_match_exact,
    text_match_flexible,
)
from fuzzy.rulesets import SHORT, STRICTNESS_LEVELS

GRID = [i / 100 for i in range(101)]


# --- fuzzy sets and rules ------------------------------------------------------


@pytest.mark.parametrize(
    ("m", "lenient", "normal", "strict", "u"),
    [
        (0.0, 0.0, 0.0, 0.0, 0.0),  # mismatch only
        (0.3, 0.0, 0.0, 0.0, 0.0),  # still fully mismatch
        (0.7, 0.7, 0.5, 0.0, 0.5),  # close only
        (1.0, 1.0, 1.0, 1.0, 1.0),  # match only
    ],
)
def test_single_set_corners(m, lenient, normal, strict, u):
    for level, expected in zip(STRICTNESS_LEVELS, (lenient, normal, strict), strict=True):
        result = grade_short(m, level)
        assert result.score_ratio == pytest.approx(expected)
        assert result.u == pytest.approx(u)


def test_blend_between_mismatch_and_close():
    # M = 0.5: mismatch = (0.6-0.5)/0.3 = 1/3, close = (0.5-0.4)/0.3 = 1/3
    result = grade_short(0.5, "normal")
    assert result.trace.memberships["M"] == pytest.approx({"mismatch": 1 / 3, "close": 1 / 3, "match": 0.0})
    assert result.score_ratio == pytest.approx(0.25)
    assert result.u == pytest.approx(0.25)


def test_blend_between_close_and_match():
    # M = 0.9: close = (0.95-0.9)/0.25 = 0.2, match = (0.9-0.85)/0.15 = 1/3
    result = grade_short(0.9, "normal")
    assert result.trace.memberships["M"]["close"] == pytest.approx(0.2)
    assert result.trace.memberships["M"]["match"] == pytest.approx(1 / 3)
    expected = (0.2 * 0.5 + (1 / 3) * 1.0) / (0.2 + 1 / 3)
    assert result.score_ratio == pytest.approx(expected)


def test_numeric_near_miss_never_reaches_full_marks():
    """The numeric rule caps a near miss at M = 0.6, which is outside the `match` set."""
    for level in STRICTNESS_LEVELS:
        result = grade_short(0.6, level)
        assert result.trace.memberships["M"]["match"] == 0.0
        assert result.score_ratio < 1.0
    assert grade_short(0.6, "normal").score_ratio == pytest.approx(0.5)
    assert grade_short(0.6, "strict").score_ratio == pytest.approx(0.0)


def test_sets_cover_the_whole_range():
    for m in GRID:
        assert grade_short(m).grading_state == "scored"


def test_rule_table_matches_design():
    assert len(SHORT.rules) == 3
    assert all(len(r.when) == 1 and r.when.keys() == {"M"} for r in SHORT.rules)


# --- matching (input M) -----------------------------------------------------


def test_normalize_text():
    assert normalize_text("  ๑๒.๕ ") == "12.5"
    assert normalize_text("Bangkok") == "bangkok"
    assert normalize_text("\tกรุงเทพฯ\n") == "กรุงเทพฯ"


@pytest.mark.parametrize(
    ("a", "b", "expected"),
    [("", "", 0), ("abc", "", 3), ("", "abc", 3), ("kitten", "sitting", 3), ("แมว", "แมว", 0), ("แมว", "แมง", 1)],
)
def test_levenshtein(a, b, expected):
    assert levenshtein(a, b) == expected


def test_similarity():
    assert similarity("กรุงเทพฯ", "กรุงเทพฯ") == 1.0
    assert similarity("Bangkok ", "bangkok") == 1.0
    assert similarity("abcd", "abce") == pytest.approx(0.75)
    assert similarity("", "abc") == 0.0
    assert similarity("", "") == 1.0


def test_text_match_flexible_takes_best_of_similarity_and_key_match():
    accepted = ["กรุงเทพมหานคร", "กรุงเทพฯ"]
    assert text_match_flexible("กรุงเทพฯ", accepted) == 1.0
    assert text_match_flexible("กรุงเทพ", accepted) == pytest.approx(1 - 1 / 8)
    # Gemini's `partial` for short is worth 0.6 and can lift a low similarity
    assert text_match_flexible("xyz", accepted, key_match="partial") == pytest.approx(0.6)
    assert text_match_flexible("xyz", accepted, key_match="equivalent") == 1.0
    assert text_match_flexible("xyz", accepted, key_match="different") == pytest.approx(similarity("xyz", "กรุงเทพฯ"))
    with pytest.raises(ValueError):
        text_match_flexible("x", [])


def test_text_match_exact_ignores_key_match_and_needs_identity():
    assert text_match_exact(" Apple ", ["apple"]) == 1.0
    assert text_match_exact("aple", ["apple"]) == 0.0
    assert text_match_exact("๑๐", ["10"]) == 1.0


@pytest.mark.parametrize(
    ("answer", "key", "abs_tol", "expected"),
    [
        (12.5, 12.5, 0.0, 1.0),
        (12.51, 12.5, 0.01, 1.0),
        (12.52, 12.5, 0.01, 0.6 * (1 - (0.02 / 12.5) / 0.1)),
        (13.0, 12.5, 0.0, 0.6 * (1 - (0.5 / 12.5) / 0.1)),
        (20.0, 12.5, 0.0, 0.0),
        (0.05, 0.0, 0.0, 0.6 * (1 - 0.05 / 0.1)),  # rel_err uses max(|k|, 1)
        (-27.0, -27.0, 0.0, 1.0),
    ],
)
def test_numeric_match(answer, key, abs_tol, expected):
    assert numeric_match(answer, key, abs_tol) == pytest.approx(expected)


def test_numeric_match_is_capped_at_0_6():
    for answer in (12.5001, 12.51, 12.6, 13.0):
        assert numeric_match(answer, 12.5, 0.0) <= 0.6


@pytest.mark.parametrize(
    ("text", "expected"),
    [("12", 12.0), (" -3.5 ", -3.5), ("๑๒.๕", 12.5), ("3/4", 0.75), ("3,5", 3.5), ("−7", -7.0),
     ("abc", None), ("", None), ("[?]", None), ("1/0", None), ("12 คน", None)],
)
def test_parse_number(text, expected):
    assert parse_number(text) == expected


def test_short_match_dispatches_on_answer_key():
    text_key = {"accepted": ["กรุงเทพมหานคร", "กรุงเทพฯ"]}
    numeric_key = {"accepted": ["12.5"], "numeric": {"value": 12.5, "abs_tol": 0.01}}
    assert short_match("กรุงเทพฯ", text_key) == 1.0
    assert short_match("กรุงเทพ", text_key, "exact") == 0.0
    assert short_match("๑๒.๕๑", numeric_key) == 1.0
    assert short_match("13", numeric_key) == pytest.approx(numeric_match(13.0, 12.5, 0.01))
    # not a number in a numeric box: fall back to the text rule
    assert short_match("12.5", numeric_key, key_match=None) == 1.0
    assert short_match("สิบสอง", numeric_key) == pytest.approx(similarity("สิบสอง", "12.5"))
    with pytest.raises(ValueError):
        short_match("x", text_key, match_mode="fuzzy")


def test_final_answer_value_uses_numeric_rule_when_key_is_numeric():
    key = {"accepted": ["x = 5"], "numeric": {"value": 5, "abs_tol": 0}}
    assert final_answer_value("different") == 0.0
    assert final_answer_value("partial") == 0.5
    assert final_answer_value("different", "5", key) == 1.0  # numeric rule lifts Gemini's category
    assert final_answer_value("partial", "5.2", key) == pytest.approx(max(0.5, numeric_match(5.2, 5, 0)))
    assert final_answer_value("different", "x = 5", key) == 0.0  # not parseable -> category as is
    assert final_answer_value("exact", None, key) == 1.0
