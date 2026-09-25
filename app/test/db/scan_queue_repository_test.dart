import 'dart:io';

import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late AppDatabase db;
  late ScanQueueRepository repo;
  late Directory tmp;
  var now = DateTime.utc(2026, 10, 1, 9, 0);

  setUp(() async {
    db = AppDatabase(NativeDatabase.memory());
    repo = ScanQueueRepository(db, clock: () => now);
    tmp = await Directory.systemTemp.createTemp('scan_queue_test');
  });

  tearDown(() async {
    await db.close();
    await tmp.delete(recursive: true);
  });

  Future<File> crop(String name) async {
    final f = File('${tmp.path}/$name.webp');
    await f.writeAsBytes([1, 2, 3]);
    return f;
  }

  Map<String, dynamic> meta(String id) => {
    'client_scan_id': id,
    'qr': 'EV1.123.4567.1.2.K7Q3M2PA',
    'scanned_at': '2026-10-01T09:15:00+07:00',
    'blur_score': 182.4,
    'regions': [],
  };

  test('enqueue stores meta and files and parses the QR', () async {
    final page = await crop('page');
    await repo.enqueue(
      clientScanId: 'a',
      meta: meta('a'),
      files: {'page': page.path},
    );

    final scan = (await repo.find('a'))!;
    expect(scan.state, ScanState.pending);
    expect(scan.meta['qr'], 'EV1.123.4567.1.2.K7Q3M2PA');
    expect(scan.files, {'page': page.path});
    expect(scan.attempts, 0);
    expect(scan.qr?.assignmentId, 123);
    expect(scan.qr?.studentId, 4567);
    expect(scan.qr?.page, 1);
    expect(scan.qr?.layoutVersion, 2);
    expect(scan.createdAt, now);
  });

  test('dueForUpload honours state and backoff time', () async {
    await repo.enqueue(clientScanId: 'p1', meta: meta('p1'), files: {});
    await repo.enqueue(
      clientScanId: 'nl',
      meta: meta('nl'),
      files: {},
      state: ScanState.needsLayout,
    );
    await repo.enqueue(clientScanId: 'later', meta: meta('later'), files: {});
    await repo.scheduleRetry(
      'later',
      error: 'boom',
      nextAttemptAt: now.add(const Duration(minutes: 5)),
    );

    final due = await repo.dueForUpload(now: now);
    expect(due.map((s) => s.clientScanId), ['p1']);

    final laterDue = await repo.dueForUpload(
      now: now.add(const Duration(minutes: 6)),
    );
    expect(laterDue.map((s) => s.clientScanId), ['p1', 'later']);

    final later = (await repo.find('later'))!;
    expect(later.attempts, 1);
    expect(later.lastError, 'boom');
    expect(later.state, ScanState.pending);
  });

  test('markDone deletes local files and keeps the row', () async {
    final page = await crop('page');
    final q1 = await crop('q1');
    await repo.enqueue(
      clientScanId: 'd',
      meta: meta('d'),
      files: {'page': page.path, 'crop_q1': q1.path},
    );
    await repo.markUploading('d');
    expect((await repo.find('d'))!.state, ScanState.uploading);

    await repo.markDone('d', serverScanId: 99);

    final scan = (await repo.find('d'))!;
    expect(scan.state, ScanState.done);
    expect(scan.serverScanId, 99);
    expect(await page.exists(), isFalse);
    expect(await q1.exists(), isFalse);
    expect(await repo.countByState(ScanState.done), 1);
  });

  test('failed, reset, conflict and remove transitions', () async {
    final page = await crop('page');
    await repo.enqueue(
      clientScanId: 'c',
      meta: meta('c'),
      files: {'page': page.path},
    );
    await repo.markFailed('c', reason: 'qr_invalid');
    var scan = (await repo.find('c'))!;
    expect(scan.state, ScanState.failed);
    expect(scan.lastError, 'qr_invalid');

    await repo.resetToPending('c');
    scan = (await repo.find('c'))!;
    expect(scan.state, ScanState.pending);
    expect(scan.attempts, 0);
    expect(scan.lastError, isNull);

    await repo.markConflict('c', serverScanId: 5);
    scan = (await repo.find('c'))!;
    expect(scan.state, ScanState.conflict);
    expect(scan.serverScanId, 5);
    expect(await page.exists(), isTrue, reason: 'files stay until confirmed');

    await repo.remove('c');
    expect(await repo.find('c'), isNull);
    expect(await page.exists(), isFalse);
  });

  group('done and conflict are sticky', () {
    test(
      'markDone then scheduleRetry / markFailed leaves the row done',
      () async {
        final page = await crop('page');
        await repo.enqueue(
          clientScanId: 's',
          meta: meta('s'),
          files: {'page': page.path},
        );
        await repo.markDone('s', serverScanId: 7);

        await repo.scheduleRetry(
          's',
          error: 'stream closed',
          nextAttemptAt: now.add(const Duration(seconds: 30)),
        );
        var scan = (await repo.find('s'))!;
        expect(scan.state, ScanState.done);
        expect(scan.attempts, 0);
        expect(scan.lastError, isNull);
        expect(scan.serverScanId, 7);

        await repo.markFailed('s', reason: 'ไฟล์ภาพหายไปจากเครื่อง');
        scan = (await repo.find('s'))!;
        expect(scan.state, ScanState.done);
        expect(scan.lastError, isNull);

        expect(await repo.markUploading('s'), isFalse);
        expect((await repo.find('s'))!.state, ScanState.done);

        await repo.markConflict('s', serverScanId: 8);
        scan = (await repo.find('s'))!;
        expect(scan.state, ScanState.done);
        expect(scan.serverScanId, 7);

        await repo.resetToPending('s');
        expect((await repo.find('s'))!.state, ScanState.done);
        expect(
          await repo.dueForUpload(now: now.add(const Duration(days: 1))),
          isEmpty,
        );
      },
    );

    test(
      'conflict ignores retry/failed but confirm-replace can finish it',
      () async {
        await repo.enqueue(clientScanId: 'k', meta: meta('k'), files: {});
        await repo.markConflict('k', serverScanId: 42);

        await repo.scheduleRetry('k', error: 'x', nextAttemptAt: now);
        expect((await repo.find('k'))!.state, ScanState.conflict);
        await repo.markFailed('k', reason: 'x');
        expect((await repo.find('k'))!.state, ScanState.conflict);
        expect(await repo.markUploading('k'), isFalse);

        await repo.markDone('k', serverScanId: 42);
        expect((await repo.find('k'))!.state, ScanState.done);
      },
    );

    test('markUploading claims a pending row exactly once per drain', () async {
      await repo.enqueue(clientScanId: 'u', meta: meta('u'), files: {});
      expect(await repo.markUploading('u'), isTrue);
      expect((await repo.find('u'))!.state, ScanState.uploading);
      expect(await repo.markUploading('missing'), isFalse);
    });
  });

  group('markUploading is a conditional claim', () {
    test('only a pending or a stale uploading row can be claimed', () async {
      await repo.enqueue(clientScanId: 'f', meta: meta('f'), files: {});
      await repo.markFailed('f', reason: 'qr_invalid');
      await repo.enqueue(
        clientScanId: 'nl',
        meta: meta('nl'),
        files: {},
        state: ScanState.needsLayout,
      );
      expect(await repo.markUploading('f'), isFalse);
      expect((await repo.find('f'))!.state, ScanState.failed);
      expect(await repo.markUploading('nl'), isFalse);
      expect((await repo.find('nl'))!.state, ScanState.needsLayout);
    });

    test('two isolates listing the same row: only one may send it', () async {
      await repo.enqueue(clientScanId: 'twice', meta: meta('twice'), files: {});
      final listedByA = await repo.dueForUpload(now: now);
      final listedByB = await repo.dueForUpload(now: now);
      expect(listedByA.single.clientScanId, 'twice');
      expect(listedByB.single.clientScanId, 'twice');

      expect(await repo.markUploading('twice'), isTrue, reason: 'A claims');
      expect(await repo.markUploading('twice'), isFalse, reason: 'B skips');
    });

    test('a stale uploading row (dead isolate) can be claimed again', () async {
      await repo.enqueue(clientScanId: 'dead', meta: meta('dead'), files: {});
      expect(await repo.markUploading('dead'), isTrue);
      expect(
        await repo.markUploading(
          'dead',
          now: now.add(const Duration(minutes: 9)),
        ),
        isFalse,
      );
      expect(
        await repo.markUploading('dead', now: now.add(staleUploadingAfter)),
        isTrue,
      );
    });
  });

  test('countAwaitingUpload counts pending and uploading rows', () async {
    for (final id in ['p', 'u', 'd', 'f', 'k', 'nl']) {
      await repo.enqueue(clientScanId: id, meta: meta(id), files: {});
    }
    await repo.markUploading('u');
    await repo.markDone('d');
    await repo.markFailed('f', reason: 'x');
    await repo.markConflict('k', serverScanId: 1);
    await repo.setState('nl', ScanState.needsLayout);

    expect(await repo.countAwaitingUpload(), 2);
    expect(await repo.countUnsent(), 5, reason: 'everything but done');
  });

  test('markConflict without a server id explains why', () async {
    await repo.enqueue(clientScanId: 'k', meta: meta('k'), files: {});
    await repo.markConflict('k');
    final scan = (await repo.find('k'))!;
    expect(scan.state, ScanState.conflict);
    expect(scan.serverScanId, isNull);
    expect(scan.lastError, ScanQueueRepository.missingIdMessage);

    // "ลองใหม่" asks the server again; a later answer with the id clears it.
    await repo.resetToPending('k');
    await repo.markConflict('k', serverScanId: 12);
    final again = (await repo.find('k'))!;
    expect(again.serverScanId, 12);
    expect(again.lastError, isNull);
  });

  test('removeAll deletes every row and its files', () async {
    final a = await crop('a');
    final b = await crop('b');
    await repo.enqueue(
      clientScanId: 'a',
      meta: meta('a'),
      files: {'page': a.path},
    );
    await repo.enqueue(
      clientScanId: 'b',
      meta: meta('b'),
      files: {'page': b.path},
    );
    await repo.markConflict('b', serverScanId: 3);

    await repo.removeAll();
    expect(await repo.listAll(), isEmpty);
    expect(await a.exists(), isFalse);
    expect(await b.exists(), isFalse);
  });

  test('a live uploading row is not re-picked until it is stale', () async {
    await repo.enqueue(clientScanId: 'live', meta: meta('live'), files: {});
    await repo.markUploading('live');

    expect(await repo.dueForUpload(now: now), isEmpty);
    expect(
      await repo.dueForUpload(now: now.add(const Duration(minutes: 9))),
      isEmpty,
      reason: 'younger than staleUploadingAfter: another isolate owns it',
    );
    final stale = await repo.dueForUpload(now: now.add(staleUploadingAfter));
    expect(stale.map((s) => s.clientScanId), ['live']);
  });

  test('earliestPendingRetry reports the next backoff time', () async {
    expect(await repo.earliestPendingRetry(now: now), isNull);
    await repo.enqueue(clientScanId: 'a', meta: meta('a'), files: {});
    await repo.enqueue(clientScanId: 'b', meta: meta('b'), files: {});
    await repo.scheduleRetry(
      'a',
      error: 'x',
      nextAttemptAt: now.add(const Duration(minutes: 5)),
    );
    await repo.scheduleRetry(
      'b',
      error: 'x',
      nextAttemptAt: now.add(const Duration(minutes: 2)),
    );
    expect(
      await repo.earliestPendingRetry(now: now),
      now.add(const Duration(minutes: 2)),
    );
    expect(
      await repo.earliestPendingRetry(now: now.add(const Duration(minutes: 3))),
      now.add(const Duration(minutes: 5)),
    );
  });

  test(
    'watchAll emits on change and clearDone removes only done rows',
    () async {
      await repo.enqueue(clientScanId: 'x', meta: meta('x'), files: {});
      await repo.enqueue(clientScanId: 'y', meta: meta('y'), files: {});
      await repo.markDone('x');

      expect((await repo.watchAll().first).length, 2);
      await repo.clearDone();
      final rest = await repo.listAll();
      expect(rest.map((s) => s.clientScanId), ['y']);
    },
  );
}
