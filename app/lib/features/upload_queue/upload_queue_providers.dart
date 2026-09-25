import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/db/app_database.dart';
import 'queued_scan.dart';
import 'scan_queue_repository.dart';
import 'scan_uploader.dart';
import 'upload_worker.dart';

/// Live view of the queue for the queue screen and the badge in the shell.
final uploadQueueProvider = StreamProvider<List<QueuedScan>>(
  (ref) => ref.watch(scanQueueRepositoryProvider).watchAll(),
);

/// Number of scans that still need attention (not done).
final uploadQueueOpenCountProvider = Provider<int>((ref) {
  return ref
      .watch(uploadQueueProvider)
      .maybeWhen(
        data: (scans) => scans.where((s) => s.state != ScanState.done).length,
        orElse: () => 0,
      );
});

/// Foreground actions on the queue: upload now, retry, confirm, discard.
class UploadQueueActions extends Notifier<bool> {
  @override
  bool build() => false; // true while a foreground drain is running

  Future<DrainResult> uploadNow() async {
    if (state) {
      return const DrainResult(done: 0, conflict: 0, failed: 0, retry: 0);
    }
    state = true;
    final scheduler = ref.read(uploadSchedulerProvider);
    try {
      // Do not let the WorkManager isolate pick the same rows meanwhile.
      await scheduler.cancelPending();
      final result = await ref.read(scanUploaderProvider).drain();
      // Anything still pending (this drain's retries, scans whose backoff
      // has not elapsed, or an upload left behind by a killed isolate) goes
      // back to WorkManager.
      final awaiting = await ref
          .read(scanQueueRepositoryProvider)
          .countAwaitingUpload();
      if (awaiting > 0) await scheduler.requestUpload();
      return result;
    } finally {
      state = false;
    }
  }

  Future<void> retry(QueuedScan scan) async {
    await ref
        .read(scanQueueRepositoryProvider)
        .resetToPending(scan.clientScanId);
    await uploadNow();
  }

  Future<void> confirmReplace(QueuedScan scan) =>
      ref.read(scanUploaderProvider).confirmReplace(scan);

  Future<void> discard(QueuedScan scan) =>
      ref.read(scanQueueRepositoryProvider).remove(scan.clientScanId);

  Future<void> clearDone() => ref.read(scanQueueRepositoryProvider).clearDone();
}

final uploadQueueActionsProvider = NotifierProvider<UploadQueueActions, bool>(
  UploadQueueActions.new,
);
