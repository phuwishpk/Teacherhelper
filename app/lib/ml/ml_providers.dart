import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../core/auth/auth_repository.dart';
import '../core/db/database_provider.dart';
import 'digit_model_runner.dart';
import 'digit_model_spec.dart';
import 'digit_recognizer.dart';
import 'model_repository.dart';
import 'runner_factory.dart';

/// `model_versions.name` of the on-device digit reader (DESIGN §12).
const digitModelName = 'digit_crnn';

/// Where downloaded models live (app support dir, not user-visible).
final modelsDirectoryProvider = Provider<Future<Directory> Function()>(
  (ref) => () async {
    final base = await getApplicationSupportDirectory();
    return Directory(p.join(base.path, 'models'));
  },
);

final modelRepositoryProvider = Provider<ModelRepository>(
  (ref) => ModelRepository(
    dio: ref.watch(dioProvider),
    db: ref.watch(appDatabaseProvider),
    directory: ref.watch(modelsDirectoryProvider),
  ),
);

/// How a model file becomes a runner; null where TFLite is not available
/// (web preview, desktop test runs), which turns the digit reader off.
final digitModelRunnerFactoryProvider = Provider<DigitModelRunnerFactory?>(
  (ref) => platformDigitModelRunnerFactory(),
);

/// The digit reader of the installed model, or null when no model is on
/// the device yet (the scan then carries no `cnn`, DESIGN §12.1: it is a
/// second opinion only). Kept for the app's lifetime; [DigitModelUpdater]
/// invalidates it after installing a new version.
final digitRecognizerProvider = FutureProvider<DigitRecognizer?>((ref) async {
  final factory = ref.watch(digitModelRunnerFactoryProvider);
  if (factory == null) return null;
  try {
    final model = await ref
        .watch(modelRepositoryProvider)
        .installed(digitModelName);
    if (model == null) return null;
    final spec = DigitModelSpec.fromMetrics(model.metrics);
    final recognizer = DigitRecognizer(
      spec: spec,
      runner: factory(model.file, spec),
    );
    ref.onDispose(recognizer.close);
    return recognizer;
  } catch (e) {
    debugPrint('digit model not loaded: $e');
    return null;
  }
});

/// Checks `/ml/models/active` now and then and installs a newer digit
/// model (DESIGN §9.8). Called when the teacher shell or the scan screen
/// opens; never blocks scanning, which works offline with what is cached.
class DigitModelUpdater {
  DigitModelUpdater(this._ref, {DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  final Ref _ref;
  final DateTime Function() _clock;

  static const recheckAfter = Duration(hours: 6);
  static const retryAfter = Duration(minutes: 2);

  Future<ModelSyncResult>? _running;
  DateTime? _nextCheck;
  ModelSyncResult? lastResult;

  /// Syncs unless a check ran recently; null when skipped.
  Future<ModelSyncResult?> maybeSync() {
    if (_ref.read(digitModelRunnerFactoryProvider) == null) {
      return Future.value();
    }
    if (_running case final running?) return running;
    final next = _nextCheck;
    if (next != null && _clock().isBefore(next)) return Future.value();
    final run = _sync();
    _running = run;
    return run.whenComplete(() => _running = null);
  }

  Future<ModelSyncResult> _sync() async {
    ModelSyncResult result;
    try {
      result = await _ref.read(modelRepositoryProvider).sync(digitModelName);
    } catch (e) {
      result = ModelSyncFailed('$e');
    }
    lastResult = result;
    _nextCheck = _clock().add(
      result is ModelSyncFailed ? retryAfter : recheckAfter,
    );
    if (result is ModelUpdated) {
      _ref.invalidate(digitRecognizerProvider);
    } else if (result is ModelSyncFailed) {
      debugPrint('digit model sync: ${result.reason}');
    }
    return result;
  }
}

final digitModelUpdaterProvider = Provider<DigitModelUpdater>(
  DigitModelUpdater.new,
);

/// Fire-and-forget sync for widgets (errors are already folded into the
/// result).
void syncDigitModelInBackground(WidgetRef ref) {
  unawaited(ref.read(digitModelUpdaterProvider).maybeSync());
}
