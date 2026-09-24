"""CTC decode utility, label encoding and CER (DESIGN 12.2)."""

import numpy as np
import pytest

from train import charset


def one_hot_path(indices):
    probs = np.full((len(indices), charset.NUM_CLASSES), 0.01, dtype=np.float32)
    for t, idx in enumerate(indices):
        probs[t, idx] = 0.9
    return probs


def path_with_probs(indices, top):
    """Per-timestep argmax ``indices`` with max probability ``top[t]`` (the rest of the mass spread evenly)."""
    probs = np.zeros((len(indices), charset.NUM_CLASSES), dtype=np.float32)
    for t, (idx, p) in enumerate(zip(indices, top)):
        probs[t, :] = (1.0 - p) / (charset.NUM_CLASSES - 1)
        probs[t, idx] = p
    return probs


def test_charset_has_13_characters_and_blank_is_last():
    assert charset.CHARSET == "0123456789.-/"
    assert charset.NUM_CLASSES == 14
    assert charset.BLANK == 13


def test_encode_decode_round_trip():
    for label in ["0", "3.14", "-27", "3/4", "-12/25", "999999", "0.5"]:
        assert charset.decode(charset.encode(label)) == label


@pytest.mark.parametrize("bad", ["", "abc", "๓.๕", "1,5", "1" * 13, "3 4"])
def test_invalid_labels_are_rejected(bad):
    assert not charset.is_valid_label(bad)
    with pytest.raises(ValueError):
        charset.encode(bad)


def test_pad_labels_pads_with_blank():
    padded = charset.pad_labels(["12", "3.5"], length=5)
    assert padded.dtype == np.int32
    assert padded.tolist() == [[1, 2, 13, 13, 13], [3, 10, 5, 13, 13]]


def test_greedy_decode_collapses_repeats_and_drops_blanks():
    b = charset.BLANK
    # "1 1 _ 1 _ _ . 3 3 _" -> "11.3": repeats collapse, a blank separates the second "1"
    probs = one_hot_path([1, 1, b, 1, b, b, 10, 3, 3, b])
    decoded = charset.ctc_greedy_decode(probs)
    assert decoded.text == "11.3"
    assert decoded.confidence == pytest.approx(0.9)
    assert decoded.answered()


def test_confidence_definition_is_the_one_the_app_mirrors():
    assert charset.CONFIDENCE_DEFINITION == "emitting_mean_max_prob"
    assert charset.CONFIDENCE_THRESHOLD == 0.8  # DESIGN 12.2 default
    assert "emits a character" in charset.CONFIDENCE_DESCRIPTION


def test_confidence_is_mean_of_max_probability_over_emitting_timesteps():
    # t0 emits "0" at 0.6, t1 emits "2" at 0.6, t2 is a certain blank (1.0) that must NOT lift the confidence
    probs = np.array([[0.6, 0.4] + [0.0] * 12, [0.2, 0.2, 0.6] + [0.0] * 11, [0.0] * 13 + [1.0]], dtype=np.float32)
    decoded = charset.ctc_greedy_decode(probs)
    assert decoded.text == "02"
    assert decoded.char_confidences == pytest.approx((0.6, 0.6))
    assert decoded.confidence == pytest.approx(0.6)
    assert not decoded.answered()  # 0.6 < 0.8 -> abstain
    assert decoded.answered(threshold=0.6)


def test_confidence_ignores_blanks_and_collapsed_repeats():
    b = charset.BLANK
    #            emit 1  rep 1  blank  emit 1  blank  blank  emit .  emit 3  rep 3  blank
    indices = [1, 1, b, 1, b, b, 10, 3, 3, b]
    top = [0.9, 0.3, 0.99, 0.7, 0.99, 0.99, 0.8, 0.6, 0.2, 0.99]
    decoded = charset.ctc_greedy_decode(path_with_probs(indices, top))
    assert decoded.text == "11.3"
    assert decoded.char_confidences == pytest.approx((0.9, 0.7, 0.8, 0.6))  # first timestep of each run only
    assert decoded.confidence == pytest.approx(0.75)


def test_many_confident_blanks_do_not_mask_one_uncertain_character():
    """The digit_crnn 0.1.0 failure mode: 31 blanks at ~1.0 and a single doubtful digit must still abstain."""
    b = charset.BLANK
    indices = [b] * 31 + [7]
    top = [0.999] * 31 + [0.5]
    decoded = charset.ctc_greedy_decode(path_with_probs(indices, top))
    assert decoded.text == "7"
    assert decoded.confidence == pytest.approx(0.5)
    assert not decoded.answered()


def test_all_blank_path_decodes_to_empty_string_with_zero_confidence():
    probs = one_hot_path([charset.BLANK] * 32)
    decoded = charset.ctc_greedy_decode(probs)
    assert decoded.text == ""
    assert decoded.char_confidences == ()
    assert decoded.confidence == 0.0
    assert not decoded.answered()  # nothing read = no answer, whatever the threshold


def test_batch_decode_matches_single():
    batch = np.stack([one_hot_path([4, 4, 13, 2]), one_hot_path([11, 7, 13, 13])])
    texts = [d.text for d in charset.ctc_greedy_decode_batch(batch)]
    assert texts == ["42", "-7"]


def test_decode_rejects_wrong_rank():
    with pytest.raises(ValueError):
        charset.ctc_greedy_decode(np.zeros((2, 3, 14)))


def test_edit_distance_and_cer():
    assert charset.edit_distance("3.14", "3.14") == 0
    assert charset.edit_distance("3.14", "314") == 1
    assert charset.edit_distance("", "12") == 2
    assert charset.edit_distance("kitten", "sitting") == 3
    pairs = [("3.14", "3.14"), ("-27", "27"), ("3/4", "")]  # 0 + 1 + 3 errors over 4 + 3 + 3 chars
    assert charset.character_error_rate(pairs) == pytest.approx(4 / 10)
    assert charset.character_error_rate([]) == 0.0
