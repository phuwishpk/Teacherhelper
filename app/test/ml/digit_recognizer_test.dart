import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:eduvision/features/scan/scan_meta.dart';
import 'package:eduvision/features/scan/scan_processor.dart';
import 'package:eduvision/ml/digit_model_spec.dart';
import 'package:eduvision/ml/digit_recognizer.dart';
import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ml_fakes.dart';

/// The committed contract of the shipped model (metrics.json is in git,
/// only model.tflite is ignored).
const metricsPath = '../ml/models/digit_crnn/0.1.0/metrics.json';

Uint8List paper() => Uint8List(32 * 128)..fillRange(0, 32 * 128, 255);

void main() {
  group('DigitModelSpec', () {
    test('reads digit_crnn 0.1.0 metrics.json', () {
      final metrics =
          jsonDecode(File(metricsPath).readAsStringSync())
              as Map<String, dynamic>;
      final spec = DigitModelSpec.fromMetrics(metrics);
      expect(spec.charset, '0123456789.-/');
      expect(spec.blankIndex, 13);
      expect(spec.numClasses, 14);
      expect(spec.timesteps, 32);
      expect(spec.inputHeight, 32);
      expect(spec.inputWidth, 128);
      expect(spec.abstainBelow, 0.8);
    });

    test('takes abstain_below from the metrics, defaults otherwise', () {
      expect(
        DigitModelSpec.fromMetrics({
          'decode': {'abstain_below': 0.98},
        }).abstainBelow,
        0.98,
      );
      expect(DigitModelSpec.fromMetrics(const {}).abstainBelow, 0.8);
    });

    test('refuses a decode this app does not implement', () {
      expect(
        () => DigitModelSpec.fromMetrics({
          'decode': {'method': 'ctc_beam'},
        }),
        throwsA(isA<UnsupportedDigitModelException>()),
      );
      expect(
        () => DigitModelSpec.fromMetrics({
          'decode': {'confidence': 'mean_max_prob_all_timesteps'},
        }),
        throwsA(isA<UnsupportedDigitModelException>()),
      );
      expect(
        () =>
            DigitModelSpec.fromMetrics({'charset': '0123', 'num_classes': 14}),
        throwsA(isA<UnsupportedDigitModelException>()),
      );
    });
  });

  group('DigitRecognizer', () {
    test('normalises gray bytes to ink = 1, paper = 0', () {
      final r = DigitRecognizer(
        spec: const DigitModelSpec(),
        runner: FakeDigitRunner((_) => outputFor('')),
      );
      final gray = paper()
        ..[0] = 0
        ..[1] = 51;
      final x = r.normalize(gray);
      expect(x[0], 1.0);
      expect(x[1], closeTo(0.8, 1e-6));
      expect(x[2], 0.0);
      expect(() => r.normalize(Uint8List(10)), throwsArgumentError);
    });

    test('answers above the threshold and abstains below it', () async {
      // The fake reads the first pixel: dark = a confident "125", else a
      // doubtful "7".
      final runner = FakeDigitRunner(
        (x) => x[0] > 0.5 ? outputFor('125', p: 0.97) : outputFor('7', p: 0.6),
      );
      final r = DigitRecognizer(
        spec: const DigitModelSpec(abstainBelow: 0.8),
        runner: runner,
      );
      final readings = await r.readAll({
        'q502': paper()..[0] = 0,
        'q503_final': paper(),
      });
      expect(readings['q502']!.text, '125');
      expect(readings['q502']!.confidence, closeTo(0.97, 1e-6));
      expect(readings['q502']!.answered, isTrue);
      expect(readings['q503_final']!.text, '7');
      expect(readings['q503_final']!.answered, isFalse);
      expect(runner.inputs, hasLength(2));

      r.close();
      expect(runner.closed, isTrue);
    });

    test('an empty read never counts as an answer', () {
      final r = DigitRecognizer(
        spec: const DigitModelSpec(abstainBelow: 0),
        runner: FakeDigitRunner((_) => outputFor('')),
      );
      final reading = r.decode(outputFor(''));
      expect(reading.text, '');
      expect(reading.answered, isFalse);
    });
  });

  test(
    'numeric crops become cnn readings; abstained ones an empty cnn',
    () async {
      final r = DigitRecognizer(
        spec: const DigitModelSpec(),
        runner: FakeDigitRunner(
          (x) =>
              x[0] > 0.5 ? outputFor('125', p: 0.97) : outputFor('7', p: 0.5),
        ),
      );
      final crops = PageCrops(
        warpedPagePath: '/tmp/page.webp',
        regions: [
          RegionCrop(
            regionId: 'q501',
            imagePath: '/tmp/q501.webp',
            inkRatio: 0.1,
            bubbleFill: {'A': 0.9},
          ),
          RegionCrop(
            regionId: 'q502',
            imagePath: '/tmp/q502.webp',
            inkRatio: 0.1,
            cnnInput: paper()..[0] = 0,
          ),
          RegionCrop(
            regionId: 'q503_final',
            imagePath: '/tmp/q503.webp',
            inkRatio: 0.1,
            cnnInput: paper(),
          ),
          // A wrong-sized buffer is skipped, not fed to the model.
          RegionCrop(
            regionId: 'q504',
            imagePath: '/tmp/q504.webp',
            inkRatio: 0.1,
            cnnInput: Uint8List(8),
          ),
        ],
      );
      final readings = await readNumericCrops(r, crops);
      expect(readings.keys, unorderedEquals(['q502', 'q503_final']));
      expect(readings['q502']!.toJson(), {'text': '125', 'confidence': 0.97});
      expect(readings['q503_final']!.abstained, isTrue);
      expect(readings['q503_final']!.toJson(), isEmpty);
      expect(const CnnReading.abstained().toJson(), <String, dynamic>{});
    },
  );
}
