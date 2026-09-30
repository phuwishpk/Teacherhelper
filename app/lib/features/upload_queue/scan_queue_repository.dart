import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/db/app_database.dart';
import '../../core/db/database_provider.dart';
import 'queued_scan.dart';

/// A scan left in `uploading` longer than this is assumed to belong to a
/// dead isolate (app killed mid-upload) and is picked up again. Anything
/// younger is probably a live upload in the other isolate (foreground drain
/// vs. WorkManager) and must not be sent twice.
const staleUploadingAfter = Duration(minutes: 10);

/// Local persistence for the upload queue (DESIGN §6.4 `scan_queue`).
/// All state transitions of a queued scan go through here so the uploader,
/// the scan screen and the queue screen agree on the rules.
///
/// `done` and `conflict` are sticky: once the server has accepted a scan a
/// late failure from a concurrent upload of the same row (the other isolate
/// losing its multipart stream because the files were just deleted) must
/// not turn it back into `pending` or `failed`.
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
    ScanKind kind = ScanKind.worksheet,
  }) async {
    final now = _clock();
    await _db
        .into(_db.scanQueue)
        .insertOnConflictUpdate(
          ScanQueueCompanion.insert(
            clientScanId: clientScanId,
            state: state,
            kind: Value(kind),
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
  /// in `uploading` for longer than [staleUploadingAfter] (app killed
  /// mid-upload) is picked up again too; a younger one is left alone.
  Future<List<QueuedScan>> dueForUpload({DateTime? now}) async {
    final at = now ?? _clock();
    final staleBefore = at.subtract(staleUploadingAfter);
    final rows =
        await (_db.select(_db.scanQueue)
              ..where(
                (t) =>
                    (t.state.equalsValue(ScanState.pending) &
                        (t.nextAttemptAt.isNull() |
                            t.nextAttemptAt.isSmallerOrEqualValue(at))) |
                    (t.state.equalsValue(ScanState.uploading) &
                        t.updatedAt.isSmallerOrEqualValue(staleBefore)),
              )
              ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
            .get();
    return rows.map(QueuedScan.fromRow).toList();
  }

  /// Earliest `next_attempt_at` among pending scans that are not due yet at
  /// [now]; null when nothing is waiting on its backoff.
  Future<DateTime?> earliestPendingRetry({DateTime? now}) async {
    final at = now ?? _clock();
    final row =
        await (_db.select(_db.scanQueue)
              ..where(
                (t) =>
                    t.state.equalsValue(ScanState.pending) &
                    t.nextAttemptAt.isBiggerThanValue(at),
              )
              ..orderBy([(t) => OrderingTerm.asc(t.nextAttemptAt)])
              ..limit(1))
            .getSingleOrNull();
    return row?.nextAttemptAt;
  }

  Future<int> countByState(ScanState state) =>
      _countWhere(_db.scanQueue.state.equalsValue(state));

  /// Scans a background upload still has to deal with: `pending` ones and
  /// `uploading` ones. An `uploading` row may belong to an isolate that was
  /// killed mid-upload; it only becomes due after [staleUploadingAfter], so
  /// the WorkManager task has to stay scheduled until then or nothing would
  /// ever pick it up again.
  Future<int> countAwaitingUpload() => _countWhere(
    _db.scanQueue.state.isInValues([ScanState.pending, ScanState.uploading]),
  );

  /// Scans the server does not have yet: every row except `done`. Signing
  /// out discards these, so the UI asks first.
  Future<int> countUnsent() =>
      _countWhere(_db.scanQueue.state.equalsValue(ScanState.done).not());

  Future<int> _countWhere(Expression<bool> predicate) async {
    final count = _db.scanQueue.clientScanId.count();
    final q = _db.selectOnly(_db.scanQueue)
      ..addColumns([count])
      ..where(predicate);
    return (await q.getSingle()).read(count) ?? 0;
  }

  /// Claims the row for an upload with a conditional UPDATE, so of two
  /// isolates that both listed the same row only one gets to send it. Only a
  /// `pending` row, or an `uploading` row older than [staleUploadingAfter]
  /// (its isolate died), can be claimed. Returns false otherwise (row gone,
  /// settled, failed, waiting for a layout, or a live upload elsewhere), in
  /// which case the caller must not send it.
  Future<bool> markUploading(String clientScanId, {DateTime? now}) async {
    final at = now ?? _clock();
    final staleBefore = at.subtract(staleUploadingAfter);
    final changed =
        await (_db.update(_db.scanQueue)..where(
              (t) =>
                  t.clientScanId.equals(clientScanId) &
                  (t.state.equalsValue(ScanState.pending) |
                      (t.state.equalsValue(ScanState.uploading) &
                          t.updatedAt.isSmallerOrEqualValue(staleBefore))),
            ))
            .write(
              ScanQueueCompanion(
                state: const Value(ScanState.uploading),
                updatedAt: Value(at),
              ),
            );
    return changed > 0;
  }

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

  /// `pending_confirm`: the submission was already published, so the
  /// teacher has to confirm the replacement (DESIGN §9.4). The server holds
  /// the scan, so the local files are kept until the teacher decides.
  ///
  /// Confirm-replace needs the server's scan id. When the response did not
  /// carry one the row still becomes `conflict` (it must never be marked
  /// done or re-sent automatically) and [missingIdMessage] explains why the
  /// confirm button is not offered. Ignored once the row is `done`.
  Future<void> markConflict(String clientScanId, {int? serverScanId}) async {
    await (_db.update(_db.scanQueue)..where(
          (t) =>
              t.clientScanId.equals(clientScanId) &
              t.state.equalsValue(ScanState.done).not(),
        ))
        .write(
          ScanQueueCompanion(
            state: const Value(ScanState.conflict),
            serverScanId: Value(serverScanId),
            lastError: Value(serverScanId == null ? missingIdMessage : null),
            nextAttemptAt: const Value(null),
            updatedAt: Value(_clock()),
          ),
        );
  }

  /// Shown on a `conflict` row whose response had no `scan_id`.
  static const missingIdMessage =
      'เซิร์ฟเวอร์ไม่ได้ส่งเลขสแกนกลับมา จึงยืนยันแทนที่จากเครื่องนี้ไม่ได้ '
      'กด "ลองใหม่" เพื่อถามเซิร์ฟเวอร์อีกครั้ง';

  /// The server rejected the scan for good (422 qr_invalid, 403, 404,
  /// 413, ...). Ignored once the row is `done` or `conflict`.
  Future<void> markFailed(String clientScanId, {required String reason}) =>
      _updateUnlessSettled(
        clientScanId,
        ScanQueueCompanion(
          state: const Value(ScanState.failed),
          lastError: Value(reason),
          nextAttemptAt: const Value(null),
        ),
      );

  /// Transient error: back to pending with a later retry time. Ignored once
  /// the row is `done` or `conflict` (the other isolate already succeeded).
  Future<void> scheduleRetry(
    String clientScanId, {
    required String error,
    required DateTime nextAttemptAt,
  }) async {
    final current = await find(clientScanId);
    if (current == null || current.isSettled) return;
    await _updateUnlessSettled(
      clientScanId,
      ScanQueueCompanion(
        state: const Value(ScanState.pending),
        attempts: Value(current.attempts + 1),
        lastError: Value(error),
        nextAttemptAt: Value(nextAttemptAt),
      ),
    );
  }

  /// Manual "retry now" from the queue screen, or a scan that was waiting
  /// for a layout and can now be processed. A `done` row is never re-sent.
  Future<void> resetToPending(String clientScanId) async {
    await (_db.update(_db.scanQueue)..where(
          (t) =>
              t.clientScanId.equals(clientScanId) &
              t.state.equalsValue(ScanState.done).not(),
        ))
        .write(
          ScanQueueCompanion(
            state: const Value(ScanState.pending),
            attempts: const Value(0),
            lastError: const Value(null),
            nextAttemptAt: const Value(null),
            updatedAt: Value(_clock()),
          ),
        );
  }

  /// A `needs_layout` scan was cropped now that its layout is on the device:
  /// store the real `meta` and files and queue it for upload. Returns false
  /// (and changes nothing) unless the row is still `needs_layout`.
  Future<bool> completeLayout(
    String clientScanId, {
    required Map<String, dynamic> meta,
    required Map<String, String> files,
  }) async {
    final changed =
        await (_db.update(_db.scanQueue)..where(
              (t) =>
                  t.clientScanId.equals(clientScanId) &
                  t.state.equalsValue(ScanState.needsLayout),
            ))
            .write(
              ScanQueueCompanion(
                state: const Value(ScanState.pending),
                metaJson: Value(jsonEncode(meta)),
                filesJson: Value(jsonEncode(files)),
                attempts: const Value(0),
                lastError: const Value(null),
                nextAttemptAt: const Value(null),
                updatedAt: Value(_clock()),
              ),
            );
    return changed > 0;
  }

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

  /// Deletes every row and its local image files (sign-out on a shared
  /// device: the next user must not see or upload these scans).
  Future<void> removeAll() async {
    for (final scan in await listAll()) {
      await _deleteFiles(scan);
    }
    await _db.delete(_db.scanQueue).go();
  }

  Future<void> _update(String clientScanId, ScanQueueCompanion values) =>
      (_db.update(_db.scanQueue)
            ..where((t) => t.clientScanId.equals(clientScanId)))
          .write(values.copyWith(updatedAt: Value(_clock())));

  /// Like [_update] but leaves `done` / `conflict` rows untouched. Returns
  /// the number of rows written (0 or 1).
  Future<int> _updateUnlessSettled(
    String clientScanId,
    ScanQueueCompanion values,
  ) =>
      (_db.update(_db.scanQueue)..where(
            (t) =>
                t.clientScanId.equals(clientScanId) &
                t.state.isNotInValues([ScanState.done, ScanState.conflict]),
          ))
          .write(values.copyWith(updatedAt: Value(_clock())));

  Future<void> _deleteFilesOf(String clientScanId) async {
    final scan = await find(clientScanId);
    if (scan != null) await _deleteFiles(scan);
  }

  Future<void> _deleteFiles(QueuedScan scan) async {
    final folders = <String>{};
    for (final path in scan.files.values) {
      final file = File(path);
      try {
        if (await file.exists()) await file.delete();
      } on FileSystemException {
        // Best effort; a leftover temp file is harmless.
      }
      folders.add(file.parent.path);
    }
    // Confirmed scans live in a folder named after their id
    // (ScanFileStore); drop it once it is empty.
    for (final folder in folders) {
      final dir = Directory(folder);
      if (dir.uri.pathSegments.where((s) => s.isNotEmpty).lastOrNull !=
          scan.clientScanId) {
        continue;
      }
      try {
        if (await dir.exists() && await dir.list().isEmpty) await dir.delete();
      } on FileSystemException {
        // Same as above.
      }
    }
  }
}

final scanQueueRepositoryProvider = Provider<ScanQueueRepository>(
  (ref) => ScanQueueRepository(ref.watch(appDatabaseProvider)),
);
