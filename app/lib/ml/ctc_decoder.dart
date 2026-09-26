import 'dart:typed_data';

/// Result of a greedy CTC decode (DESIGN §12.2).
class CtcDecoded {
  const CtcDecoded(this.text, this.confidence, this.charConfidences);

  final String text;

  /// Mean of [charConfidences]; 0.0 when nothing was emitted.
  final double confidence;

  /// Max probability at the timestep that emitted each character of [text].
  final List<double> charConfidences;

  @override
  String toString() => 'CtcDecoded($text, $confidence)';
}

/// Greedy (best path) CTC decode of one `timesteps x classes` probability
/// matrix stored row-major in [probs].
///
/// Mirrors `ml/train/charset.py::ctc_greedy_decode` exactly (the
/// `decode` block of the model's metrics.json): argmax per timestep (the
/// first index wins a tie, like numpy), collapse repeats, drop [blank];
/// confidence = mean, over the timesteps that EMIT a character (argmax !=
/// blank and != the previous timestep's argmax), of the max probability at
/// that timestep. Blank timesteps never lift the confidence, so one doubtful
/// digit among 31 certain blanks still abstains.
CtcDecoded ctcGreedyDecode(
  Float32List probs, {
  required int classes,
  required String charset,
  required int blank,
}) {
  if (classes <= 0 || probs.length % classes != 0) {
    throw ArgumentError(
      'probs has ${probs.length} values, not a multiple of $classes classes',
    );
  }
  final timesteps = probs.length ~/ classes;
  final chars = StringBuffer();
  final confidences = <double>[];
  var previous = -1;
  for (var t = 0; t < timesteps; t++) {
    final row = t * classes;
    var best = 0;
    var top = probs[row];
    for (var c = 1; c < classes; c++) {
      final p = probs[row + c];
      if (p > top) {
        top = p;
        best = c;
      }
    }
    if (best != previous && best != blank) {
      // An index outside the charset (a wider model) is dropped like
      // charset.decode() does; it still breaks a run of repeats.
      if (best < charset.length) {
        chars.write(charset[best]);
        confidences.add(top);
      }
    }
    previous = best;
  }
  final confidence = confidences.isEmpty
      ? 0.0
      : confidences.reduce((a, b) => a + b) / confidences.length;
  return CtcDecoded(
    chars.toString(),
    confidence,
    List.unmodifiable(confidences),
  );
}
