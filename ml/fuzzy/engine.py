"""Generic Mamdani-style fuzzy engine from DESIGN section 11.1.

* antecedents are conjunctions over labelled sets, AND = ``min``
* consequents are singletons (one constant per output)
* defuzzification is the weighted average ``y = sum(w_i * z_i) / sum(w_i)``
* every evaluation returns a full trace (inputs, memberships, per-rule
  weights) so the teacher UI can show "why this score"
* if ``sum(w_i) == 0`` the result is *degenerate*: no outputs are produced and
  the caller must set ``grading_state = manual`` (DESIGN 11.1)

This is the Python twin of the PHP ``FuzzyEngine``; the rule tables that feed
it live in :mod:`fuzzy.rulesets`.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from typing import Any

from fuzzy.membership import MembershipFn

_EPS = 1e-9


@dataclass(frozen=True)
class Rule:
    """``IF (var_1 is set_1) AND (var_2 is set_2) ... THEN output_k = z_k``."""

    name: str
    when: Mapping[str, str]
    then: Mapping[str, float]
    note: str = ""

    def __post_init__(self) -> None:
        if not self.when:
            raise ValueError(f"rule {self.name}: antecedent must not be empty")
        if not self.then:
            raise ValueError(f"rule {self.name}: consequent must not be empty")


@dataclass(frozen=True)
class RuleTrace:
    name: str
    weight: float
    then: dict[str, float]
    note: str = ""


@dataclass(frozen=True)
class FuzzyResult:
    """Outputs plus the complete trace of one evaluation."""

    inputs: dict[str, float]
    memberships: dict[str, dict[str, float]]
    rules: list[RuleTrace]
    weight_sum: float
    outputs: dict[str, float] = field(default_factory=dict)

    @property
    def is_degenerate(self) -> bool:
        """True when no rule fired, so no output could be defuzzified."""
        return self.weight_sum <= 0.0

    def fired(self) -> list[RuleTrace]:
        """Rules with a non-zero weight, strongest first."""
        return sorted(
            (r for r in self.rules if r.weight > 0.0),
            key=lambda r: r.weight,
            reverse=True,
        )

    def to_dict(self) -> dict[str, Any]:
        """JSON-able form matching the ``responses.fuzzy_trace`` column."""
        return {
            "inputs": dict(self.inputs),
            "memberships": {v: dict(s) for v, s in self.memberships.items()},
            "rules": [
                {"name": r.name, "weight": r.weight, "then": dict(r.then), "note": r.note}
                for r in self.rules
            ],
            "weight_sum": self.weight_sum,
            "outputs": dict(self.outputs),
            "degenerate": self.is_degenerate,
        }


class FuzzyEngine:
    """Evaluate a fixed set of rules over labelled fuzzy variables."""

    def __init__(
        self,
        variables: Mapping[str, Mapping[str, MembershipFn]],
        rules: Sequence[Rule],
        outputs: Sequence[str],
    ) -> None:
        if not variables:
            raise ValueError("engine needs at least one variable")
        if not rules:
            raise ValueError("engine needs at least one rule")
        if not outputs:
            raise ValueError("engine needs at least one output")
        self.variables: dict[str, dict[str, MembershipFn]] = {
            name: dict(sets) for name, sets in variables.items()
        }
        self.outputs: tuple[str, ...] = tuple(outputs)
        self.rules: tuple[Rule, ...] = tuple(rules)
        self._validate_rules()

    def _validate_rules(self) -> None:
        seen: set[str] = set()
        for rule in self.rules:
            if rule.name in seen:
                raise ValueError(f"duplicate rule name {rule.name!r}")
            seen.add(rule.name)
            for var, label in rule.when.items():
                if var not in self.variables:
                    raise ValueError(f"rule {rule.name}: unknown variable {var!r}")
                if label not in self.variables[var]:
                    raise ValueError(f"rule {rule.name}: variable {var!r} has no set {label!r}")
            missing = set(self.outputs) - set(rule.then)
            extra = set(rule.then) - set(self.outputs)
            if missing or extra:
                raise ValueError(
                    f"rule {rule.name}: consequent must define exactly {self.outputs}, "
                    f"missing={sorted(missing)} extra={sorted(extra)}"
                )

    def fuzzify(self, inputs: Mapping[str, float]) -> dict[str, dict[str, float]]:
        """Membership of every input in every set of its variable."""
        missing = set(self.variables) - set(inputs)
        if missing:
            raise ValueError(f"missing inputs: {sorted(missing)}")
        result: dict[str, dict[str, float]] = {}
        for var, sets in self.variables.items():
            x = _check_unit(var, inputs[var])
            result[var] = {label: float(fn(x)) for label, fn in sets.items()}
        return result

    def evaluate(self, inputs: Mapping[str, float]) -> FuzzyResult:
        memberships = self.fuzzify(inputs)
        traces: list[RuleTrace] = []
        weight_sum = 0.0
        acc: dict[str, float] = {name: 0.0 for name in self.outputs}
        for rule in self.rules:
            # AND = min over the antecedent clauses
            weight = min(memberships[var][label] for var, label in rule.when.items())
            traces.append(RuleTrace(rule.name, weight, dict(rule.then), rule.note))
            if weight > 0.0:
                weight_sum += weight
                for name in self.outputs:
                    acc[name] += weight * rule.then[name]
        outputs: dict[str, float] = {}
        if weight_sum > 0.0:
            outputs = {name: acc[name] / weight_sum for name in self.outputs}
        return FuzzyResult(
            inputs={var: float(inputs[var]) for var in self.variables},
            memberships=memberships,
            rules=traces,
            weight_sum=weight_sum,
            outputs=outputs,
        )


def _check_unit(name: str, value: float) -> float:
    """Inputs are ratios in [0, 1]; tolerate float noise, reject anything else."""
    x = float(value)
    if x != x:  # NaN
        raise ValueError(f"input {name!r} is NaN")
    if x < -_EPS or x > 1.0 + _EPS:
        raise ValueError(f"input {name!r} must be within [0, 1], got {x}")
    return min(1.0, max(0.0, x))
