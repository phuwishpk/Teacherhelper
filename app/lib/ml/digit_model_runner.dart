import 'dart:io';
import 'dart:typed_data';

import 'digit_model_spec.dart';

/// Runs the digit model on prepared inputs. Behind an interface so the
/// recognizer is tested without the TFLite native library.
abstract interface class DigitModelRunner {
  /// [inputs]: one `height x width` float image each (ink = 1.0, paper =
  /// 0.0, row-major). Returns, per input, the `timesteps x classes` softmax
  /// output row-major.
  Future<List<Float32List>> run(List<Float32List> inputs);

  void close();
}

/// Turns a verified model file into a runner.
typedef DigitModelRunnerFactory =
    DigitModelRunner Function(File modelFile, DigitModelSpec spec);
