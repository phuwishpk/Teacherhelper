import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:workmanager/workmanager.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/token_storage.dart';
import '../../core/db/app_database.dart';
import '../../core/db/database_provider.dart';
import 'scan_queue_repository.dart';
import 'scan_uploader.dart';

/// Task name handed to the background isolate.
const uploadTaskName = 'eduvision.upload_scans';

/// Unique name so only one upload task is ever queued.
const uploadTaskUniqueName = 'eduvision.upload_scans.once';

/// Entry point of the workmanager background isolate. Drains the queue; a
/// `false` return makes WorkManager retry with its own exponential backoff
/// (DESIGN §6.4), on top of the per-scan backoff stored in the queue.
@pragma('vm:entry-point')
void uploadCallbackDispatcher() {
  Workmanager().executeTask((task, inputData) async {
    if (task != uploadTaskName) return true;
    final db = AppDatabase(openAppDatabase());
    try {
      final dio = createDio(
        tokenStorage: SecureTokenStorage(),
        onUnauthorized: () {},
      );
      final repository = ScanQueueRepository(db);
      return runBackgroundDrain(
        ScanUploader(dio: dio, repository: repository),
        repository,
      );
    } finally {
      await db.close();
    }
  });
}

/// One background run: drain what is due, then tell WorkManager whether the
/// task is finished (`true`) or should be retried with backoff (`false`).
///
/// `DrainResult.settled` alone is not enough: when every pending scan still
/// has `next_attempt_at` in the future the drain visits nothing, and a
/// `true` here would let the scan sit in `pending` until the app is
/// reopened. The same goes for a row left in `uploading` by a killed
/// isolate, which only becomes due after `staleUploadingAfter`. So the
/// answer is "retry" whenever anything is still pending or uploading.
Future<bool> runBackgroundDrain(
  ScanUploader uploader,
  ScanQueueRepository repository,
) async {
  await uploader.drain();
  return await repository.countAwaitingUpload() == 0;
}

/// Schedules background uploads; abstract so tests and the Chrome preview
/// never touch platform channels.
abstract class UploadScheduler {
  Future<void> initialize();

  /// Ask the OS to run the upload task as soon as the network is available.
  Future<void> requestUpload();

  /// Drop the queued one-off task so a foreground drain does not race the
  /// background isolate on the same rows. Call [requestUpload] again after
  /// the drain if anything is still pending.
  Future<void> cancelPending();
}

class NoopUploadScheduler implements UploadScheduler {
  const NoopUploadScheduler();

  @override
  Future<void> initialize() async {}

  @override
  Future<void> requestUpload() async {}

  @override
  Future<void> cancelPending() async {}
}

class WorkmanagerUploadScheduler implements UploadScheduler {
  const WorkmanagerUploadScheduler();

  @override
  Future<void> initialize() =>
      Workmanager().initialize(uploadCallbackDispatcher);

  @override
  Future<void> requestUpload() => Workmanager().registerOneOffTask(
    uploadTaskUniqueName,
    uploadTaskName,
    constraints: Constraints(networkType: NetworkType.connected),
    existingWorkPolicy: ExistingWorkPolicy.keep,
    backoffPolicy: BackoffPolicy.exponential,
    backoffPolicyDelay: const Duration(seconds: 30),
  );

  @override
  Future<void> cancelPending() =>
      Workmanager().cancelByUniqueName(uploadTaskUniqueName);
}

/// iOS has no WorkManager (DESIGN §33), and the system suspends an app soon
/// after it leaves the screen, so uploads run in the app itself while it is
/// open: [requestUpload] drains the queue at once and, while anything is
/// still pending or uploading, drains again every [retryEvery].
class ForegroundUploadScheduler implements UploadScheduler {
  ForegroundUploadScheduler({
    required this.drain,
    required this.awaiting,
    this.retryEvery = const Duration(seconds: 30),
  });

  final Future<void> Function() drain;
  final Future<int> Function() awaiting;
  final Duration retryEvery;

  Timer? _retry;
  bool _draining = false;
  bool _again = false;

  @override
  Future<void> initialize() async {}

  @override
  Future<void> requestUpload() async {
    _retry?.cancel();
    _retry = null;
    if (_draining) {
      // A scan queued during a drain may have been passed over: go again.
      _again = true;
      return;
    }
    _draining = true;
    try {
      do {
        _again = false;
        try {
          await drain();
        } catch (e) {
          debugPrint('foreground upload: $e');
        }
      } while (_again);
    } finally {
      _draining = false;
    }
    var left = 0;
    try {
      left = await awaiting();
    } catch (e) {
      debugPrint('foreground upload: $e');
    }
    if (left > 0 && _retry == null) {
      _retry = Timer(retryEvery, () => unawaited(requestUpload()));
    }
  }

  @override
  Future<void> cancelPending() async {
    _retry?.cancel();
    _retry = null;
  }

  void dispose() {
    _retry?.cancel();
    _retry = null;
  }
}

bool get _backgroundUploadsSupported => !kIsWeb && Platform.isAndroid;

bool get _foregroundUploadsOnly => !kIsWeb && Platform.isIOS;

final uploadSchedulerProvider = Provider<UploadScheduler>((ref) {
  if (_backgroundUploadsSupported) return const WorkmanagerUploadScheduler();
  if (_foregroundUploadsOnly) {
    final scheduler = ForegroundUploadScheduler(
      drain: () async {
        await ref.read(scanUploaderProvider).drain();
      },
      awaiting: () =>
          ref.read(scanQueueRepositoryProvider).countAwaitingUpload(),
    );
    ref.onDispose(scheduler.dispose);
    return scheduler;
  }
  return const NoopUploadScheduler();
});
