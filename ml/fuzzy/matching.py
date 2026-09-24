"""Answer-key matching from DESIGN section 11.4 (input ``M`` of the ``short``
system, and the numeric part of ``F`` in 11.3).

* text, ``match_mode = flexible``: ``M = max(sim, key_match)`` where ``sim`` is
  ``1 - levenshtein(norm(a), norm(k)) / max(len)`` against the best accepted answer
* text, ``match_mode = exact``: ``M = 1`` only when ``norm(a)`` equals an accepted
  answer, Gemini's ``key_match`` is ignored (spelling questions)
* numeric: ``M = 1`` when ``|a - k| <= abs_tol``, otherwise
  ``0.6 * clamp(1 - rel_err / 0.1)`` with ``rel_err = |a - k| / max(|k|, 1)``,
  so a near miss never reaches the "match" set (its cap is 0.6)

``norm`` strips surrounding whitespace, maps Thai digits to Arabic digits and
lower-cases the text.
"""

from __future__ import annotations

import re
from collections.abc import Mapping, Sequence
from typing import Any

from fuzzy.membership import clamp
from fuzzy.scale import match_value

THAI_TO_ARABIC_DIGITS = str.maketrans("๐๑๒๓๔๕๖๗๘๙", "0123456789")

NEAR_MISS_CAP = 0.6
NEAR_MISS_REL_ERR = 0.1

_NUMBER_RE = re.compile(
    r"""^[+\-−]?\d+(?:[.,]\d+)?$  # 12, -3.5, 3,5
        |^[+\-−]?\d+/\d+$          # 3/4""",
    re.VERBOSE,
)


def normalize_text(text: str) -> str:
    """``norm`` from 11.4: strip, Thai digits -> Arabic, lower-case."""
    return text.strip().translate(THAI_TO_ARABIC_DIGITS).lower()


def levenshtein(a: str, b: str) -> int:
    """Edit distance with unit costs (insert, delete, substitute)."""
    if a == b:
        return 0
    if not a:
        return len(b)
    if not b:
        return len(a)
    previous = list(range(len(b) + 1))
    for i, ca in enumerate(a, start=1):
        current = [i]
        for j, cb in enumerate(b, start=1):
            cost = 0 if ca == cb else 1
            current.append(min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + cost))
        previous = current
    return previous[-1]


def similarity(answer: str, key: str) -> float:
    """``1 - levenshtein(norm(a), norm(k)) / max(len)`` in 0..1."""
    a, k = normalize_text(answer), normalize_text(key)
    longest = max(len(a), len(k))
    if longest == 0:
        return 1.0
    return 1.0 - levenshtein(a, k) / longest


def text_match_flexible(answer: str, accepted: Sequence[str], key_match: str | None = None) -> float:
    """``M = max(sim, key_match)``; ``key_match`` is Gemini's category (may be omitted)."""
    if not accepted:
        raise ValueError("accepted answers must not be empty")
    best_sim = max(similarity(answer, key) for key in accepted)
    if key_match is None:
        return best_sim
    return max(best_sim, match_value(key_match, "short"))


def text_match_exact(answer: str, accepted: Sequence[str]) -> float:
    """``M = 1`` when ``norm(a)`` equals one accepted answer exactly, else 0."""
    if not accepted:
        raise ValueError("accepted answers must not be empty")
    a = normalize_text(answer)
    return 1.0 if any(a == normalize_text(key) for key in accepted) else 0.0


def numeric_match(answer: float, key: float, abs_tol: float = 0.0) -> float:
    """Numeric rule of 11.4: exact within ``abs_tol`` -> 1, near miss capped at 0.6."""
    if abs_tol < 0:
        raise ValueError("abs_tol must be >= 0")
    diff = abs(answer - key)
    if diff <= abs_tol:
        return 1.0
    rel_err = diff / max(abs(key), 1.0)
    return NEAR_MISS_CAP * clamp(1.0 - rel_err / NEAR_MISS_REL_ERR)


def parse_number(text: str) -> float | None:
    """Parse what a student may write in a numeric box: ``12``, ``-3.5``, ``๑๒.๕``, ``3/4``.

    Returns ``None`` when the text is not a number (letters, empty, ``[?]`` ...).
    """
    s = normalize_text(text).replace(" ", "").replace("−", "-")
    if not _NUMBER_RE.match(s):
        return None
    if "/" in s:
        num, den = s.split("/")
        d = float(den)
        if d == 0:
            return None
        return float(num) / d
    return float(s.replace(",", "."))


def short_match(
    answer_text: str,
    answer_key: Mapping[str, Any],
    match_mode: str = "flexible",
    key_match: str | None = None,
) -> float:
    """Compute ``M`` for a ``short`` question from its ``answer_key`` JSON (schema 8.3).

    ``answer_key`` is ``{"accepted": [...]}`` or
    ``{"accepted": [...], "numeric": {"value": k, "abs_tol": t}}``. When a numeric
    key exists and the answer parses as a number the numeric rule is used;
    otherwise the text rule for ``match_mode`` applies.
    """
    numeric = answer_key.get("numeric")
    if numeric is not None:
        value = parse_number(answer_text)
        if value is not None:
            return numeric_match(value, float(numeric["value"]), float(numeric.get("abs_tol", 0.0)))
    accepted = list(answer_key.get("accepted") or [])
    if match_mode == "exact":
        return text_match_exact(answer_text, accepted)
    if match_mode == "flexible":
        return text_match_flexible(answer_text, accepted, key_match)
    raise ValueError(f"unknown match_mode {match_mode!r}")


def final_answer_value(
    final_answer_match: str,
    final_answer_text: str | None = None,
    final_key: Mapping[str, Any] | None = None,
) -> float:
    """``F`` for ``show_work`` (11.3): Gemini's category, lifted by the numeric rule when
    the key is numeric (``max(F, numeric_match)``)."""
    f = match_value(final_answer_match, "show_work")
    if final_key is None or final_answer_text is None:
        return f
    numeric = final_key.get("numeric")
    if numeric is None:
        return f
    value = parse_number(final_answer_text)
    if value is None:
        return f
    return max(f, numeric_match(value, float(numeric["value"]), float(numeric.get("abs_tol", 0.0))))
