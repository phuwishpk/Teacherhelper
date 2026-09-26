import 'dart:typed_data';

import 'ctc_decoder.dart';
import 'digit_model_runner.dart';
import 'digit_model_spec.dart';

/// What the digit reader made of one numeric box (DESIGN §12.1).
class DigitReading {
  const DigitReading({
    required this.text,
    required this.confidence,
    required this.answered,
  });

  final String text;
  final double confidence;

  /// False when the reader abstains (confidence below the model's
  /// `decode.abstain_below`, or nothing read at all).
  final bool answered;

  @override
  String toString() =>
      'DigitReading($text, ${confidence.toStringAsFixed(3)}, '
      '${answered ? 'answered' : 'abstained'})';
}

/// The on-device second reader for numeric boxes (DESIGN §12): normalises
/// the 32 x 128 grayscale crop from the native pipeline, runs the model and
/// decodes it with the contract of the model's metrics.json.
class DigitRecognizer {
  DigitRecognizer({required this.spec, required this._runner});

  final DigitModelSpec spec;
  final DigitModelRunner _runner;

  /// `x = 1 - gray / 255` (ink = 1.0, paper = 0.0), the last preprocess
  /// step of metrics.json `input.preprocess`. The earlier steps (tight crop,
  /// resize to height 32, pad right with paper) are done natively
  /// (ScanPipelineImpl.cnnInput, mirror of ml/train/preprocess.py).
  Float32List normalize(Uint8List gray) {
    if (gray.length != spec.inputLength) {
      throw ArgumentError(
        'expected ${spec.inputHeight}x${spec.inputWidth} = '
        '${spec.inputLength} bytes, got ${gray.length}',
      );
    }
    final x = Float32List(gray.length);
    for (var i = 0; i < gray.length; i++) {
      x[i] = 1.0 - gray[i] / 255.0;
    }
    return x;
  }

  /// Decodes one model output with the greedy CTC contract and applies the
  /// abstain threshold.
  DigitReading decode(Float32List probs) {
    final d = ctcGreedyDecode(
      probs,
      classes: spec.numClasses,
      charset: spec.charset,
      blank: spec.blankIndex,
    );
    return DigitReading(
      text: d.text,
      confidence: d.confidence,
      answered: d.text.isNotEmpty && d.confidence >= spec.abstainBelow,
    );
  }

  /// Reads every image of [grayImages] (keyed by crop id).
  Future<Map<String, DigitReading>> readAll(
    Map<String, Uint8List> grayImages,
  ) async {
    if (grayImages.isEmpty) return const {};
    final ids = grayImages.keys.toList();
    final outputs = await _runner.run([
      for (final id in ids) normalize(grayImages[id]!),
    ]);
    if (outputs.length != ids.length) {
      throw StateError('model returned ${outputs.length} of ${ids.length}');
    }
    return {for (var i = 0; i < ids.length; i++) ids[i]: decode(outputs[i])};
  }

  void close() => _runner.close();
}
