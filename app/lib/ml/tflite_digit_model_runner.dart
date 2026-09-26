import 'dart:io';
import 'dart:typed_data';

import 'package:tflite_flutter/tflite_flutter.dart';

import 'digit_model_runner.dart';
import 'digit_model_spec.dart';

/// On-device runner (tflite_flutter / LiteRT on Android).
class TfliteDigitModelRunner implements DigitModelRunner {
  TfliteDigitModelRunner._(this._interpreter, this._spec);

  /// Loads [modelFile] and checks its tensors against [spec]
  /// (input `[1, H, W, 1]` float32, output `[1, T, C]` float32).
  factory TfliteDigitModelRunner.fromFile(File modelFile, DigitModelSpec spec) {
    final interpreter = Interpreter.fromFile(modelFile);
    try {
      final input = interpreter.getInputTensor(0);
      final output = interpreter.getOutputTensor(0);
      final inShape = input.shape;
      final outShape = output.shape;
      if (input.type != TensorType.float32 ||
          output.type != TensorType.float32 ||
          inShape.length != 4 ||
          inShape[1] != spec.inputHeight ||
          inShape[2] != spec.inputWidth ||
          outShape.length != 3 ||
          outShape[1] != spec.timesteps ||
          outShape[2] != spec.numClasses) {
        throw UnsupportedDigitModelException(
          'tensors $inShape ${input.type} -> $outShape ${output.type}',
        );
      }
      if (inShape[0] != 1) {
        interpreter.resizeInputTensor(0, [
          1,
          spec.inputHeight,
          spec.inputWidth,
          1,
        ]);
        interpreter.allocateTensors();
      }
      return TfliteDigitModelRunner._(interpreter, spec);
    } catch (_) {
      interpreter.close();
      rethrow;
    }
  }

  final Interpreter _interpreter;
  final DigitModelSpec _spec;
  bool _closed = false;

  @override
  Future<List<Float32List>> run(List<Float32List> inputs) async {
    if (_closed) throw StateError('digit model is closed');
    final results = <Float32List>[];
    for (final x in inputs) {
      if (x.length != _spec.inputLength) {
        throw ArgumentError('input has ${x.length} values');
      }
      _interpreter.getInputTensor(0).data = x.buffer.asUint8List(
        x.offsetInBytes,
        x.lengthInBytes,
      );
      _interpreter.invoke();
      final out = _interpreter.getOutputTensor(0).data;
      // Copy: the tensor memory is reused by the next invoke().
      final bytes = Uint8List.fromList(out);
      results.add(bytes.buffer.asFloat32List(0, _spec.outputLength));
    }
    return results;
  }

  @override
  void close() {
    if (_closed) return;
    _closed = true;
    _interpreter.close();
  }
}
