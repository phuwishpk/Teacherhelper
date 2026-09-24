"""EduVision CRNN digit reader: training, export and evaluation (DESIGN section 12).

The on-device model reads the handwriting inside a numeric answer frame
(``0-9 . - /``) and is only a *second reader*: its output feeds input ``D`` of
fuzzy system 2 (DESIGN 11.8), never the score.

Modules (import TensorFlow lazily, so charset/preprocess/synth/dataset work
with a plain ``uv sync``; the rest needs ``uv sync --extra train``)
    charset         character set, CTC greedy decode, CER (12.2); the Dart decoder mirrors it
    preprocess      crop -> 32 x 128 canvas -> float input, the contract with the app
    synth           synthetic dataset from MNIST/EMNIST glyphs + programmatic symbols (12.3.1)
    dataset         labels.csv loader and the writer-based train/val/test split (12.3)
    model           CRNN + CTC (12.2) and the training callbacks
    train           CLI: train a run into ml/runs/<name>/
    evaluate        CER, exact match, abstain rate, accuracy when answered
    export          CLI: float16 TFLite + metrics.json + sha256 into ml/models/digit_crnn/<version>/
    import_dataset  CLI: validate and merge the team's real dataset (path,label,writer_key)
"""
