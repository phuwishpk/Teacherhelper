"""Rule tables from DESIGN section 11, kept as plain data.

Each :class:`RuleSet` is the Python twin of one PHP config file
(``show_work``, ``short``, ``open`` for scoring, ``review_priority`` for the
teacher queue). Singleton consequents that depend on the assignment's
``strictness`` are written as ``(lenient, normal, strict)`` triples; call
:meth:`RuleSet.engine` with a strictness level to get a concrete
:class:`~fuzzy.engine.FuzzyEngine`.

Set labels (English identifiers for the Thai names in the design doc):

    F  final answer      wrong / correct          (1 - F, F)
    S  valid-step ratio  few / medium / many      (ramp_down 0-0.5, tri 0.2-0.5-0.8, ramp_up 0.5-1)
    M  key match         mismatch / close / match (ramp_down 0.3-0.6, tri 0.4-0.7-0.95, ramp_up 0.85-1)
    K  core criterion    low / high               (1 - K, K)
    R  other criteria    few / medium / many      (same as S)
    D, L, B              low / high               (1 - x, x)
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal, get_args

from fuzzy.engine import FuzzyEngine, Rule
from fuzzy.membership import MembershipSpec, build

Strictness = Literal["lenient", "normal", "strict"]
STRICTNESS_LEVELS: tuple[Strictness, ...] = get_args(Strictness)

# A consequent value is either a fixed singleton or one singleton per strictness.
Singleton = float | tuple[float, float, float]


@dataclass(frozen=True)
class RuleSpec:
    name: str
    when: dict[str, str]
    then: dict[str, Singleton]
    note: str = ""


@dataclass(frozen=True)
class RuleSet:
    name: str
    variables: dict[str, dict[str, MembershipSpec]]
    outputs: tuple[str, ...]
    rules: tuple[RuleSpec, ...]

    def resolve(self, strictness: Strictness = "normal") -> list[Rule]:
        """Concrete rules for one strictness level."""
        if strictness not in STRICTNESS_LEVELS:
            raise ValueError(f"unknown strictness {strictness!r}, expected one of {STRICTNESS_LEVELS}")
        idx = STRICTNESS_LEVELS.index(strictness)
        rules: list[Rule] = []
        for spec in self.rules:
            then: dict[str, float] = {}
            for output, z in spec.then.items():
                then[output] = float(z[idx]) if isinstance(z, tuple) else float(z)
            rules.append(Rule(spec.name, dict(spec.when), then, spec.note))
        return rules

    def engine(self, strictness: Strictness = "normal") -> FuzzyEngine:
        variables = {
            var: {label: build(spec) for label, spec in sets.items()}
            for var, sets in self.variables.items()
        }
        return FuzzyEngine(variables, self.resolve(strictness), self.outputs)


# --- shared set definitions -------------------------------------------------

LOW_HIGH: dict[str, MembershipSpec] = {
    "low": {"type": "low"},
    "high": {"type": "high"},
}

# S in 11.3 and R in 11.5 ("few / medium / many")
FEW_MEDIUM_MANY: dict[str, MembershipSpec] = {
    "few": {"type": "ramp_down", "a": 0.0, "b": 0.5},
    "medium": {"type": "tri", "a": 0.2, "m": 0.5, "b": 0.8},
    "many": {"type": "ramp_up", "a": 0.5, "b": 1.0},
}


# --- system 1: show_work (11.3) ---------------------------------------------

SHOW_WORK = RuleSet(
    name="show_work",
    variables={
        "F": {"wrong": {"type": "low"}, "correct": {"type": "high"}},
        "S": FEW_MEDIUM_MANY,
    },
    outputs=("score_ratio", "u"),
    rules=(
        RuleSpec("R1", {"F": "correct", "S": "many"}, {"score_ratio": (1.00, 1.00, 1.00), "u": 1.0},
                 "understands well"),
        RuleSpec("R2", {"F": "correct", "S": "medium"}, {"score_ratio": (0.85, 0.80, 0.70), "u": 0.7}),
        RuleSpec("R3", {"F": "correct", "S": "few"}, {"score_ratio": (0.60, 0.50, 0.30), "u": 0.4},
                 "answer right but method wrong: guessed or copied"),
        RuleSpec("R4", {"F": "wrong", "S": "many"}, {"score_ratio": (0.70, 0.60, 0.40), "u": 0.6},
                 "method right, slipped at the end: partial understanding"),
        RuleSpec("R5", {"F": "wrong", "S": "medium"}, {"score_ratio": (0.45, 0.35, 0.20), "u": 0.3}),
        RuleSpec("R6", {"F": "wrong", "S": "few"}, {"score_ratio": (0.00, 0.00, 0.00), "u": 0.0}),
    ),
)


# --- system 1: short (11.4) --------------------------------------------------

SHORT = RuleSet(
    name="short",
    variables={
        "M": {
            "mismatch": {"type": "ramp_down", "a": 0.3, "b": 0.6},
            "close": {"type": "tri", "a": 0.4, "m": 0.7, "b": 0.95},
            "match": {"type": "ramp_up", "a": 0.85, "b": 1.0},
        },
    },
    outputs=("score_ratio", "u"),
    rules=(
        RuleSpec("S1", {"M": "mismatch"}, {"score_ratio": (0.0, 0.0, 0.0), "u": 0.0}),
        RuleSpec("S2", {"M": "close"}, {"score_ratio": (0.7, 0.5, 0.0), "u": 0.5}),
        RuleSpec("S3", {"M": "match"}, {"score_ratio": (1.0, 1.0, 1.0), "u": 1.0}),
    ),
)


# --- system 1: open (11.5) ---------------------------------------------------

OPEN = RuleSet(
    name="open",
    variables={
        "K": LOW_HIGH,
        "R": FEW_MEDIUM_MANY,
    },
    outputs=("score_ratio", "u"),
    rules=(
        RuleSpec("O1", {"K": "high", "R": "many"}, {"score_ratio": (1.00, 1.00, 1.00), "u": 1.0}),
        RuleSpec("O2", {"K": "high", "R": "medium"}, {"score_ratio": (0.85, 0.80, 0.70), "u": 0.75}),
        RuleSpec("O3", {"K": "high", "R": "few"}, {"score_ratio": (0.70, 0.60, 0.50), "u": 0.55},
                 "has the core but little detail"),
        RuleSpec("O4", {"K": "low", "R": "many"}, {"score_ratio": (0.55, 0.45, 0.30), "u": 0.35},
                 "all the pieces but missed the core: below O3"),
        RuleSpec("O5", {"K": "low", "R": "medium"}, {"score_ratio": (0.35, 0.25, 0.10), "u": 0.2}),
        RuleSpec("O6", {"K": "low", "R": "few"}, {"score_ratio": (0.00, 0.00, 0.00), "u": 0.0}),
    ),
)


# --- system 2: review priority (11.8) ---------------------------------------

REVIEW_PRIORITY = RuleSet(
    name="review_priority",
    variables={
        "D": LOW_HIGH,  # readers disagree
        "L": LOW_HIGH,  # handwriting hard to read
        "B": LOW_HIGH,  # u near an understanding boundary
    },
    outputs=("p",),
    rules=(
        RuleSpec("P1", {"D": "high"}, {"p": 1.0}, "readers disagree"),
        RuleSpec("P2", {"L": "high"}, {"p": 0.8}, "hard to read"),
        RuleSpec("P3", {"B": "high"}, {"p": 0.6}, "near an understanding boundary"),
        RuleSpec("P4", {"D": "low", "L": "low", "B": "low"}, {"p": 0.0}, "nothing suspicious"),
    ),
)


SCORING_RULESETS: dict[str, RuleSet] = {
    SHOW_WORK.name: SHOW_WORK,
    SHORT.name: SHORT,
    OPEN.name: OPEN,
}

ALL_RULESETS: dict[str, RuleSet] = {**SCORING_RULESETS, REVIEW_PRIORITY.name: REVIEW_PRIORITY}
