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
      final uploader = ScanUploader(
        dio: dio,
        repository: ScanQueueRepository(db),
      );
      final result = await uploader.drain();
      return result.settled;
    } finally {
      await db.close();
    }
  });
}

/// Schedules background uploads; abstract so tests and the Chrome preview
/// never touch platform channels.
abstract class UploadScheduler {
  Future<void> initialize();

  /// Ask the OS to run the upload task as soon as the network is available.
  Future<void> requestUpload();
}

class NoopUploadScheduler implements UploadScheduler {
  const NoopUploadScheduler();

  @override
  Future<void> initialize() async {}

  @override
  Future<void> requestUpload() async {}
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
}

bool get _backgroundUploadsSupported => !kIsWeb && Platform.isAndroid;

final uploadSchedulerProvider = Provider<UploadScheduler>(
  (ref) => _backgroundUploadsSupported
      ? const WorkmanagerUploadScheduler()
      : const NoopUploadScheduler(),
);
