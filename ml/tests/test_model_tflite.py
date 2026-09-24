"""CRNN builds, trains one step with the CTC loss, converts to TFLite and runs (DESIGN 12.2).

Skipped when TensorFlow is not installed (plain ``uv sync``); ``uv sync --extra train`` enables it.
"""

import hashlib
import json
from pathlib import Path

import numpy as np
import pytest

tf = pytest.importorskip("tensorflow")

from train import export  # noqa: E402
from train.charset import BLANK, NUM_CLASSES, pad_labels  # noqa: E402
from train.evaluate import TFLiteRunner, evaluate_predictions  # noqa: E402
from train.model import TIMESTEPS, build_crnn, compile_model, ctc_loss  # noqa: E402

MODELS_DIR = Path(__file__).resolve().parents[1] / "models" / "digit_crnn"


@pytest.fixture(scope="module")
def tiny_model():
    return build_crnn("lstm", rnn_units=8)


def test_crnn_output_is_32_timesteps_by_14_classes(tiny_model):
    assert tiny_model.input_shape == (None, 32, 128, 1)
    assert tiny_model.output_shape == (None, TIMESTEPS, NUM_CLASSES)
    probs = tiny_model.predict(np.zeros((2, 32, 128, 1), np.float32), verbose=0)
    np.testing.assert_allclose(probs.sum(-1), 1.0, atol=1e-5)


def test_ctc_loss_is_finite_and_decreases_on_one_batch(tiny_model):
    compile_model(tiny_model, learning_rate=1e-2)
    x = np.random.default_rng(0).random((8, 32, 128, 1), dtype=np.float32)
    y = pad_labels(["3.14", "-27", "3/4", "0", "12", "999", "1.5", "-0.5"])
    assert (y == BLANK).sum() > 0
    first = float(tiny_model.evaluate(x, y, verbose=0))
    tiny_model.fit(x, y, epochs=5, verbose=0)
    last = float(tiny_model.evaluate(x, y, verbose=0))
    assert np.isfinite(first) and np.isfinite(last) and last < first


def test_ctc_loss_ignores_padding_positions():
    probs = tf.constant(np.random.default_rng(1).dirichlet(np.ones(NUM_CLASSES), size=(2, TIMESTEPS)).astype(np.float32))
    short = pad_labels(["12", "3"], length=4)
    long_pad = pad_labels(["12", "3"], length=12)
    a = float(ctc_loss(short, probs))
    b = float(ctc_loss(long_pad, probs))
    assert a == pytest.approx(b, rel=1e-5)


@pytest.mark.parametrize("rnn", ["lstm", "conv1d"])
def test_tflite_conversion_uses_builtin_ops_and_matches_keras(rnn):
    model = build_crnn(rnn, rnn_units=8)
    blob = export.convert_to_tflite(model, float16=True)
    assert len(blob) < export.MAX_TFLITE_BYTES
    runner = TFLiteRunner(blob)
    x = np.random.default_rng(2).random((3, 32, 128, 1), dtype=np.float32)
    lite = runner.predict(x, batch_size=2)  # exercises resize_tensor_input for batches 2 and 1
    assert lite.shape == (3, TIMESTEPS, NUM_CLASSES)
    np.testing.assert_allclose(lite.sum(-1), 1.0, atol=1e-3)
    ref = model.predict(x, verbose=0)
    assert np.abs(lite - ref).max() < 0.02  # float16 weights


def test_evaluate_predictions_reports_all_metrics():
    probs = np.full((3, TIMESTEPS, NUM_CLASSES), 0.01, np.float32)
    probs[:, :, BLANK] = 0.9
    probs[0, 3, :] = 0.01
    probs[0, 3, 4] = 0.9  # "4" with high confidence
    probs[1, 5, :] = 0.01
    probs[1, 5, 7] = 0.5  # "7" but a low-confidence timestep -> still answered (mean ~0.89)
    probs[2, :, :] = 1 / NUM_CLASSES  # uniform -> abstain
    result = evaluate_predictions(["4", "8", "9"], probs, threshold=0.8)
    assert result["n"] == 3
    assert result["abstain_rate"] == pytest.approx(1 / 3)
    assert result["exact_match"] == pytest.approx(1 / 3)
    assert result["accuracy_when_answered"] == pytest.approx(1 / 2)
    assert result["cer"] == pytest.approx(2 / 3)
    assert result["worst_confident_errors"][0]["label"] == "8"


def test_exported_models_have_matching_sha256_and_contract():
    """Every exported version under ml/models must be self-consistent (model.tflite is gitignored, so it may be absent)."""
    versions = sorted(MODELS_DIR.glob("*/metrics.json")) if MODELS_DIR.exists() else []
    if not versions:
        pytest.skip("no exported model versions")
    for metrics_path in versions:
        record = json.loads(metrics_path.read_text(encoding="utf-8"))
        assert record["name"] == "digit_crnn"
        assert record["charset"] == "0123456789.-/" and record["blank_index"] == 13
        assert record["input"]["shape"] == [1, 32, 128, 1]
        for key in ("cer", "exact_match", "abstain_rate", "accuracy_when_answered"):
            assert key in record["metrics"]
        sha_file = metrics_path.with_name("model.tflite.sha256").read_text().split()[0]
        assert sha_file == record["sha256"]
        tflite = metrics_path.with_name("model.tflite")
        if tflite.exists():
            assert hashlib.sha256(tflite.read_bytes()).hexdigest() == record["sha256"]
            assert tflite.stat().st_size == record["size_bytes"] <= export.MAX_TFLITE_BYTES
            runner = TFLiteRunner(tflite)
            out = runner.predict(np.zeros((1, 32, 128, 1), np.float32))
            assert out.shape == (1, 32, 14)
