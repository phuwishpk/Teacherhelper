// Smoke test of the shipped digit model (DESIGN §12) against the Dart
// reader. Runs only on a developer machine that has the (gitignored)
// ml/models/digit_crnn/0.1.0/model.tflite; skipped otherwise.
//
// The TFLite native library of tflite_flutter exists only inside an
// Android/iOS/desktop app bundle, not in `flutter test`, so the interpreter
// step runs in the ml/ Python environment (`uv sync --extra train`, the
// same TFLite runtime the model was validated with). Everything else is the
// app's own code: sha256 check, metrics contract, normalisation, greedy CTC
// decode and abstain rule, compared with ml/train/charset.py.
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:eduvision/ml/digit_model_runner.dart';
import 'package:eduvision/ml/digit_model_spec.dart';
import 'package:eduvision/ml/digit_recognizer.dart';
import 'package:eduvision/ml/model_repository.dart';
import 'package:flutter_test/flutter_test.dart';

const _modelDir = '../ml/models/digit_crnn/0.1.0';
final _model = File('$_modelDir/model.tflite');
final _metrics = File('$_modelDir/metrics.json');
final _python = File('../ml/.venv/bin/python');
final _labels = File('../ml/data/synth/labels.csv');

/// Prepares [n] synthetic crops exactly like the native pipeline does
/// (tight crop + fit to 32 x 128, ScanPipelineImpl.cnnInput mirrors
/// ml/train/preprocess.py) and prints them as JSON.
const _prepScript = r'''
import base64, csv, json, sys
import cv2
from train import preprocess
rows = list(csv.DictReader(open("data/synth/labels.csv", encoding="utf-8")))
n = int(sys.argv[1])
step = max(1, len(rows) // n)
out = []
for row in rows[::step][:n]:
    gray = preprocess.to_gray(cv2.imread("data/synth/" + row["path"], cv2.IMREAD_UNCHANGED))
    canvas = preprocess.fit_to_canvas(preprocess.tight_crop(gray))
    out.append({"label": row["label"], "gray": base64.b64encode(canvas.tobytes()).decode()})
json.dump(out, open(sys.argv[2], "w"))
''';

/// Runs the TFLite model on float inputs prepared by the Dart side and
/// writes the softmax outputs plus the reference decode of
/// ml/train/charset.py.
const _runScript = r'''
import json, sys
import numpy as np
from train.charset import ctc_greedy_decode
from train.evaluate import TFLiteRunner
x = np.fromfile(sys.argv[2], dtype=np.float32).reshape(-1, 32, 128, 1)
probs = TFLiteRunner(sys.argv[1]).predict(x).astype(np.float32)
probs.tofile(sys.argv[3])
ref = [ctc_greedy_decode(p) for p in probs]
json.dump([{"text": d.text, "confidence": d.confidence} for d in ref], open(sys.argv[4], "w"))
''';

Future<ProcessResult> _py(String script, List<String> args) => Process.run(
  _python.absolute.path,
  ['-c', script, ...args],
  workingDirectory: Directory('../ml').absolute.path,
);

/// The Python TFLite interpreter behind the app's runner interface.
class _PythonTfliteRunner implements DigitModelRunner {
  _PythonTfliteRunner(this.tmp, this.spec);

  final Directory tmp;
  final DigitModelSpec spec;
  List<Map<String, dynamic>> reference = const [];

  @override
  Future<List<Float32List>> run(List<Float32List> inputs) async {
    final inFile = File('${tmp.path}/in.f32');
    final outFile = File('${tmp.path}/out.f32');
    final refFile = File('${tmp.path}/ref.json');
    final all = Float32List(inputs.length * spec.inputLength);
    for (var i = 0; i < inputs.length; i++) {
      all.setAll(i * spec.inputLength, inputs[i]);
    }
    await inFile.writeAsBytes(all.buffer.asUint8List());
    final r = await _py(_runScript, [
      _model.absolute.path,
      inFile.path,
      outFile.path,
      refFile.path,
    ]);
    if (r.exitCode != 0) throw StateError('tflite run failed: ${r.stderr}');
    reference = (jsonDecode(await refFile.readAsString()) as List)
        .cast<Map<String, dynamic>>();
    final probs = (await outFile.readAsBytes()).buffer.asFloat32List();
    return [
      for (var i = 0; i < inputs.length; i++)
        Float32List.sublistView(
          probs,
          i * spec.outputLength,
          (i + 1) * spec.outputLength,
        ),
    ];
  }

  @override
  void close() {}
}

void main() {
  final skipNoModel = _model.existsSync()
      ? false
      : 'ml/models/digit_crnn/0.1.0/model.tflite is not on this machine';

  test('model.tflite matches the sha256 of its metrics.json', () async {
    final metrics =
        jsonDecode(await _metrics.readAsString()) as Map<String, dynamic>;
    final digest = await fileSha256(_model);
    expect(digest, metrics['sha256']);
    expect(
      (await File(
        '$_modelDir/model.tflite.sha256',
      ).readAsString()).split(' ')[0],
      digest,
    );
    expect(await _model.length(), metrics['size_bytes']);
    expect(await _model.length(), lessThan(2 * 1024 * 1024)); // DESIGN §12.2
  }, skip: skipNoModel);

  test(
    'the Dart reader decodes real model output like ml/train/charset.py',
    () async {
      if (!_python.existsSync() || !_labels.existsSync()) {
        markTestSkipped(
          'needs ml/.venv (uv sync --extra train) and data/synth',
        );
        return;
      }
      final probe = await _py('import tensorflow, cv2', const []);
      if (probe.exitCode != 0) {
        markTestSkipped('tensorflow is not installed in ml/.venv');
        return;
      }
      final tmp = await Directory.systemTemp.createTemp('digit_smoke');
      addTearDown(() => tmp.delete(recursive: true));

      const n = 60;
      final prepFile = File('${tmp.path}/prep.json');
      final prep = await _py(_prepScript, ['$n', prepFile.path]);
      expect(prep.exitCode, 0, reason: '${prep.stderr}');
      final samples = (jsonDecode(await prepFile.readAsString()) as List)
          .cast<Map<String, dynamic>>();
      expect(samples, hasLength(n));

      final spec = DigitModelSpec.fromMetrics(
        jsonDecode(await _metrics.readAsString()) as Map<String, dynamic>,
      );
      final runner = _PythonTfliteRunner(tmp, spec);
      final reader = DigitRecognizer(spec: spec, runner: runner);
      final readings = await reader.readAll({
        for (var i = 0; i < samples.length; i++)
          '$i': base64Decode(samples[i]['gray'] as String),
      });

      var exact = 0, answered = 0, answeredRight = 0;
      for (var i = 0; i < samples.length; i++) {
        final r = readings['$i']!;
        final ref = runner.reference[i];
        // Same text and confidence as the Python reference decoder.
        expect(r.text, ref['text'], reason: 'sample $i');
        expect(
          r.confidence,
          closeTo((ref['confidence'] as num).toDouble(), 1e-5),
          reason: 'sample $i',
        );
        expect(r.answered, r.confidence >= spec.abstainBelow);
        final right = r.text == samples[i]['label'];
        if (right) exact++;
        if (r.answered) {
          answered++;
          if (right) answeredRight++;
        }
      }
      // digit_crnn 0.1.0 reads ~82% of synthetic test strings exactly and
      // ~83% of those it answers; a broken normalisation or tensor layout
      // drops this to ~0.
      expect(exact / n, greaterThan(0.6), reason: 'exact $exact/$n');
      expect(answered, greaterThan(n ~/ 2));
      expect(answeredRight / answered, greaterThan(0.6));
    },
    skip: skipNoModel,
    timeout: const Timeout(Duration(minutes: 4)),
  );
}
