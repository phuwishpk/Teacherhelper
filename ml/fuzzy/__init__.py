"""EduVision fuzzy-logic reference implementation (DESIGN section 11).

Python twin of the PHP ``FuzzyEngine`` used for unit-test parity, membership
and surface plots for the AI-course report, and tuning experiments in Phase 4.

Modules
    membership  linear membership functions (11.1)
    engine      generic min/singleton/weighted-average engine with traces (11.1)
    rulesets    rule tables for show_work, short, open (11.3-11.5) and review priority (11.8)
    scale       Gemini category -> number (11.2)
    matching    answer-key matching, input M and numeric F (11.4)
    grading     system 1 wrappers, mcq, rounding and understanding bands (11.3-11.7)
    priority    system 2 wrappers and its D / L / B inputs (11.8)
"""

from fuzzy.engine import FuzzyEngine, FuzzyResult, Rule, RuleTrace
from fuzzy.grading import (
    GradeResult,
    McqResult,
    ai_score,
    grade,
    grade_blank,
    grade_mcq,
    grade_open,
    grade_short,
    grade_show_work,
    open_inputs,
    round_to_step,
    step_ratio,
    understanding_level,
)
from fuzzy.membership import clamp, high, low, ramp_down, ramp_up, tri
from fuzzy.priority import (
    PriorityResult,
    boundary_closeness,
    disagreement,
    illegibility,
    priority_band,
    review_priority,
)
from fuzzy.rulesets import (
    ALL_RULESETS,
    OPEN,
    REVIEW_PRIORITY,
    SHORT,
    SHOW_WORK,
    STRICTNESS_LEVELS,
    RuleSet,
    Strictness,
)

__all__ = [
    "ALL_RULESETS",
    "OPEN",
    "REVIEW_PRIORITY",
    "SHORT",
    "SHOW_WORK",
    "STRICTNESS_LEVELS",
    "FuzzyEngine",
    "FuzzyResult",
    "GradeResult",
    "McqResult",
    "PriorityResult",
    "Rule",
    "RuleSet",
    "RuleTrace",
    "Strictness",
    "ai_score",
    "boundary_closeness",
    "clamp",
    "disagreement",
    "grade",
    "grade_blank",
    "grade_mcq",
    "grade_open",
    "grade_short",
    "grade_show_work",
    "high",
    "illegibility",
    "low",
    "open_inputs",
    "priority_band",
    "ramp_down",
    "ramp_up",
    "review_priority",
    "round_to_step",
    "step_ratio",
    "tri",
    "understanding_level",
]
