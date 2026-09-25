import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/upload_queue/scan_queue_repository.dart';
import '../../features/upload_queue/upload_worker.dart';
import '../db/app_database.dart';
import '../db/database_provider.dart';

/// What a signed-in user leaves in drift (DESIGN §6.4): the offline cache
/// (`cached_rosters` with student names, `cached_layouts`) and the upload
/// queue (`scan_queue` with crops of student handwriting). A school phone is
/// often shared, so none of it may carry over to the next user; the next
/// teacher's token would also upload the previous teacher's scans.
///
/// `model_cache` is kept: the digit model is not personal data.
class LocalUserData {
  LocalUserData({
    required this._db,
    required this._scans,
    required this._scheduler,
  });

  final AppDatabase _db;
  final ScanQueueRepository _scans;
  final UploadScheduler _scheduler;

  /// Scans the server does not have yet; signing out would discard them.
  Future<int> unsentScanCount() => _scans.countUnsent();

  /// Stops background uploads and deletes the queue (with its image files)
  /// and the offline roster / layout cache.
  Future<void> wipe() async {
    await _scheduler.cancelPending();
    await _scans.removeAll();
    await _db.transaction(() async {
      await _db.delete(_db.cachedRosters).go();
      await _db.delete(_db.cachedLayouts).go();
    });
  }
}

final localUserDataProvider = Provider<LocalUserData>(
  (ref) => LocalUserData(
    db: ref.watch(appDatabaseProvider),
    scans: ref.watch(scanQueueRepositoryProvider),
    scheduler: ref.watch(uploadSchedulerProvider),
  ),
);
