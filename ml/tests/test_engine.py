"""Generic engine behaviour of DESIGN 11.1: AND = min, singleton consequents,
weighted-average defuzzification, full trace, degenerate case."""

import json
import math

import pytest

from fuzzy.engine import FuzzyEngine, Rule
from fuzzy.membership import high, low, ramp_down, ramp_up


@pytest.fixture
def engine() -> FuzzyEngine:
    variables = {
        "A": {"low": low, "high": high},
        "B": {"low": low, "high": high},
    }
    rules = [
        Rule("X1", {"A": "high", "B": "high"}, {"y": 1.0}),
        Rule("X2", {"A": "high", "B": "low"}, {"y": 0.5}, note="half"),
        Rule("X3", {"A": "low"}, {"y": 0.0}),
    ]
    return FuzzyEngine(variables, rules, outputs=["y"])


def test_and_is_min_and_output_is_weighted_average(engine):
    result = engine.evaluate({"A": 0.8, "B": 0.25})
    weights = {r.name: r.weight for r in result.rules}
    assert weights == pytest.approx({"X1": 0.25, "X2": 0.75, "X3": 0.2})
    total = 0.25 + 0.75 + 0.2
    assert result.weight_sum == pytest.approx(total)
    assert result.outputs["y"] == pytest.approx((0.25 * 1.0 + 0.75 * 0.5) / total)
    assert not result.is_degenerate


def test_trace_contains_inputs_memberships_and_rules(engine):
    result = engine.evaluate({"A": 0.8, "B": 0.25})
    assert result.inputs == {"A": 0.8, "B": 0.25}
    assert result.memberships["A"] == pytest.approx({"low": 0.2, "high": 0.8})
    assert result.memberships["B"] == pytest.approx({"low": 0.75, "high": 0.25})
    assert [r.name for r in result.fired()] == ["X2", "X1", "X3"]
    payload = result.to_dict()
    assert set(payload) == {"inputs", "memberships", "rules", "weight_sum", "outputs", "degenerate"}
    assert payload["rules"][1]["note"] == "half"
    json.dumps(payload)  # must be storable in responses.fuzzy_trace


def test_degenerate_when_no_rule_fires():
    engine = FuzzyEngine(
        {"A": {"tiny": lambda x: ramp_down(x, 0.0, 0.2), "huge": lambda x: ramp_up(x, 0.8, 1.0)}},
        [Rule("T", {"A": "tiny"}, {"y": 0.0}), Rule("H", {"A": "huge"}, {"y": 1.0})],
        ["y"],
    )
    result = engine.evaluate({"A": 0.5})
    assert result.is_degenerate
    assert result.weight_sum == 0.0
    assert result.outputs == {}
    assert result.fired() == []
    assert result.to_dict()["degenerate"] is True


def test_multiple_outputs_share_weights():
    engine = FuzzyEngine(
        {"A": {"low": low, "high": high}},
        [Rule("L", {"A": "low"}, {"z": 0.0, "u": 0.1}), Rule("H", {"A": "high"}, {"z": 1.0, "u": 0.9})],
        ["z", "u"],
    )
    result = engine.evaluate({"A": 0.25})
    assert result.outputs == pytest.approx({"z": 0.25, "u": 0.75 * 0.1 + 0.25 * 0.9})


def test_missing_input_is_an_error(engine):
    with pytest.raises(ValueError, match="missing inputs"):
        engine.evaluate({"A": 0.5})


@pytest.mark.parametrize("bad", [-0.01, 1.01, math.nan])
def test_out_of_range_input_is_an_error(engine, bad):
    with pytest.raises(ValueError):
        engine.evaluate({"A": bad, "B": 0.5})


def test_float_noise_is_tolerated(engine):
    result = engine.evaluate({"A": 1.0 + 1e-12, "B": -1e-12})
    assert result.memberships["A"]["high"] == 1.0
    assert result.memberships["B"]["low"] == 1.0


def test_rule_validation():
    variables = {"A": {"low": low, "high": high}}
    with pytest.raises(ValueError, match="unknown variable"):
        FuzzyEngine(variables, [Rule("R", {"Z": "low"}, {"y": 0.0})], ["y"])
    with pytest.raises(ValueError, match="has no set"):
        FuzzyEngine(variables, [Rule("R", {"A": "medium"}, {"y": 0.0})], ["y"])
    with pytest.raises(ValueError, match="consequent must define exactly"):
        FuzzyEngine(variables, [Rule("R", {"A": "low"}, {"y": 0.0, "extra": 1.0})], ["y"])
    with pytest.raises(ValueError, match="consequent must define exactly"):
        FuzzyEngine(variables, [Rule("R", {"A": "low"}, {"y": 0.0})], ["y", "u"])
    with pytest.raises(ValueError, match="duplicate rule name"):
        FuzzyEngine(variables, [Rule("R", {"A": "low"}, {"y": 0.0}), Rule("R", {"A": "high"}, {"y": 1.0})], ["y"])
    with pytest.raises(ValueError, match="antecedent"):
        Rule("R", {}, {"y": 0.0})
    with pytest.raises(ValueError, match="consequent"):
        Rule("R", {"A": "low"}, {})
    with pytest.raises(ValueError):
        FuzzyEngine(variables, [], ["y"])
    with pytest.raises(ValueError):
        FuzzyEngine(variables, [Rule("R", {"A": "low"}, {"y": 0.0})], [])
