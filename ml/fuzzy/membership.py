"""Membership functions from DESIGN section 11.1.

Every function is piecewise linear and clamped to [0, 1]:

    ramp_up(x; a, b)   = clamp((x - a) / (b - a))    0 when x <= a, 1 when x >= b
    ramp_down(x; a, b) = clamp((b - x) / (b - a))    1 when x <= a, 0 when x >= b
    tri(x; a, m, b)    = ramp_up(x; a, m) when x <= m, ramp_down(x; m, b) when x > m
    clamp(v)           = min(1, max(0, v))

Two-valued variables (F, K, D, L, B) use the degenerate pair
``low(x) = 1 - x`` and ``high(x) = x``.

``build`` turns a JSON-able spec (the same shape the PHP FuzzyEngine config
arrays use) into a callable, so rule tables can stay declarative.
"""

from __future__ import annotations

from collections.abc import Callable, Mapping
from typing import Any

MembershipFn = Callable[[float], float]


def clamp(v: float) -> float:
    """Clamp ``v`` into [0, 1]."""
    return min(1.0, max(0.0, v))


def ramp_up(x: float, a: float, b: float) -> float:
    """0 for x <= a, rising linearly to 1 at x >= b."""
    return clamp((x - a) / (b - a))


def ramp_down(x: float, a: float, b: float) -> float:
    """1 for x <= a, falling linearly to 0 at x >= b."""
    return clamp((b - x) / (b - a))


def tri(x: float, a: float, m: float, b: float) -> float:
    """Triangle with feet at ``a`` and ``b`` and peak 1 at ``m``."""
    if x <= m:
        return ramp_up(x, a, m)
    return ramp_down(x, m, b)


def low(x: float) -> float:
    """Membership of "low" for a two-valued variable: 1 - x."""
    return clamp(1.0 - x)


def high(x: float) -> float:
    """Membership of "high" for a two-valued variable: x."""
    return clamp(x)


# Spec format (JSON-able, mirrors the PHP config arrays):
#   {"type": "ramp_up",   "a": 0.5, "b": 1.0}
#   {"type": "ramp_down", "a": 0.0, "b": 0.5}
#   {"type": "tri",       "a": 0.2, "m": 0.5, "b": 0.8}
#   {"type": "low"}   -> 1 - x
#   {"type": "high"}  -> x
MembershipSpec = Mapping[str, Any]


def build(spec: MembershipSpec) -> MembershipFn:
    """Turn a membership spec into a callable ``f(x) -> mu``."""
    kind = spec.get("type")
    if kind == "ramp_up":
        a, b = _ab(spec)
        return lambda x: ramp_up(x, a, b)
    if kind == "ramp_down":
        a, b = _ab(spec)
        return lambda x: ramp_down(x, a, b)
    if kind == "tri":
        a, m, b = float(spec["a"]), float(spec["m"]), float(spec["b"])
        if not a < m < b:
            raise ValueError(f"tri needs a < m < b, got {spec!r}")
        return lambda x: tri(x, a, m, b)
    if kind == "low":
        return low
    if kind == "high":
        return high
    raise ValueError(f"unknown membership type {kind!r}")


def _ab(spec: MembershipSpec) -> tuple[float, float]:
    a, b = float(spec["a"]), float(spec["b"])
    if not a < b:
        raise ValueError(f"ramp needs a < b, got {spec!r}")
    return a, b
