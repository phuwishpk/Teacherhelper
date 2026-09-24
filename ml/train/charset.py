"""Character set, CTC greedy decoding and text metrics (DESIGN 12.2).

The Dart decoder in the app must reproduce :func:`ctc_greedy_decode` exactly:
same class order (``CHARSET`` then the blank as the last class), collapse
repeated classes, drop blanks, and confidence = mean, over the timesteps that
*emit* a character (argmax != blank and != the previous timestep's argmax), of
the highest probability at that timestep (``CONFIDENCE_DEFINITION``). When no
character is emitted the confidence is 0.0. A result below
``CONFIDENCE_THRESHOLD`` is *abstain* (the CNN does not answer).

Why the emitting timesteps only: ~26 of the 32 timesteps of a typical read are
blanks predicted at ~1.0, so a mean over all timesteps never drops below 0.8
and the DESIGN 12.2 abstain rule becomes a no-op (digit_crnn 0.1.0: 0 %
abstain at every threshold up to 0.9, worst errors at 0.998+). The mean over
the emitting timesteps is the DESIGN wording ("mean of the max probability per
timestep along the decoded path") applied to the characters that were actually
decoded. The app reads the definition and the threshold from ``metrics.json``
(``decode.confidence`` / ``decode.abstain_below``) rather than hard-coding them.
"""

from __future__ import annotations

from collections.abc import Iterable, Sequence
from dataclasses import dataclass

import numpy as np

CHARSET = "0123456789.-/"
"""The 13 characters the model can read, in class order (class i = CHARSET[i])."""

BLANK = len(CHARSET)
"""Index of the CTC blank class (13, the last of the 14 outputs)."""

NUM_CLASSES = len(CHARSET) + 1
CHAR_TO_INDEX: dict[str, int] = {c: i for i, c in enumerate(CHARSET)}

MAX_LABEL_LEN = 12
"""Longest label accepted for training; CTC needs timesteps >= length + repeats (32 timesteps)."""

CONFIDENCE_THRESHOLD = 0.8
"""Below this confidence the reader abstains (DESIGN 12.2 default; tune on validation, ship via metrics.json)."""

CONFIDENCE_DEFINITION = "emitting_mean_max_prob"
"""Identifier of the confidence formula, exported as ``decode.confidence`` so the app can assert it mirrors the right one."""

CONFIDENCE_DESCRIPTION = (
    "mean, over the timesteps at which the greedy decoder emits a character "
    "(argmax != blank and argmax != previous timestep's argmax), of the max probability at that timestep; "
    "0.0 when no character is emitted"
)


def is_valid_label(label: str) -> bool:
    return 0 < len(label) <= MAX_LABEL_LEN and all(c in CHAR_TO_INDEX for c in label)


def encode(label: str) -> list[int]:
    if not is_valid_label(label):
        raise ValueError(f"label {label!r} is not a string of 1-{MAX_LABEL_LEN} characters from {CHARSET!r}")
    return [CHAR_TO_INDEX[c] for c in label]


def decode(indices: Iterable[int]) -> str:
    """Map class indices back to text; the blank and out-of-range indices are dropped."""
    return "".join(CHARSET[i] for i in indices if 0 <= i < BLANK)


def pad_labels(labels: Sequence[str], length: int = MAX_LABEL_LEN) -> np.ndarray:
    """Encode labels into an ``int32 (N, length)`` array padded with ``BLANK``."""
    out = np.full((len(labels), length), BLANK, dtype=np.int32)
    for row, label in enumerate(labels):
        codes = encode(label)
        out[row, : len(codes)] = codes
    return out


@dataclass(frozen=True)
class Decoded:
    text: str
    confidence: float
    """``CONFIDENCE_DEFINITION``: mean of ``char_confidences``, 0.0 when ``text`` is empty."""
    char_confidences: tuple[float, ...] = ()
    """Max probability at the timestep that emitted each character of ``text`` (same length as ``text``)."""

    def answered(self, threshold: float = CONFIDENCE_THRESHOLD) -> bool:
        return self.confidence >= threshold


def ctc_greedy_decode(probs: np.ndarray, blank: int = BLANK) -> Decoded:
    """Greedy (best path) CTC decode of one ``(timesteps, classes)`` probability matrix.

    Mirror of the Dart decoder: argmax per timestep, collapse repeats, drop blanks;
    confidence = mean of the max probability at the emitting timesteps (``CONFIDENCE_DESCRIPTION``).
    """
    probs = np.asarray(probs, dtype=np.float32)
    if probs.ndim != 2:
        raise ValueError(f"expected (timesteps, classes), got shape {probs.shape}")
    best = probs.argmax(axis=-1)
    top = probs.max(axis=-1)
    chars: list[str] = []
    char_confidences: list[float] = []
    previous = -1
    for t, index in enumerate(best.tolist()):
        if index != previous and index != blank:
            chars.append(CHARSET[index])
            char_confidences.append(float(top[t]))
        previous = index
    confidence = float(np.mean(char_confidences)) if char_confidences else 0.0
    return Decoded("".join(chars), confidence, tuple(char_confidences))


def ctc_greedy_decode_batch(probs: np.ndarray, blank: int = BLANK) -> list[Decoded]:
    probs = np.asarray(probs, dtype=np.float32)
    if probs.ndim != 3:
        raise ValueError(f"expected (batch, timesteps, classes), got shape {probs.shape}")
    return [ctc_greedy_decode(p, blank) for p in probs]


def edit_distance(a: str, b: str) -> int:
    """Levenshtein distance (insertions, deletions, substitutions all cost 1)."""
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
            current.append(min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + (ca != cb)))
        previous = current
    return previous[-1]


def character_error_rate(pairs: Iterable[tuple[str, str]]) -> float:
    """``sum(edit_distance(reference, hypothesis)) / sum(len(reference))`` over ``(reference, hypothesis)`` pairs."""
    errors = 0
    total = 0
    for reference, hypothesis in pairs:
        errors += edit_distance(reference, hypothesis)
        total += len(reference)
    return errors / total if total else 0.0
