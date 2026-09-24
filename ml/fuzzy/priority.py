"""Fuzzy system 2 (teacher review queue) from DESIGN section 11.8.

Inputs, all in 0..1:

    D  readers disagree   max of: CNN vs Gemini on a numeric box (same 0, differ 1,
                          CNN abstained 0.5), MCQ ambiguity (11.6), Gemini says
                          blank but ink_ratio > 0.02 (1)
    L  hard to read       max(legibility value (11.2), min(1, 5 x share of [?] characters))
    B  near a boundary    clamp(1 - min(|u - 0.4|, |u - 0.75|) / 0.1)

Output ``p`` maps to ``priority_band``: check (p >= 0.5), look (0.2 <= p < 0.5),
confident (p < 0.2). Two cases skip the fuzzy step: ``suspicious_instruction``
forces ``p = 1`` with a "suspicious" flag, and ``grading_state = manual`` goes to
the top of the queue as "manual".
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Literal

from fuzzy.engine import FuzzyResult
from fuzzy.grading import UNDERSTANDING_GOOD_MIN, UNDERSTANDING_PARTIAL_MIN
from fuzzy.matching import normalize_text, parse_number
from fuzzy.membership import clamp
from fuzzy.rulesets import REVIEW_PRIORITY
from fuzzy.scale import legibility_value

PriorityBand = Literal["check", "look", "confident"]
PriorityFlag = Literal["suspicious", "manual"]

BAND_CHECK_MIN = 0.5
BAND_LOOK_MIN = 0.2

# 11.8, D: CNN did not answer on a numeric box
CNN_ABSTAINED_DISAGREEMENT = 0.5
# 11.8, D: Gemini says blank but the box has ink above this ratio
BLANK_INK_RATIO_MAX = 0.02
# 11.8, L: [?] share is scaled by this factor before clamping
UNKNOWN_CHAR_SCALE = 5.0
# 11.8, B: distance to a boundary that still counts as "near"
BOUNDARY_WIDTH = 0.1

UNKNOWN_MARK = "[?]"


def priority_band(p: float) -> PriorityBand:
    if p >= BAND_CHECK_MIN:
        return "check"
    if p >= BAND_LOOK_MIN:
        return "look"
    return "confident"


@dataclass(frozen=True)
class PriorityResult:
    p: float
    band: PriorityBand
    flag: PriorityFlag | None
    trace: FuzzyResult | None

    @property
    def bulk_approvable(self) -> bool:
        """Only confident, unflagged responses may be approved in bulk (9.5, 10.7)."""
        return self.flag is None and self.band == "confident"

    def sort_key(self) -> tuple[int, float]:
        """Queue order: manual first, then suspicious, then by descending ``p``."""
        rank = {"manual": 0, "suspicious": 1}.get(self.flag or "", 2)
        return rank, -self.p

    def to_dict(self) -> dict[str, Any]:
        return {
            "p": self.p,
            "band": self.band,
            "flag": self.flag,
            "trace": self.trace.to_dict() if self.trace is not None else None,
        }


def review_priority(
    disagreement: float,
    illegibility: float,
    boundary: float,
    *,
    suspicious_instruction: bool = False,
    grading_state: str = "scored",
) -> PriorityResult:
    """Run system 2 on ``(D, L, B)`` with the two special cases of 11.8."""
    if grading_state == "manual":
        return PriorityResult(1.0, "check", "manual", None)
    if suspicious_instruction:
        return PriorityResult(1.0, "check", "suspicious", None)
    result = REVIEW_PRIORITY.engine().evaluate({"D": disagreement, "L": illegibility, "B": boundary})
    if result.is_degenerate:
        # cannot happen with the 11.8 table (P1-P3 or P4 always fires) but never crash the queue
        return PriorityResult(1.0, "check", "manual", result)
    p = result.outputs["p"]
    return PriorityResult(p, priority_band(p), None, result)


# --- input helpers ------------------------------------------------------------


def reader_disagreement(cnn_text: str | None, gemini_text: str | None, *, numeric: bool) -> float:
    """Case 1 of ``D``: second reader (CNN) vs Gemini on a numeric box.

    Not a numeric box -> 0. CNN abstained (``None``) -> 0.5. Otherwise the two
    readings are compared as numbers when both parse, else as normalized text.
    """
    if not numeric:
        return 0.0
    if cnn_text is None:
        return CNN_ABSTAINED_DISAGREEMENT
    gemini = "" if gemini_text is None else gemini_text
    a, b = parse_number(cnn_text), parse_number(gemini)
    if a is not None and b is not None:
        return 0.0 if a == b else 1.0
    return 0.0 if normalize_text(cnn_text) == normalize_text(gemini) else 1.0


def blank_disagreement(blank: bool, ink_ratio: float | None) -> float:
    """Case 3 of ``D``: Gemini reports blank but the crop has ink."""
    if blank and ink_ratio is not None and ink_ratio > BLANK_INK_RATIO_MAX:
        return 1.0
    return 0.0


def disagreement(
    *,
    numeric: bool = False,
    cnn_text: str | None = None,
    gemini_text: str | None = None,
    mcq_ambiguity: float = 0.0,
    blank: bool = False,
    ink_ratio: float | None = None,
) -> float:
    """``D`` = max of the three cases in 11.8."""
    return max(
        reader_disagreement(cnn_text, gemini_text, numeric=numeric),
        clamp(mcq_ambiguity),
        blank_disagreement(blank, ink_ratio),
    )


def unknown_ratio(text: str) -> float:
    """Share of ``[?]`` marks among the transcribed characters (whitespace ignored).

    Each ``[?]`` counts as one unreadable character.
    """
    unknown = text.count(UNKNOWN_MARK)
    rest = len("".join(text.replace(UNKNOWN_MARK, "").split()))
    total = unknown + rest
    if total == 0:
        return 0.0
    return unknown / total


def illegibility(legibility: str, transcription: str = "") -> float:
    """``L`` = max(legibility value, min(1, 5 x share of [?]))."""
    return max(legibility_value(legibility), min(1.0, UNKNOWN_CHAR_SCALE * unknown_ratio(transcription)))


def boundary_closeness(u: float) -> float:
    """``B`` = clamp(1 - min(|u - 0.4|, |u - 0.75|) / 0.1)."""
    distance = min(abs(u - UNDERSTANDING_PARTIAL_MIN), abs(u - UNDERSTANDING_GOOD_MIN))
    return clamp(1.0 - distance / BOUNDARY_WIDTH)
