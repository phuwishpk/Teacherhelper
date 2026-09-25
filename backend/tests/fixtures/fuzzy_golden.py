"""Generates tests/fixtures/fuzzy_golden.json from the Python reference in ml/fuzzy.

The PHP port (App\\Domain\\Grading) must reproduce ml/fuzzy exactly (DESIGN §11.9);
Tests\\Unit\\Grading\\FuzzyCrossCheckTest replays every case of the JSON.

Regenerate after changing a rule table or a formula on either side:

    cd ml && PYTHONPATH=. uv run --no-sync python ../backend/tests/fixtures/fuzzy_golden.py \
        > ../backend/tests/fixtures/fuzzy_golden.json
"""

from __future__ import annotations

import json
import sys

from fuzzy import (
    ai_score,
    boundary_closeness,
    grade_open,
    grade_short,
    grade_show_work,
    illegibility,
    open_inputs,
    review_priority,
    round_to_step,
)
from fuzzy.matching import final_answer_value, numeric_match, parse_number, short_match, similarity
from fuzzy.priority import blank_disagreement, reader_disagreement, unknown_ratio
from fuzzy.rulesets import STRICTNESS_LEVELS

UNIT = [0.0, 0.1, 0.2, 0.25, 1 / 3, 0.4, 0.5, 0.6, 2 / 3, 0.75, 0.8, 0.9, 1.0]


def result(r):
    return {
        "grading_state": r.grading_state,
        "score_ratio": r.score_ratio,
        "u": r.u,
        "understanding": r.understanding,
        "weights": {t.name: t.weight for t in r.trace.rules},
        "weight_sum": r.trace.weight_sum,
    }


def main() -> None:
    show_work = [
        {"strictness": s, "F": f, "S": x, **result(grade_show_work(f, x, s)),
         "ai_score_5": grade_show_work(f, x, s).score(5)}
        for s in STRICTNESS_LEVELS
        for f in [0.0, 0.25, 0.5, 0.6, 1.0]
        for x in UNIT
    ]
    short = [
        {"strictness": s, "M": m, **result(grade_short(m, s))}
        for s in STRICTNESS_LEVELS
        for m in [i / 20 for i in range(21)] + [0.36, 0.84, 0.86, 0.93]
    ]
    open_ = [
        {"strictness": s, "K": k, "R": x, **result(grade_open(k, x, s))}
        for s in STRICTNESS_LEVELS
        for k in [0.0, 0.25, 0.5, 0.75, 1.0]
        for x in [0.0, 0.25, 1 / 3, 0.5, 2 / 3, 0.75, 1.0]
    ]
    grid = [0.0, 0.25, 0.4, 0.5, 0.6, 1.0]
    priority = []
    for d in grid:
        for l in grid:
            for b in grid:
                r = review_priority(d, l, b)
                priority.append({
                    "D": d, "L": l, "B": b, "p": r.p, "band": r.band,
                    "weights": {t.name: t.weight for t in r.trace.rules},
                })

    num_key = {"accepted": ["12.5"], "numeric": {"value": 12.5, "abs_tol": 0.01}}
    text_key = {"accepted": ["กรุงเทพมหานคร", "กรุงเทพฯ"]}
    spell_key = {"accepted": ["apple"]}
    int_key = {"accepted": ["20"], "numeric": {"value": 20, "abs_tol": 0}}
    short_match_cases = [
        ("กรุงเทพฯ", text_key, "flexible", None),
        ("กรุงเทพ", text_key, "flexible", None),
        ("กรุงเทพ", text_key, "exact", None),
        ("กรุงเทพ", text_key, "flexible", "partial"),
        ("xyz", text_key, "flexible", "partial"),
        ("xyz", text_key, "flexible", "equivalent"),
        ("xyz", text_key, "flexible", "different"),
        ("  Apple ", spell_key, "exact", None),
        ("aple", spell_key, "exact", "exact"),
        ("aple", spell_key, "flexible", None),
        ("๑๒.๕๑", num_key, "flexible", None),
        ("13", num_key, "flexible", None),
        ("12.52", num_key, "flexible", None),
        ("สิบสอง", num_key, "flexible", None),
        ("12.5", num_key, "exact", None),
        ("20", int_key, "flexible", None),
        ("21", int_key, "flexible", "different"),
        ("19.5", int_key, "flexible", None),
        ("-20", int_key, "flexible", None),
        ("20 คน", int_key, "flexible", None),
        ("40/2", int_key, "flexible", None),
        ("", int_key, "flexible", None),
        ("[?]0", int_key, "flexible", "missing"),
    ]
    matching = {
        "short_match": [
            {"answer": a, "answer_key": k, "match_mode": mode, "key_match": km, "M": short_match(a, k, mode, km)}
            for a, k, mode, km in short_match_cases
        ],
        "similarity": [
            {"a": a, "b": b, "sim": similarity(a, b)}
            for a, b in [("กรุงเทพฯ", "กรุงเทพฯ"), ("Bangkok ", "bangkok"), ("abcd", "abce"), ("", "abc"),
                         ("", ""), ("kitten", "sitting"), ("แมว", "แมง"), ("ภาษาไทย", "ภาษา"), ("๑๒๓", "123")]
        ],
        "parse_number": [
            {"text": t, "value": parse_number(t)}
            for t in ["12", " -3.5 ", "๑๒.๕", "3/4", "3,5", "−7", "+4", "abc", "", "[?]", "1/0", "12 คน",
                      "1 2", "-3/4", "0.05", "1e3", "12."]
        ],
        "numeric_match": [
            {"answer": a, "key": k, "abs_tol": t, "M": numeric_match(a, k, t)}
            for a, k, t in [(12.5, 12.5, 0.0), (12.51, 12.5, 0.01), (12.52, 12.5, 0.01), (13.0, 12.5, 0.0),
                            (20.0, 12.5, 0.0), (0.05, 0.0, 0.0), (-27.0, -27.0, 0.0), (5.2, 5.0, 0.0), (6.0, 5.0, 0.0)]
        ],
        "final_answer_value": [
            {"match": m, "text": t, "final_key": k, "F": final_answer_value(m, t, k)}
            for m, t, k in [
                ("different", None, None), ("partial", None, None), ("exact", None, None),
                ("different", "5", {"accepted": ["x = 5"], "numeric": {"value": 5, "abs_tol": 0}}),
                ("partial", "5.2", {"accepted": ["x = 5"], "numeric": {"value": 5, "abs_tol": 0}}),
                ("different", "x = 5", {"accepted": ["x = 5"], "numeric": {"value": 5, "abs_tol": 0}}),
                ("exact", None, {"accepted": ["x = 5"], "numeric": {"value": 5, "abs_tol": 0}}),
                ("missing", "", {"accepted": ["x = 5"], "numeric": {"value": 5, "abs_tol": 0}}),
                ("equivalent", "x=5", {"accepted": ["x = 5"]}),
            ]
        ],
    }
    signals = {
        "boundary_closeness": [{"u": u, "B": boundary_closeness(u)} for u in [i / 20 for i in range(21)] + [0.525, 0.575, 0.45]],
        "illegibility": [
            {"legibility": lg, "text": t, "L": illegibility(lg, t)}
            for lg, t in [("clear", ""), ("readable", ""), ("hard", ""), ("clear", "ab[?]"), ("clear", "[?][?]"),
                          ("readable", "x = [?]5"), ("clear", "3x + 5 = 20 [?]"), ("clear", "   "), ("readable", "ก[?]ข ค ง จ ฉ ช ซ")]
        ],
        "unknown_ratio": [{"text": t, "ratio": unknown_ratio(t)} for t in ["", "abc", "a[?]", "[?] [?]", "ก ข [?]"]],
        "reader_disagreement": [
            {"cnn": c, "gemini": g, "numeric": n, "D": reader_disagreement(c, g, numeric=n)}
            for c, g, n in [("125", "125", True), ("125", "126", True), (None, "125", True), (None, "hello", False),
                            ("5", "5.0", True), ("๕", "5", True), ("3/4", "0.75", True), ("12", None, True), ("ab", "AB", True)]
        ],
        "blank_disagreement": [
            {"blank": b, "ink_ratio": i, "D": blank_disagreement(b, i)}
            for b, i in [(True, 0.05), (True, 0.01), (True, 0.02), (False, 0.5), (True, None)]
        ],
    }
    open_input_cases = [
        [{"level": "met", "points": 2, "is_core": True}, {"level": "partially_met", "points": 1, "is_core": False},
         {"level": "not_met", "points": 1, "is_core": False}],
        [{"level": "not_met", "points": 2, "is_core": True}, {"level": "met", "points": 2, "is_core": False}],
        [{"level": "met", "points": 1, "is_core": False}, {"level": "partially_met", "points": 3, "is_core": False}],
        [{"level": "partially_met", "points": 4, "is_core": True}],
    ]
    open_inputs_out = []
    for criteria in open_input_cases:
        k, r = open_inputs(criteria)
        open_inputs_out.append({"criteria": criteria, "K": k, "R": r})
    rounding = [
        {"score_ratio": r, "max_points": m, "step": s, "ai_score": ai_score(r, m, s), "round_to_step": round_to_step(r * m, s)}
        for r in [0.0, 0.1, 0.25, 1 / 3, 0.5375, 0.55, 0.7, 0.75, 0.9, 1.0]
        for m in [1.0, 2.0, 4.0, 5.0, 10.0]
        for s in [0.5, 0.25, 1.0]
    ]

    out = {
        "_comment": "Generated from ml/fuzzy by backend/tests/fixtures/fuzzy_golden.py; do not edit by hand.",
        "show_work": show_work,
        "short": short,
        "open": open_,
        "review_priority": priority,
        "matching": matching,
        "signals": signals,
        "open_inputs": open_inputs_out,
        "rounding": rounding,
    }
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
