import 'dart:typed_data';

import 'package:eduvision/ml/ctc_decoder.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ml_fakes.dart';

CtcDecoded decode(Float32List probs) =>
    ctcGreedyDecode(probs, classes: classes, charset: charset, blank: blank);

/// The cases of ml/tests/test_charset.py, so the Dart decoder and
/// ml/train/charset.py::ctc_greedy_decode cannot drift apart.
void main() {
  test('collapses repeats and drops blanks', () {
    const b = blank;
    final d = decode(oneHotPath([1, 1, b, 1, b, b, 10, 3, 3, b]));
    expect(d.text, '11.3');
    expect(d.confidence, closeTo(0.9, 1e-6));
  });

  test('confidence is the mean max probability over emitting timesteps', () {
    // t0 emits "0" at 0.6, t1 emits "2" at 0.6, t2 is a certain blank that
    // must NOT lift the confidence.
    final probs = Float32List(3 * classes);
    probs
      ..[0] = 0.6
      ..[1] = 0.4
      ..[classes + 0] = 0.2
      ..[classes + 1] = 0.2
      ..[classes + 2] = 0.6
      ..[2 * classes + blank] = 1.0;
    final d = decode(probs);
    expect(d.text, '02');
    expect(d.charConfidences, [closeTo(0.6, 1e-6), closeTo(0.6, 1e-6)]);
    expect(d.confidence, closeTo(0.6, 1e-6));
  });

  test('ignores blanks and collapsed repeats in the confidence', () {
    const b = blank;
    final d = decode(
      pathWithProbs(
        [1, 1, b, 1, b, b, 10, 3, 3, b],
        [0.9, 0.3, 0.99, 0.7, 0.99, 0.99, 0.8, 0.6, 0.2, 0.99],
      ),
    );
    expect(d.text, '11.3');
    // The first timestep of each run only.
    expect(d.charConfidences.map((c) => c.toStringAsFixed(4)), [
      '0.9000',
      '0.7000',
      '0.8000',
      '0.6000',
    ]);
    expect(d.confidence, closeTo(0.75, 1e-6));
  });

  test('31 certain blanks do not mask one doubtful digit', () {
    final d = decode(
      pathWithProbs(
        [...List.filled(31, blank), 7],
        [...List.filled(31, 0.999), 0.5],
      ),
    );
    expect(d.text, '7');
    expect(d.confidence, closeTo(0.5, 1e-6));
  });

  test('an all-blank path is empty with confidence 0', () {
    final d = decode(oneHotPath(List.filled(32, blank)));
    expect(d.text, '');
    expect(d.charConfidences, isEmpty);
    expect(d.confidence, 0.0);
  });

  test('reads every character of the charset (batch case of the Python '
      'suite)', () {
    expect(decode(oneHotPath([4, 4, 13, 2])).text, '42');
    expect(decode(oneHotPath([11, 7, 13, 13])).text, '-7');
    expect(decode(oneHotPath([3, 12, 4])).text, '3/4');
  });

  test('ties go to the lowest class index, like numpy argmax', () {
    final probs = Float32List(classes)
      ..[5] = 0.5
      ..[blank] = 0.5;
    expect(decode(probs).text, '5');
  });

  test('rejects a buffer that is not timesteps x classes', () {
    expect(() => decode(Float32List(classes + 1)), throwsArgumentError);
  });
}
