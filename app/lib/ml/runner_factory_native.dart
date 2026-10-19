import 'dart:io';

import 'digit_model_runner.dart';
import 'tflite_digit_model_runner.dart';

/// TFLite (LiteRT) ships in the Android and iOS builds of the app; desktop
/// test runs have no native library, so the reader is off there.
DigitModelRunnerFactory? platformDigitModelRunnerFactory() =>
    Platform.isAndroid || Platform.isIOS
    ? TfliteDigitModelRunner.fromFile
    : null;
