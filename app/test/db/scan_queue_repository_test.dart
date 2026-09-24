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

  test('conflict, failed, reset and remove transitions', () async {
    final page = await crop('page');
    await repo.enqueue(
      clientScanId: 'c',
      meta: meta('c'),
      files: {'page': page.path},
    );
    await repo.markConflict('c', serverScanId: 5);
    var scan = (await repo.find('c'))!;
    expect(scan.state, ScanState.conflict);
    expect(scan.serverScanId, 5);
    expect(await page.exists(), isTrue, reason: 'files stay until confirmed');

    await repo.markFailed('c', reason: 'qr_invalid');
    scan = (await repo.find('c'))!;
    expect(scan.state, ScanState.failed);
    expect(scan.lastError, 'qr_invalid');

    await repo.resetToPending('c');
    scan = (await repo.find('c'))!;
    expect(scan.state, ScanState.pending);
    expect(scan.attempts, 0);
    expect(scan.lastError, isNull);

    await repo.remove('c');
    expect(await repo.find('c'), isNull);
    expect(await page.exists(), isFalse);
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
