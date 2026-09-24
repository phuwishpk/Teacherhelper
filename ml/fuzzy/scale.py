"""Category -> number conversions from DESIGN section 11.2.

Gemini always answers with categories (``exact``, ``met`` ...); the numbers
below are the only place those categories become inputs of the fuzzy systems.
"""

from __future__ import annotations

MATCH_CATEGORIES = ("exact", "equivalent", "partial", "different", "missing")
CRITERIA_LEVELS = ("met", "partially_met", "not_met")
LEGIBILITY_LEVELS = ("clear", "readable", "hard")

# `partial` is worth 0.5, except for `short` where it is 0.6 (11.2)
PARTIAL_MATCH_DEFAULT = 0.5
PARTIAL_MATCH_SHORT = 0.6

_CRITERIA_VALUE = {"met": 1.0, "partially_met": 0.5, "not_met": 0.0}
# Higher = harder to read; the value is used as an "illegibility" signal (11.8)
_LEGIBILITY_VALUE = {"clear": 0.0, "readable": 0.4, "hard": 1.0}


def match_value(category: str, question_type: str = "show_work") -> float:
    """``final_answer_match`` / ``key_match`` category -> 0..1."""
    if category not in MATCH_CATEGORIES:
        raise ValueError(f"unknown match category {category!r}")
    if category in ("exact", "equivalent"):
        return 1.0
    if category == "partial":
        return PARTIAL_MATCH_SHORT if question_type == "short" else PARTIAL_MATCH_DEFAULT
    return 0.0


def criteria_level_value(level: str) -> float:
    """``criteria[].level`` -> 0..1."""
    try:
        return _CRITERIA_VALUE[level]
    except KeyError:
        raise ValueError(f"unknown criteria level {level!r}") from None


def legibility_value(legibility: str) -> float:
    """``legibility`` -> illegibility 0..1 (clear 0, readable 0.4, hard 1)."""
    try:
        return _LEGIBILITY_VALUE[legibility]
    except KeyError:
        raise ValueError(f"unknown legibility {legibility!r}") from None
