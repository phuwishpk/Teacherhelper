"""Membership functions of DESIGN 11.1."""

import pytest

from fuzzy.membership import build, clamp, high, low, ramp_down, ramp_up, tri


def test_clamp():
    assert clamp(-0.5) == 0.0
    assert clamp(0.0) == 0.0
    assert clamp(0.3) == 0.3
    assert clamp(1.0) == 1.0
    assert clamp(7.0) == 1.0


@pytest.mark.parametrize(
    ("x", "expected"),
    [(-1.0, 0.0), (0.0, 0.0), (0.25, 0.5), (0.5, 1.0), (2.0, 1.0)],
)
def test_ramp_up(x, expected):
    assert ramp_up(x, 0.0, 0.5) == pytest.approx(expected)


@pytest.mark.parametrize(
    ("x", "expected"),
    [(-1.0, 1.0), (0.0, 1.0), (0.25, 0.5), (0.5, 0.0), (2.0, 0.0)],
)
def test_ramp_down(x, expected):
    assert ramp_down(x, 0.0, 0.5) == pytest.approx(expected)


@pytest.mark.parametrize(
    ("x", "expected"),
    [(0.0, 0.0), (0.2, 0.0), (0.35, 0.5), (0.5, 1.0), (0.65, 0.5), (0.8, 0.0), (1.0, 0.0)],
)
def test_tri(x, expected):
    assert tri(x, 0.2, 0.5, 0.8) == pytest.approx(expected)


def test_tri_is_ramp_up_then_ramp_down():
    for i in range(0, 101):
        x = i / 100
        expected = ramp_up(x, 0.2, 0.5) if x <= 0.5 else ramp_down(x, 0.5, 0.8)
        assert tri(x, 0.2, 0.5, 0.8) == pytest.approx(expected)


def test_low_high_are_complements():
    for i in range(0, 11):
        x = i / 10
        assert low(x) == pytest.approx(1.0 - x)
        assert high(x) == pytest.approx(x)
        assert low(x) + high(x) == pytest.approx(1.0)


def test_build_each_type():
    assert build({"type": "ramp_up", "a": 0.5, "b": 1.0})(0.75) == pytest.approx(0.5)
    assert build({"type": "ramp_down", "a": 0.0, "b": 0.5})(0.25) == pytest.approx(0.5)
    assert build({"type": "tri", "a": 0.2, "m": 0.5, "b": 0.8})(0.5) == pytest.approx(1.0)
    assert build({"type": "low"})(0.3) == pytest.approx(0.7)
    assert build({"type": "high"})(0.3) == pytest.approx(0.3)


@pytest.mark.parametrize(
    "spec",
    [
        {"type": "sigmoid"},
        {},
        {"type": "ramp_up", "a": 0.5, "b": 0.5},
        {"type": "ramp_down", "a": 0.7, "b": 0.2},
        {"type": "tri", "a": 0.5, "m": 0.2, "b": 0.8},
    ],
)
def test_build_rejects_bad_specs(spec):
    with pytest.raises(ValueError):
        build(spec)
