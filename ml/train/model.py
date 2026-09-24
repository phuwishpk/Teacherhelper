"""CRNN + CTC digit reader (DESIGN 12.2) and its training helpers.

Architecture (input ``(32, 128, 1)``, ink = 1, paper = 0):

    4 x [Conv3x3 -> BN -> ReLU -> MaxPool]  channels 32, 64, 128, 128
        pools (2,2), (2,2), (2,1), (4,1): height 32 -> 1, width 128 -> 32 timesteps
    reshape -> (32, 128)
    BiLSTM(64) x 2  (``unroll=True``: the fused Keras 3 LSTM does not convert to
                     TFLite builtin ops, the unrolled one does, at 1.3 MB float16)
    Dense(14, softmax)  = 13 characters + blank (index 13)

``--rnn conv1d`` replaces the BiLSTMs with two Conv1D(128, 5) blocks, the
fallback DESIGN 12.2 allows if the LSTM ever stops converting.
"""

from __future__ import annotations

import time

import numpy as np

from train.charset import BLANK, NUM_CLASSES, character_error_rate, ctc_greedy_decode_batch, decode
from train.preprocess import IMAGE_HEIGHT, IMAGE_WIDTH

TIMESTEPS = 32
CONV_BLOCKS: tuple[tuple[int, tuple[int, int]], ...] = ((32, (2, 2)), (64, (2, 2)), (128, (2, 1)), (128, (4, 1)))
RNN_CHOICES = ("lstm", "conv1d")


def build_crnn(rnn: str = "lstm", rnn_units: int = 64, unroll: bool = True, num_classes: int = NUM_CLASSES):
    import keras
    from keras import layers

    if rnn not in RNN_CHOICES:
        raise ValueError(f"rnn must be one of {RNN_CHOICES}")
    inputs = keras.Input(shape=(IMAGE_HEIGHT, IMAGE_WIDTH, 1), name="image")
    x = inputs
    for i, (channels, pool) in enumerate(CONV_BLOCKS, start=1):
        x = layers.Conv2D(channels, 3, padding="same", use_bias=False, name=f"conv{i}")(x)
        x = layers.BatchNormalization(name=f"bn{i}")(x)
        x = layers.ReLU(name=f"relu{i}")(x)
        x = layers.MaxPool2D(pool, name=f"pool{i}")(x)
    x = layers.Reshape((TIMESTEPS, CONV_BLOCKS[-1][0]), name="to_sequence")(x)
    if rnn == "lstm":
        for i in (1, 2):
            x = layers.Bidirectional(
                layers.LSTM(rnn_units, return_sequences=True, unroll=unroll), name=f"bilstm{i}"
            )(x)
    else:
        for i in (1, 2):
            x = layers.Conv1D(2 * rnn_units, 5, padding="same", use_bias=False, name=f"seq_conv{i}")(x)
            x = layers.BatchNormalization(name=f"seq_bn{i}")(x)
            x = layers.ReLU(name=f"seq_relu{i}")(x)
    outputs = layers.Dense(num_classes, activation="softmax", name="probs")(x)
    return keras.Model(inputs, outputs, name=f"digit_crnn_{rnn}")


def ctc_loss(y_true, y_pred):
    """CTC loss on softmax outputs; ``y_true`` is ``int32 (B, L)`` padded with ``BLANK``."""
    import keras
    from keras import ops

    y_true = ops.cast(y_true, "int32")
    lengths = ops.sum(ops.cast(ops.not_equal(y_true, BLANK), "int32"), axis=1)
    targets = ops.where(ops.equal(y_true, BLANK), 0, y_true)  # padding beyond `lengths` is ignored
    logits = ops.log(ops.cast(y_pred, "float32") + 1e-8)
    output_lengths = ops.full((ops.shape(y_pred)[0],), TIMESTEPS, dtype="int32")
    loss = keras.ops.ctc_loss(targets, logits, lengths, output_lengths, mask_index=BLANK)
    return ops.mean(loss)


def compile_model(model, learning_rate: float = 1e-3):
    import keras

    model.compile(optimizer=keras.optimizers.Adam(learning_rate), loss=ctc_loss)
    return model


def predict_probs(model, images: np.ndarray, batch_size: int = 256) -> np.ndarray:
    return np.asarray(model.predict(images, batch_size=batch_size, verbose=0), dtype=np.float32)


def sequence_metrics(labels: list[str], probs: np.ndarray) -> dict[str, float]:
    decoded = ctc_greedy_decode_batch(probs)
    return {
        "cer": character_error_rate(zip(labels, (d.text for d in decoded))),
        "exact": float(np.mean([d.text == label for d, label in zip(decoded, labels)])) if labels else 0.0,
    }


def make_sequence_metrics_callback(images: np.ndarray, padded_labels: np.ndarray, prefix: str = "val_"):
    """Keras callback logging ``val_cer`` / ``val_exact`` every epoch (visible to CSVLogger)."""
    import keras

    labels = [decode(row) for row in padded_labels]

    class SequenceMetrics(keras.callbacks.Callback):
        def on_epoch_end(self, epoch, logs=None):
            if logs is None:
                return
            start = time.time()
            metrics = sequence_metrics(labels, predict_probs(self.model, images))
            for key, value in metrics.items():
                logs[f"{prefix}{key}"] = value
            logs[f"{prefix}metrics_seconds"] = time.time() - start

    return SequenceMetrics()
