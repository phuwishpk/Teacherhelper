import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/db/app_database.dart';
import '../../core/db/database_provider.dart';
import 'queued_scan.dart';

/// Local persistence for the upload queue (DESIGN §6.4 `scan_queue`).
/// All state transitions of a queued scan go through here so the uploader,
/// the scan screen and the queue screen agree on the rules.
class ScanQueueRepository {
  ScanQueueRepository(this._db, {DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _db;
  final DateTime Function() _clock;

  Future<void> enqueue({
    required String clientScanId,
    required Map<String, dynamic> meta,
    required Map<String, String> files,
    ScanState state = ScanState.pending,
  }) async {
    final now = _clock();
    await _db
        .into(_db.scanQueue)
        .insertOnConflictUpdate(
          ScanQueueCompanion.insert(
            clientScanId: clientScanId,
            state: state,
            metaJson: jsonEncode(meta),
            filesJson: jsonEncode(files),
            createdAt: now,
            updatedAt: now,
          ),
        );
  }

  Future<QueuedScan?> find(String clientScanId) async {
    final row = await (_db.select(
      _db.scanQueue,
    )..where((t) => t.clientScanId.equals(clientScanId))).getSingleOrNull();
    return row == null ? null : QueuedScan.fromRow(row);
  }

  Future<List<QueuedScan>> listAll() async {
    final rows = await (_db.select(
      _db.scanQueue,
    )..orderBy([(t) => OrderingTerm.desc(t.createdAt)])).get();
    return rows.map(QueuedScan.fromRow).toList();
  }

  Stream<List<QueuedScan>> watchAll() {
    return (_db.select(_db.scanQueue)
          ..orderBy([(t) => OrderingTerm.desc(t.createdAt)]))
        .watch()
        .map((rows) => rows.map(QueuedScan.fromRow).toList());
  }

  Future<List<QueuedScan>> listByState(ScanState state) async {
    final rows = await (_db.select(
      _db.scanQueue,
    )..where((t) => t.state.equalsValue(state))).get();
    return rows.map(QueuedScan.fromRow).toList();
  }

  /// Pending scans whose backoff delay has elapsed, oldest first. A scan left
  /// in `uploading` (app killed mid-upload) is picked up again too.
  Future<List<QueuedScan>> dueForUpload({DateTime? now}) async {
    final at = now ?? _clock();
    final rows =
        await (_db.select(_db.scanQueue)
              ..where(
                (t) =>
                    (t.state.equalsValue(ScanState.pending) |
                        t.state.equalsValue(ScanState.uploading)) &
                    (t.nextAttemptAt.isNull() |
                        t.nextAttemptAt.isSmallerOrEqualValue(at)),
              )
              ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
            .get();
    return rows.map(QueuedScan.fromRow).toList();
  }

  Future<int> countByState(ScanState state) async {
    final count = _db.scanQueue.clientScanId.count();
    final q = _db.selectOnly(_db.scanQueue)
      ..addColumns([count])
      ..where(_db.scanQueue.state.equalsValue(state));
    return (await q.getSingle()).read(count) ?? 0;
  }

  Future<void> markUploading(String clientScanId) => _update(
    clientScanId,
    ScanQueueCompanion(
      state: const Value(ScanState.uploading),
      attempts: Value.absent(),
    ),
  );

  /// Upload accepted: delete the local files and keep the row for the log.
  Future<void> markDone(String clientScanId, {int? serverScanId}) async {
    await _deleteFilesOf(clientScanId);
    await _update(
      clientScanId,
      ScanQueueCompanion(
        state: const Value(ScanState.done),
        serverScanId: Value(serverScanId),
        lastError: const Value(null),
        nextAttemptAt: const Value(null),
      ),
    );
  }

  /// 202 pending_confirm: the submission was already published, so the
  /// teacher has to confirm the replacement (DESIGN §9.4).
  Future<void> markConflict(String clientScanId, {required int serverScanId}) =>
      _update(
        clientScanId,
        ScanQueueCompanion(
          state: const Value(ScanState.conflict),
          serverScanId: Value(serverScanId),
          lastError: const Value(null),
          nextAttemptAt: const Value(null),
        ),
      );

  /// 422: the server rejected the scan for good (qr_invalid, ...).
  Future<void> markFailed(String clientScanId, {required String reason}) =>
      _update(
        clientScanId,
        ScanQueueCompanion(
          state: const Value(ScanState.failed),
          lastError: Value(reason),
          nextAttemptAt: const Value(null),
        ),
      );

  /// Transient error: back to pending with a later retry time.
  Future<void> scheduleRetry(
    String clientScanId, {
    required String error,
    required DateTime nextAttemptAt,
  }) async {
    final current = await find(clientScanId);
    await _update(
      clientScanId,
      ScanQueueCompanion(
        state: const Value(ScanState.pending),
        attempts: Value((current?.attempts ?? 0) + 1),
        lastError: Value(error),
        nextAttemptAt: Value(nextAttemptAt),
      ),
    );
  }

  /// Manual "retry now" from the queue screen, or a scan that was waiting
  /// for a layout and can now be processed.
  Future<void> resetToPending(String clientScanId) => _update(
    clientScanId,
    const ScanQueueCompanion(
      state: Value(ScanState.pending),
      attempts: Value(0),
      lastError: Value(null),
      nextAttemptAt: Value(null),
    ),
  );

  Future<void> setState(String clientScanId, ScanState state) =>
      _update(clientScanId, ScanQueueCompanion(state: Value(state)));

  /// Removes the row and its files (user discards a failed/conflict scan).
  Future<void> remove(String clientScanId) async {
    await _deleteFilesOf(clientScanId);
    await (_db.delete(
      _db.scanQueue,
    )..where((t) => t.clientScanId.equals(clientScanId))).go();
  }

  Future<void> clearDone() => (_db.delete(
    _db.scanQueue,
  )..where((t) => t.state.equalsValue(ScanState.done))).go();

  Future<void> _update(String clientScanId, ScanQueueCompanion values) =>
      (_db.update(_db.scanQueue)
            ..where((t) => t.clientScanId.equals(clientScanId)))
          .write(values.copyWith(updatedAt: Value(_clock())));

  Future<void> _deleteFilesOf(String clientScanId) async {
    final scan = await find(clientScanId);
    if (scan == null) return;
    for (final path in scan.files.values) {
      final file = File(path);
      try {
        if (await file.exists()) await file.delete();
      } on FileSystemException {
        // Best effort; a leftover temp file is harmless.
      }
    }
  }
}

final scanQueueRepositoryProvider = Provider<ScanQueueRepository>(
  (ref) => ScanQueueRepository(ref.watch(appDatabaseProvider)),
);
