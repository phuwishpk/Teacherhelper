import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/features/upload_queue/scan_uploader.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

void main() {
  late AppDatabase db;
  late ScanQueueRepository repo;
  final now = DateTime.utc(2026, 10, 1, 9, 0);

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    repo = ScanQueueRepository(db, clock: () => now);
  });

  tearDown(() => db.close());

  Map<String, dynamic> meta(String id) => {
    'client_scan_id': id,
    'qr': 'EV1.123.4567.1.2.K7Q3M2PA',
    'scanned_at': '2026-10-01T09:15:00+07:00',
    'blur_score': 182.4,
    'regions': [],
  };

  ScanUploader uploader(FakeHttpAdapter adapter) =>
      ScanUploader(dio: fakeDio(adapter), repository: repo, clock: () => now);

  test('empty queue: task is finished', () async {
    final adapter = FakeHttpAdapter((_) async => jsonResponse(201, {}));
    expect(await runBackgroundDrain(uploader(adapter), repo), isTrue);
    expect(adapter.requests, isEmpty);
  });

  test('everything settled after the drain: task is finished', () async {
    await repo.enqueue(clientScanId: 'ok', meta: meta('ok'), files: {});
    await repo.enqueue(
      clientScanId: 'nl',
      meta: meta('nl'),
      files: {},
      state: ScanState.needsLayout,
    );
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {'scan_id': 1, 'state': 'active'}),
    );
    expect(await runBackgroundDrain(uploader(adapter), repo), isTrue);
    expect((await repo.find('ok'))!.state, ScanState.done);
    expect(
      (await repo.find('nl'))!.state,
      ScanState.needsLayout,
      reason: 'needs_layout is not pending and does not keep the task alive',
    );
  });

  test('pending but not yet due: task asks WorkManager to retry', () async {
    // A foreground attempt got a 503 thirty seconds ago and scheduled the
    // next try in the future; WorkManager then ran right away.
    await repo.enqueue(clientScanId: 'wait', meta: meta('wait'), files: {});
    await repo.scheduleRetry(
      'wait',
      error: 'maintenance',
      nextAttemptAt: now.add(const Duration(seconds: 30)),
    );
    final adapter = FakeHttpAdapter((_) async => jsonResponse(201, {}));

    expect(await runBackgroundDrain(uploader(adapter), repo), isFalse);
    expect(adapter.requests, isEmpty, reason: 'nothing was due');
    expect((await repo.find('wait'))!.state, ScanState.pending);
  });

  test('a retry scheduled by this drain also asks for another run', () async {
    await repo.enqueue(clientScanId: 'r', meta: meta('r'), files: {});
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(503, {
        'message': 'maintenance',
        'errors': <String, Object>{},
        'code': 'maintenance',
      }),
    );
    expect(await runBackgroundDrain(uploader(adapter), repo), isFalse);
    expect((await repo.find('r'))!.attempts, 1);
  });

  test(
    'an upload interrupted by a killed isolate keeps the task alive',
    () async {
      // The app died while this row was `uploading`; it is not due until it is
      // stale, so WorkManager must keep coming back for it.
      await repo.enqueue(clientScanId: 'cut', meta: meta('cut'), files: {});
      await repo.markUploading('cut');
      final adapter = FakeHttpAdapter((_) async => jsonResponse(201, {}));

      expect(await runBackgroundDrain(uploader(adapter), repo), isFalse);
      expect(adapter.requests, isEmpty, reason: 'not stale yet');
    },
  );

  test('a stale interrupted upload is sent and finishes the task', () async {
    await repo.enqueue(clientScanId: 'cut', meta: meta('cut'), files: {});
    await repo.markUploading('cut', now: now.subtract(staleUploadingAfter));
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {'scan_id': 3, 'state': 'active'}),
    );

    expect(await runBackgroundDrain(uploader(adapter), repo), isTrue);
    expect(adapter.requests, hasLength(1));
    expect((await repo.find('cut'))!.state, ScanState.done);
  });

  test(
    'a 403 (another teacher\'s scan) does not keep the task alive',
    () async {
      await repo.enqueue(clientScanId: 'x', meta: meta('x'), files: {});
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(403, {'message': 'forbidden'}),
      );
      expect(await runBackgroundDrain(uploader(adapter), repo), isTrue);
      expect((await repo.find('x'))!.state, ScanState.failed);
    },
  );

  test('a rejected scan (422) does not keep the task alive', () async {
    await repo.enqueue(clientScanId: 'bad', meta: meta('bad'), files: {});
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(422, {
        'message': 'QR ไม่ถูกต้อง',
        'errors': {},
        'code': 'qr_invalid',
      }),
    );
    expect(await runBackgroundDrain(uploader(adapter), repo), isTrue);
    expect((await repo.find('bad'))!.state, ScanState.failed);
  });
}
