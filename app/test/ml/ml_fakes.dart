import 'dart:typed_data';

import 'package:eduvision/ml/digit_model_runner.dart';
import 'package:eduvision/ml/digit_model_spec.dart';

const charset = DigitModelSpec.defaultCharset;
const blank = 13;
const classes = 14;

/// Mirror of `one_hot_path` in ml/tests/test_charset.py: 0.9 on the given
/// class, 0.01 on the others.
Float32List oneHotPath(List<int> indices) {
  final probs = Float32List(indices.length * classes)
    ..fillRange(0, indices.length * classes, 0.01);
  for (var t = 0; t < indices.length; t++) {
    probs[t * classes + indices[t]] = 0.9;
  }
  return probs;
}

/// Mirror of `path_with_probs`: argmax [indices] with max probability
/// [top], the rest of the mass spread evenly.
Float32List pathWithProbs(List<int> indices, List<double> top) {
  final probs = Float32List(indices.length * classes);
  for (var t = 0; t < indices.length; t++) {
    final rest = (1.0 - top[t]) / (classes - 1);
    for (var c = 0; c < classes; c++) {
      probs[t * classes + c] = rest;
    }
    probs[t * classes + indices[t]] = top[t];
  }
  return probs;
}

/// A 32-timestep output that reads [text] with every emitting timestep at
/// probability [p] (blanks certain).
Float32List outputFor(String text, {double p = 0.99, int timesteps = 32}) {
  final indices = <int>[];
  final top = <double>[];
  for (final ch in text.split('')) {
    indices
      ..add(charset.indexOf(ch))
      ..add(blank);
    top
      ..add(p)
      ..add(0.999);
  }
  while (indices.length < timesteps) {
    indices.add(blank);
    top.add(0.999);
  }
  return pathWithProbs(indices, top);
}

/// Answers each input with a scripted output and records what it got.
class FakeDigitRunner implements DigitModelRunner {
  FakeDigitRunner(this.outputFor);

  /// Picks the output from the (normalised) input.
  final Float32List Function(Float32List input) outputFor;
  final inputs = <Float32List>[];
  bool closed = false;

  @override
  Future<List<Float32List>> run(List<Float32List> batch) async {
    inputs.addAll(batch);
    return [for (final x in batch) outputFor(x)];
  }

  @override
  void close() => closed = true;
}
