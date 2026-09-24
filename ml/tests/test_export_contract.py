"""metrics.json contract and the reproducible created_at (no TensorFlow needed)."""

import json
import os
from datetime import datetime, timezone

import pytest

from train import export
from train.charset import CONFIDENCE_DEFINITION, CONFIDENCE_DESCRIPTION, CONFIDENCE_THRESHOLD


def test_contract_names_the_confidence_definition_and_the_threshold_the_app_reads():
    contract = export.contract()
    assert contract["charset"] == "0123456789.-/" and contract["blank_index"] == 13 and contract["num_classes"] == 14
    assert contract["input"]["shape"] == [1, 32, 128, 1] and contract["output"]["shape"] == [1, 32, 14]
    decode = contract["decode"]
    assert decode["method"] == "ctc_greedy"
    assert decode["confidence"] == CONFIDENCE_DEFINITION == "emitting_mean_max_prob"
    assert decode["confidence_description"] == CONFIDENCE_DESCRIPTION
    assert decode["abstain_below"] == CONFIDENCE_THRESHOLD == 0.8
    assert decode["reference"].endswith("charset.py::ctc_greedy_decode")
    assert export.contract(0.9)["decode"]["abstain_below"] == 0.9
    json.dumps(contract)  # serialisable as written


def test_format_utc_and_parse_timestamp_normalise_to_second_precision_utc():
    assert export.format_utc(datetime(2026, 9, 24, 15, 30, 46, 123456, tzinfo=timezone.utc)) == "2026-09-24T15:30:46Z"
    assert export.format_utc(datetime(2026, 9, 24, 15, 30, 46)) == "2026-09-24T15:30:46Z"  # naive = UTC
    assert export.parse_timestamp("2026-09-24T22:30:46+07:00") == "2026-09-24T15:30:46Z"  # Asia/Bangkok -> UTC
    assert export.parse_timestamp("2026-09-24T15:30:46Z") == "2026-09-24T15:30:46Z"
    with pytest.raises(ValueError):
        export.parse_timestamp("yesterday")


def test_created_at_prefers_the_run_finished_at(tmp_path):
    (tmp_path / "summary.json").write_text(json.dumps({"finished_at": "2026-09-24T15:30:46Z"}), encoding="utf-8")
    assert export.run_created_at(tmp_path) == "2026-09-24T15:30:46Z"
    assert export.run_created_at(tmp_path, {"finished_at": "2026-09-25T00:00:00+07:00"}) == "2026-09-24T17:00:00Z"


def test_created_at_falls_back_to_best_keras_mtime(tmp_path):
    """Runs trained before finished_at existed (e.g. digit_crnn 0.1.0) still get a stable timestamp."""
    best = tmp_path / "best.keras"
    best.write_bytes(b"not a real model")
    epoch = int(datetime(2026, 9, 24, 15, 30, 46, tzinfo=timezone.utc).timestamp())
    os.utime(best, (epoch, epoch))
    assert export.run_created_at(tmp_path) == "2026-09-24T15:30:46Z"
    assert export.run_created_at(tmp_path, {"epochs_run": 12}) == "2026-09-24T15:30:46Z"
    assert export.run_created_at(tmp_path) == export.run_created_at(tmp_path)  # stable across calls
