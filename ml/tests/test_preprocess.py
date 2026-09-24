"""The preprocessing contract with the app (train/preprocess.py)."""

import numpy as np

from train import preprocess


def test_fit_to_canvas_pads_right_with_paper_and_keeps_aspect():
    gray = np.zeros((64, 64), np.uint8)  # square black block
    canvas = preprocess.fit_to_canvas(gray)
    assert canvas.shape == (32, 128) and canvas.dtype == np.uint8
    assert canvas[:, :32].max() == 0  # resized to 32 x 32 on the left
    assert canvas[:, 32:].min() == 255  # paper on the right


def test_fit_to_canvas_squashes_a_strip_wider_than_128():
    gray = np.zeros((10, 1000), np.uint8)
    canvas = preprocess.fit_to_canvas(gray)
    assert canvas.shape == (32, 128)
    assert canvas.max() == 0  # the whole width is ink, nothing padded


def test_tight_crop_keeps_only_the_ink_plus_margin():
    gray = np.full((100, 200), 255, np.uint8)
    gray[40:60, 50:90] = 0  # 20 px tall ink block
    cropped = preprocess.tight_crop(gray, margin=0.10)
    assert cropped.shape == (24, 44)  # 20 + 2*2, 40 + 2*2
    assert preprocess.tight_crop(np.full((30, 30), 255, np.uint8)).shape == (30, 30)  # blank: unchanged


def test_normalize_maps_ink_to_one_and_paper_to_zero():
    canvas = np.full((32, 128), 255, np.uint8)
    canvas[0, 0] = 0
    x = preprocess.normalize(canvas)
    assert x.shape == (32, 128, 1) and x.dtype == np.float32
    assert x[0, 0, 0] == 1.0 and x[5, 5, 0] == 0.0


def test_preprocess_end_to_end_shape():
    gray = np.full((80, 300), 240, np.uint8)
    gray[30:50, 20:120] = 10
    x = preprocess.preprocess(gray)
    assert x.shape == (32, 128, 1)
    assert 0.0 <= x.min() and x.max() <= 1.0
