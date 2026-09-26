import 'dart:io';
import 'dart:typed_data';

import 'package:eduvision/features/scan/scan_processor.dart';
import 'package:eduvision/ml/digit_model_spec.dart';
import 'package:eduvision/ml/ml_providers.dart';
import 'package:eduvision/ml/model_repository.dart';
import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ml_fakes.dart';

class _FakeModels implements ModelRepository {
  _FakeModels({this.model});

  InstalledModel? model;
  ModelSyncResult syncResult = const NoActiveModel();
  int syncs = 0;

  @override
  Future<InstalledModel?> installed(String name) async => model;

  @override
  Future<ModelSyncResult> sync(String name) async {
    syncs++;
    return syncResult;
  }

  @override
  Future<ActiveModel?> active(String name) => throw UnimplementedError();
}

InstalledModel _model(String version, {double abstainBelow = 0.9}) =>
    InstalledModel(
      name: digitModelName,
      version: version,
      sha256: 'x' * 64,
      file: File('/models/digit_crnn/$version.tflite'),
      metrics: {
        'decode': {
          'method': 'ctc_greedy',
          'confidence': 'emitting_mean_max_prob',
          'abstain_below': abstainBelow,
        },
      },
    );

PageCrops _crops() => PageCrops(
  warpedPagePath: '/tmp/page.webp',
  regions: [
    RegionCrop(
      regionId: 'q502',
      imagePath: '/tmp/q502.webp',
      inkRatio: 0.1,
      cnnInput: Uint8List(32 * 128)..fillRange(0, 32 * 128, 255),
    ),
    RegionCrop(regionId: 'q503', imagePath: '/tmp/q503.webp', inkRatio: 0.1),
  ],
);

void main() {
  test('the scan reader uses the installed model and its threshold', () async {
    final loaded = <(String, double)>[];
    final models = _FakeModels(model: _model('0.1.0', abstainBelow: 0.9));
    final c = ProviderContainer(
      overrides: [
        modelRepositoryProvider.overrideWithValue(models),
        digitModelRunnerFactoryProvider.overrideWithValue((file, spec) {
          loaded.add((file.path, spec.abstainBelow));
          return FakeDigitRunner((_) => outputFor('42', p: 0.85));
        }),
      ],
    );
    addTearDown(c.dispose);

    final reader = c.read(digitReaderProvider)!;
    final readings = await reader(_crops());
    expect(loaded, [('/models/digit_crnn/0.1.0.tflite', 0.9)]);
    // 0.85 < 0.9 from the model's metrics: abstained, sent as `cnn: {}`.
    expect(readings.keys, ['q502']);
    expect(readings['q502']!.abstained, isTrue);

    // A later model with the default threshold answers the same output.
    models.model = _model('0.2.0', abstainBelow: 0.8);
    c.invalidate(digitRecognizerProvider);
    final again = await reader(_crops());
    expect(again['q502']!.toJson(), {'text': '42', 'confidence': 0.85});
  });

  test('no model on the device: no readings, no runner', () async {
    var created = 0;
    final c = ProviderContainer(
      overrides: [
        modelRepositoryProvider.overrideWithValue(_FakeModels()),
        digitModelRunnerFactoryProvider.overrideWithValue((file, spec) {
          created++;
          return FakeDigitRunner((_) => outputFor('1'));
        }),
      ],
    );
    addTearDown(c.dispose);
    expect(await c.read(digitReaderProvider)!(_crops()), isEmpty);
    expect(created, 0);
  });

  test('a model whose decode is unsupported is not used', () async {
    final c = ProviderContainer(
      overrides: [
        modelRepositoryProvider.overrideWithValue(
          _FakeModels(
            model: InstalledModel(
              name: digitModelName,
              version: '9.0.0',
              sha256: 'x' * 64,
              file: File('/m.tflite'),
              metrics: {
                'decode': {'method': 'ctc_beam'},
              },
            ),
          ),
        ),
        digitModelRunnerFactoryProvider.overrideWithValue(
          (file, spec) => FakeDigitRunner((_) => outputFor('1')),
        ),
      ],
    );
    addTearDown(c.dispose);
    expect(await c.read(digitRecognizerProvider.future), isNull);
  });

  test('without TFLite (tests, web) there is no reader at all', () {
    final c = ProviderContainer(
      overrides: [digitModelRunnerFactoryProvider.overrideWithValue(null)],
    );
    addTearDown(c.dispose);
    expect(c.read(digitReaderProvider), isNull);
  });

  group('DigitModelUpdater', () {
    late DateTime now;
    late _FakeModels models;
    late ProviderContainer c;
    late int runners;

    setUp(() {
      now = DateTime.utc(2026, 9, 25, 8);
      runners = 0;
      models = _FakeModels(model: _model('0.1.0'));
      c = ProviderContainer(
        overrides: [
          modelRepositoryProvider.overrideWithValue(models),
          digitModelRunnerFactoryProvider.overrideWithValue((file, spec) {
            runners++;
            return FakeDigitRunner((_) => outputFor('1'));
          }),
          digitModelUpdaterProvider.overrideWith(
            (ref) => DigitModelUpdater(ref, clock: () => now),
          ),
        ],
      );
    });

    tearDown(() => c.dispose());

    test('an update reloads the reader; checks are spaced out', () async {
      expect(await c.read(digitRecognizerProvider.future), isNotNull);
      expect(runners, 1);

      models
        ..model = _model('0.2.0')
        ..syncResult = ModelUpdated(_model('0.2.0'));
      final updater = c.read(digitModelUpdaterProvider);
      expect(await updater.maybeSync(), isA<ModelUpdated>());
      final reloaded = await c.read(digitRecognizerProvider.future);
      expect(reloaded, isNotNull);
      expect(runners, 2, reason: 'the new model file was loaded');

      // Within 6 hours: no second request.
      now = now.add(const Duration(hours: 5));
      expect(await updater.maybeSync(), isNull);
      expect(models.syncs, 1);
      now = now.add(const Duration(hours: 2));
      models.syncResult = ModelUpToDate(_model('0.2.0'));
      expect(await updater.maybeSync(), isA<ModelUpToDate>());
      expect(models.syncs, 2);
    });

    test('a failed check is retried after two minutes', () async {
      models.syncResult = const ModelSyncFailed('offline');
      final updater = c.read(digitModelUpdaterProvider);
      expect(await updater.maybeSync(), isA<ModelSyncFailed>());
      now = now.add(const Duration(minutes: 1));
      expect(await updater.maybeSync(), isNull);
      now = now.add(const Duration(minutes: 2));
      expect(await updater.maybeSync(), isA<ModelSyncFailed>());
      expect(models.syncs, 2);
    });
  });

  test('the spec defaults match digit_crnn 0.1.0', () {
    const spec = DigitModelSpec();
    expect(spec.inputLength, 32 * 128);
    expect(spec.outputLength, 32 * 14);
  });
}
