"""Image preprocessing shared by the synthetic generator, the importer and inference.

This is the contract with the app (DESIGN 12.2: "grayscale, height 32, width
128, padded to keep the aspect ratio"). The Kotlin/Dart side must do the same
steps on the answer-frame crop before calling the TFLite model:

1. ``tight_crop``: threshold the grayscale crop (Otsu), take the bounding box of
   the ink and add a margin of ``margin`` x height around it. A crop with no ink
   is returned unchanged.
2. ``fit_to_canvas``: scale so the height is 32 px keeping the aspect ratio,
   pad on the right with paper (255) to width 128; a strip wider than 128 px is
   squashed to 128 instead.
3. ``normalize``: ``x = 1 - gray / 255`` so ink is 1.0, paper is 0.0, shape
   ``(32, 128, 1)`` float32. The model input is the batch ``(1, 32, 128, 1)``.
"""

from __future__ import annotations

import cv2
import numpy as np

IMAGE_HEIGHT = 32
IMAGE_WIDTH = 128
PAPER = 255
DEFAULT_MARGIN = 0.10


def tight_crop(gray: np.ndarray, margin: float = DEFAULT_MARGIN) -> np.ndarray:
    """Crop a dark-ink-on-light-paper image to its ink bounding box plus a margin."""
    if gray.ndim != 2:
        raise ValueError("tight_crop expects a 2-D grayscale image")
    if gray.size == 0:
        return gray
    _, ink = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV | cv2.THRESH_OTSU)
    ys, xs = np.nonzero(ink)
    if len(ys) == 0 or ink.mean() > 127:  # nothing written, or "ink" covers the page: keep as is
        return gray
    pad = int(round(margin * (ys.max() - ys.min() + 1)))
    y0 = max(0, ys.min() - pad)
    y1 = min(gray.shape[0], ys.max() + 1 + pad)
    x0 = max(0, xs.min() - pad)
    x1 = min(gray.shape[1], xs.max() + 1 + pad)
    return gray[y0:y1, x0:x1]


def fit_to_canvas(
    gray: np.ndarray, height: int = IMAGE_HEIGHT, width: int = IMAGE_WIDTH, paper: int = PAPER
) -> np.ndarray:
    """Resize to ``height`` keeping the aspect ratio and pad right with paper to ``width``."""
    if gray.ndim != 2:
        raise ValueError("fit_to_canvas expects a 2-D grayscale image")
    h, w = gray.shape
    if h == 0 or w == 0:
        return np.full((height, width), paper, dtype=np.uint8)
    new_w = max(1, int(round(w * height / h)))
    if new_w > width:
        new_w = width
    interpolation = cv2.INTER_AREA if h > height else cv2.INTER_LINEAR
    resized = cv2.resize(gray, (new_w, height), interpolation=interpolation)
    canvas = np.full((height, width), paper, dtype=np.uint8)
    canvas[:, :new_w] = resized
    return canvas


def normalize(canvas: np.ndarray) -> np.ndarray:
    """``uint8 (32, 128)`` paper/ink image -> ``float32 (32, 128, 1)`` with ink = 1, paper = 0."""
    x = 1.0 - canvas.astype(np.float32) / 255.0
    return x[..., np.newaxis]


def preprocess(gray: np.ndarray, margin: float = DEFAULT_MARGIN) -> np.ndarray:
    """Full inference-time pipeline for one answer-frame crop (grayscale uint8)."""
    return normalize(fit_to_canvas(tight_crop(gray, margin)))


def to_gray(image: np.ndarray) -> np.ndarray:
    """Accept a BGR/BGRA/gray image from ``cv2.imread`` and return grayscale uint8."""
    if image.ndim == 2:
        return image
    if image.shape[2] == 4:
        return cv2.cvtColor(image, cv2.COLOR_BGRA2GRAY)
    return cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
