"""CRNN builds, trains one step with the CTC loss, converts to TFLite and runs (DESIGN 12.2).

Skipped when TensorFlow is not installed (plain ``uv sync``); ``uv sync --extra train`` enables it.
"""

import csv
import hashlib
import json
import re
from pathlib import Path

import numpy as np
import pytest

tf = pytest.importorskip("tensorflow")

from train import export  # noqa: E402
from train.charset import BLANK, CONFIDENCE_THRESHOLD, NUM_CLASSES, pad_labels  # noqa: E402
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
    probs[0, 3, 4] = 0.9  # "4" emitted at 0.9 -> answered, correct
    probs[1, 5, :] = 0.01
    probs[1, 5, 7] = 0.5  # "7" emitted at 0.5 -> abstain although the other 31 timesteps are confident blanks
    probs[2, :, :] = 1 / NUM_CLASSES  # uniform -> argmax 0 everywhere -> "0" at 1/14 -> abstain
    result = evaluate_predictions(["4", "8", "9"], probs, threshold=0.8)
    assert result["n"] == 3
    assert result["abstain_rate"] == pytest.approx(2 / 3)
    assert result["exact_match"] == pytest.approx(1 / 3)
    assert result["accuracy_when_answered"] == pytest.approx(1.0)
    assert result["cer_when_answered"] == 0.0
    assert result["cer"] == pytest.approx(2 / 3)  # abstentions still count as errors here ("8"->"7", "9"->"0")
    assert result["worst_confident_errors"][0] == {"label": "8", "read": "7", "confidence": 0.5, "distance": 1}


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
        # The committed decode contract must be the one train.charset implements today (a definition change
        # without a re-export would silently desynchronise the Dart decoder from metrics.json).
        assert record["decode"] == export.contract(record["decode"]["abstain_below"])["decode"]
        assert 0.0 < record["decode"]["abstain_below"] <= 1.0
        assert record["metrics"]["threshold"] == record["decode"]["abstain_below"]
        assert re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", record["created_at"])
        sha_file = metrics_path.with_name("model.tflite.sha256").read_text().split()[0]
        assert sha_file == record["sha256"]
        tflite = metrics_path.with_name("model.tflite")
        if tflite.exists():
            assert hashlib.sha256(tflite.read_bytes()).hexdigest() == record["sha256"]
            assert tflite.stat().st_size == record["size_bytes"] <= export.MAX_TFLITE_BYTES
            runner = TFLiteRunner(tflite)
            out = runner.predict(np.zeros((1, 32, 128, 1), np.float32))
            assert out.shape == (1, 32, 14)


def _write_tiny_run(tmp_path: Path, model) -> tuple[Path, Path]:
    """A 3-writer dataset (32x128 PNGs), a split.json and a run directory with best.keras + summary.json."""
    import cv2

    data_dir = tmp_path / "data"
    (data_dir / "images").mkdir(parents=True)
    rows = []
    rng = np.random.default_rng(3)
    for writer in ("w1", "w2", "w3"):
        for i, label in enumerate(("12", "3.5")):
            rel = f"images/{writer}_{i}.png"
            image = np.full((32, 128), 255, np.uint8)
            image[8:24, 10 + 20 * i : 40 + 20 * i] = rng.integers(0, 80, (16, 30), dtype=np.uint8)
            assert cv2.imwrite(str(data_dir / rel), image)
            rows.append((rel, label, writer))
    with (data_dir / "labels.csv").open("w", newline="", encoding="utf-8") as fh:
        writer_ = csv.writer(fh)
        writer_.writerow(["path", "label", "writer_key"])
        writer_.writerows(rows)
    run_dir = tmp_path / "runs" / "tiny"
    run_dir.mkdir(parents=True)
    model.save(run_dir / "best.keras")
    (run_dir / "split.json").write_text(
        json.dumps({"seed": 0, "train_writers": ["w1"], "val_writers": ["w2"], "test_writers": ["w3"]}), encoding="utf-8"
    )
    (run_dir / "summary.json").write_text(
        json.dumps({"run": "tiny", "rnn": "lstm", "epochs_run": 1, "finished_at": "2026-09-24T22:30:46+07:00"}),
        encoding="utf-8",
    )
    return run_dir, data_dir / "labels.csv"


def test_export_is_reproducible_and_created_at_comes_from_the_run(tmp_path, tiny_model):
    """Re-exporting the same run must leave metrics.json and the sha256 file byte-identical (no wall-clock fields)."""
    run_dir, labels_csv = _write_tiny_run(tmp_path, tiny_model)
    first = export.export(run_dir, "0.0.1", [labels_csv], models_dir=tmp_path / "models_a", name="tiny")
    second = export.export(run_dir, "0.0.1", [labels_csv], models_dir=tmp_path / "models_b", name="tiny")
    assert (first / "metrics.json").read_bytes() == (second / "metrics.json").read_bytes()
    assert (first / "model.tflite.sha256").read_bytes() == (second / "model.tflite.sha256").read_bytes()
    record = json.loads((first / "metrics.json").read_text(encoding="utf-8"))
    assert record["created_at"] == "2026-09-24T15:30:46Z"  # summary.json finished_at, normalised to UTC
    assert record["decode"]["abstain_below"] == CONFIDENCE_THRESHOLD
    assert record["metrics"]["n_test"] == 2 and record["val_metrics"]["n"] == 2
    assert record["training"]["run"] == "tiny"
    # --created-at overrides, --threshold flows into decode.abstain_below (the value the app must read)
    third = export.export(
        run_dir, "0.0.2", [labels_csv], models_dir=tmp_path / "models_c", name="tiny", threshold=0.9, created_at="2026-01-02T03:04:05Z"
    )
    record3 = json.loads((third / "metrics.json").read_text(encoding="utf-8"))
    assert record3["created_at"] == "2026-01-02T03:04:05Z"
    assert record3["decode"]["abstain_below"] == 0.9 == record3["metrics"]["threshold"]
    assert record3["sha256"] == record["sha256"]  # the model bytes do not depend on the threshold
