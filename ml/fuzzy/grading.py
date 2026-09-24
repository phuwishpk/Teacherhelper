"""Fuzzy system 1 (scoring) from DESIGN sections 11.3-11.7.

The functions here turn the numbers produced by :mod:`fuzzy.scale` and
:mod:`fuzzy.matching` into ``score_ratio`` and ``u`` through the rule tables in
:mod:`fuzzy.rulesets`, then apply the understanding bands and the score
rounding of 11.7. Multiple choice (11.6) and blank answers (11.1) bypass fuzzy.
"""

from __future__ import annotations

import math
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from typing import Any, Literal

from fuzzy.engine import FuzzyResult
from fuzzy.rulesets import OPEN, SCORING_RULESETS, SHORT, SHOW_WORK, RuleSet, Strictness
from fuzzy.scale import criteria_level_value

Understanding = Literal["good", "partial", "not_yet"]
GradingState = Literal["scored", "manual"]

UNDERSTANDING_GOOD_MIN = 0.75
UNDERSTANDING_PARTIAL_MIN = 0.4

# 11.6: a bubble counts as filled at this ratio and up
MCQ_FILL_MIN = 0.45
# 11.6: a bubble between these values is "ambiguous" (feeds D of system 2)
MCQ_AMBIGUOUS_MIN = 0.2
MCQ_AMBIGUITY_MULTI = 1.0
MCQ_AMBIGUITY_FAINT = 0.6

DEFAULT_SCORE_STEP = 0.5


def understanding_level(u: float) -> Understanding:
    """11.7: good (u >= 0.75), partial (0.4 <= u < 0.75), not_yet (u < 0.4)."""
    if u >= UNDERSTANDING_GOOD_MIN:
        return "good"
    if u >= UNDERSTANDING_PARTIAL_MIN:
        return "partial"
    return "not_yet"


def round_to_step(value: float, step: float = DEFAULT_SCORE_STEP) -> float:
    """Round to the nearest ``step`` (0.5 by default; 1 or 0.25 are allowed too).

    Ties round away from zero, like PHP ``round()``, so the PHP port matches.
    """
    if step <= 0:
        raise ValueError("step must be > 0")
    scaled = value / step
    return math.floor(abs(scaled) + 0.5) * math.copysign(step, scaled)


def ai_score(score_ratio: float, max_points: float, step: float = DEFAULT_SCORE_STEP) -> float:
    """11.7: ``ai_score = round_half(score_ratio x max_points)``."""
    return round_to_step(score_ratio * max_points, step)


@dataclass(frozen=True)
class GradeResult:
    question_type: str
    strictness: Strictness
    grading_state: GradingState
    score_ratio: float | None
    u: float | None
    understanding: Understanding | None
    trace: FuzzyResult | None

    def score(self, max_points: float, step: float = DEFAULT_SCORE_STEP) -> float | None:
        if self.score_ratio is None:
            return None
        return ai_score(self.score_ratio, max_points, step)

    def to_dict(self) -> dict[str, Any]:
        return {
            "question_type": self.question_type,
            "strictness": self.strictness,
            "grading_state": self.grading_state,
            "score_ratio": self.score_ratio,
            "u": self.u,
            "understanding": self.understanding,
            "trace": self.trace.to_dict() if self.trace is not None else None,
        }


def _run(ruleset: RuleSet, inputs: Mapping[str, float], strictness: Strictness) -> GradeResult:
    result = ruleset.engine(strictness).evaluate(inputs)
    if result.is_degenerate:
        # 11.1: sum of weights is zero -> the teacher grades this one by hand
        return GradeResult(ruleset.name, strictness, "manual", None, None, None, result)
    score_ratio = result.outputs["score_ratio"]
    u = result.outputs["u"]
    return GradeResult(ruleset.name, strictness, "scored", score_ratio, u, understanding_level(u), result)


def grade_show_work(final_match: float, step_ratio: float, strictness: Strictness = "normal") -> GradeResult:
    """11.3: ``F`` = final-answer correctness, ``S`` = share of valid steps."""
    return _run(SHOW_WORK, {"F": final_match, "S": step_ratio}, strictness)


def grade_short(match: float, strictness: Strictness = "normal") -> GradeResult:
    """11.4: ``M`` = how well the answer matches the key (see :mod:`fuzzy.matching`)."""
    return _run(SHORT, {"M": match}, strictness)


def grade_open(core: float, rest: float, strictness: Strictness = "normal") -> GradeResult:
    """11.5: ``K`` = level of the core criterion, ``R`` = points-weighted mean of the others."""
    return _run(OPEN, {"K": core, "R": rest}, strictness)


def grade(question_type: str, inputs: Mapping[str, float], strictness: Strictness = "normal") -> GradeResult:
    """Dispatch on ``questions.type`` (``mcq`` is not fuzzy, see :func:`grade_mcq`)."""
    try:
        ruleset = SCORING_RULESETS[question_type]
    except KeyError:
        raise ValueError(f"no fuzzy ruleset for question type {question_type!r}") from None
    return _run(ruleset, inputs, strictness)


def grade_blank(question_type: str, strictness: Strictness = "normal") -> GradeResult:
    """11.1: Gemini says ``blank`` with no conflicting signal -> score 0, u 0, no fuzzy."""
    return GradeResult(question_type, strictness, "scored", 0.0, 0.0, "not_yet", None)


# --- input helpers ------------------------------------------------------------


def step_ratio(steps: Sequence[Mapping[str, Any]] | Sequence[bool]) -> float:
    """``S`` of 11.3: valid steps / all steps, 0 when there are no steps.

    Accepts Gemini's ``steps`` objects (``{"line", "text", "valid", ...}``) or plain booleans.
    """
    if not steps:
        return 0.0
    valid = 0
    for step in steps:
        flag = step["valid"] if isinstance(step, Mapping) else step
        valid += 1 if bool(flag) else 0
    return valid / len(steps)


def open_inputs(criteria: Sequence[Mapping[str, Any]]) -> tuple[float, float]:
    """``(K, R)`` of 11.5 from ``[{"level", "points", "is_core"}, ...]``.

    ``K`` is the level of the single ``is_core`` criterion and ``R`` the
    points-weighted mean of the rest. Without a core criterion both equal the
    points-weighted mean of all criteria.
    """
    if not criteria:
        raise ValueError("open question needs at least one rubric criterion")
    core = [c for c in criteria if c.get("is_core")]
    if len(core) > 1:
        raise ValueError("open question must have at most one core criterion")
    if not core:
        mean = _weighted_mean(criteria)
        return mean, mean
    rest = [c for c in criteria if not c.get("is_core")]
    k = criteria_level_value(core[0]["level"])
    r = _weighted_mean(rest) if rest else k
    return k, r


def _weighted_mean(criteria: Sequence[Mapping[str, Any]]) -> float:
    total = sum(float(c["points"]) for c in criteria)
    if total <= 0:
        raise ValueError("rubric points must sum to a positive number")
    return sum(float(c["points"]) * criteria_level_value(c["level"]) for c in criteria) / total


# --- multiple choice (11.6, not fuzzy) ---------------------------------------


@dataclass(frozen=True)
class McqResult:
    score_ratio: float
    u: float
    understanding: Understanding
    filled: tuple[str, ...]
    ambiguity: float  # D of system 2 (11.6 / 11.8)

    @property
    def grading_state(self) -> GradingState:
        return "scored"


def grade_mcq(fills: Mapping[str, float], correct: str) -> McqResult:
    """11.6: one filled bubble (fill >= 0.45) matching the key scores 1, anything else 0.

    ``u`` mirrors the score because there is no method to judge. ``ambiguity``
    is 1 for several filled bubbles, 0.6 when some bubble sits between 0.2 and
    0.45, else 0.
    """
    if not fills:
        raise ValueError("mcq needs bubble fill ratios")
    filled = tuple(option for option, fill in fills.items() if fill >= MCQ_FILL_MIN)
    score = 1.0 if filled == (correct,) else 0.0
    if len(filled) > 1:
        ambiguity = MCQ_AMBIGUITY_MULTI
    elif any(MCQ_AMBIGUOUS_MIN <= fill < MCQ_FILL_MIN for fill in fills.values()):
        ambiguity = MCQ_AMBIGUITY_FAINT
    else:
        ambiguity = 0.0
    return McqResult(score, score, understanding_level(score), filled, ambiguity)
