import 'dart:io';

import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/features/upload_queue/scan_uploader.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

void main() {
  late AppDatabase db;
  late ScanQueueRepository repo;
  late Directory tmp;
  final now = DateTime.utc(2026, 10, 1, 9, 0);

  setUp(() async {
    db = AppDatabase(NativeDatabase.memory());
    repo = ScanQueueRepository(db, clock: () => now);
    tmp = await Directory.systemTemp.createTemp('uploader_test');
  });

  tearDown(() async {
    await db.close();
    await tmp.delete(recursive: true);
  });

  Future<Map<String, String>> files(String id) async {
    final page = File('${tmp.path}/$id-page.webp')..writeAsBytesSync([1]);
    final q = File('${tmp.path}/$id-q501.webp')..writeAsBytesSync([2]);
    return {'page': page.path, 'crop_q501': q.path};
  }

  Future<void> enqueue(String id) async {
    await repo.enqueue(
      clientScanId: id,
      meta: {
        'client_scan_id': id,
        'qr': 'EV1.123.4567.1.2.K7Q3M2PA',
        'scanned_at': '2026-10-01T09:15:00+07:00',
        'blur_score': 182.4,
        'regions': [
          {'region_id': 'q501', 'question_id': 501, 'file': 'crop_q501'},
        ],
      },
      files: await files(id),
    );
  }

  ScanUploader uploader(FakeHttpAdapter adapter) =>
      ScanUploader(dio: fakeDio(adapter), repository: repo, clock: () => now);

  test('backoff doubles from 30 s and caps at one hour', () {
    expect(backoffFor(0), const Duration(seconds: 30));
    expect(backoffFor(1), const Duration(minutes: 1));
    expect(backoffFor(3), const Duration(minutes: 4));
    expect(backoffFor(10), const Duration(hours: 1));
    expect(backoffFor(50), const Duration(hours: 1));
  });

  test('201 sends multipart meta + files and marks done', () async {
    await enqueue('s1');
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'scan_id': 77,
        'submission_id': 5,
        'state': 'active',
      }),
    );
    final result = await uploader(adapter).drain();

    expect(result.done, 1);
    expect(result.settled, isTrue);
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/scans');
    final form = req.data as FormData;
    expect(form.fields.single.key, 'meta');
    expect(form.fields.single.value, contains('"client_scan_id":"s1"'));
    expect(form.files.map((f) => f.key), ['page', 'crop_q501']);

    final scan = (await repo.find('s1'))!;
    expect(scan.state, ScanState.done);
    expect(scan.serverScanId, 77);
    for (final path in scan.files.values) {
      expect(File(path).existsSync(), isFalse, reason: 'files deleted on done');
    }
  });

  test('200 (duplicate client_scan_id) also counts as done', () async {
    await enqueue('dup');
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'scan_id': 1,
        'submission_id': 2,
        'state': 'active',
      }),
    );
    expect(
      await uploader(adapter).uploadOne((await repo.find('dup'))!),
      UploadOutcome.done,
    );
  });

  test(
    '202 pending_confirm becomes conflict, confirm-replace finishes it',
    () async {
      await enqueue('c1');
      final adapter = FakeHttpAdapter((options) async {
        if (options.uri.path.endsWith('/confirm-replace')) {
          return jsonResponse(200, {'scan_id': 42, 'state': 'active'});
        }
        return jsonResponse(202, {'scan_id': 42, 'state': 'pending_confirm'});
      });
      final up = uploader(adapter);
      final result = await up.drain();
      expect(result.conflict, 1);

      var scan = (await repo.find('c1'))!;
      expect(scan.state, ScanState.conflict);
      expect(scan.serverScanId, 42);
      expect(File(scan.files['page']!).existsSync(), isTrue);

      await up.confirmReplace(scan);
      expect(
        adapter.requests.last.uri.path,
        '/api/v1/scans/42/confirm-replace',
      );
      scan = (await repo.find('c1'))!;
      expect(scan.state, ScanState.done);
      expect(File(scan.files['page']!).existsSync(), isFalse);
    },
  );

  test('422 marks failed with the reason and never retries', () async {
    await enqueue('bad');
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(422, {
        'message': 'QR ไม่ถูกต้อง',
        'errors': {},
        'code': 'qr_invalid',
      }),
    );
    final result = await uploader(adapter).drain();
    expect(result.failed, 1);
    final scan = (await repo.find('bad'))!;
    expect(scan.state, ScanState.failed);
    expect(scan.lastError, 'QR ไม่ถูกต้อง (qr_invalid)');
    expect(
      await repo.dueForUpload(now: now.add(const Duration(days: 1))),
      isEmpty,
    );
  });

  test('server error schedules a retry with exponential backoff', () async {
    await enqueue('r1');
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(503, {'message': 'maintenance'}),
    );
    final up = uploader(adapter);

    var result = await up.drain();
    expect(result.retry, 1);
    expect(result.settled, isFalse);
    var scan = (await repo.find('r1'))!;
    expect(scan.state, ScanState.pending);
    expect(scan.attempts, 1);
    expect(scan.nextAttemptAt, now.add(const Duration(seconds: 30)));
    expect(scan.lastError, 'maintenance');

    // Not due yet: drain uploads nothing.
    result = await up.drain();
    expect(result.retry + result.done, 0);
    expect(adapter.requests.length, 1);

    // Force a second attempt: delay doubles.
    await repo.scheduleRetry('r1', error: 'x', nextAttemptAt: now);
    await up.drain();
    scan = (await repo.find('r1'))!;
    expect(scan.attempts, 3);
    expect(scan.nextAttemptAt, now.add(const Duration(minutes: 2)));
  });

  test('network failure (no response) is retried too', () async {
    await enqueue('n1');
    final adapter = FakeHttpAdapter(
      (options) async => throw DioException.connectionError(
        requestOptions: options,
        reason: 'offline',
      ),
    );
    final result = await uploader(adapter).drain();
    expect(result.retry, 1);
    final scan = (await repo.find('n1'))!;
    expect(scan.state, ScanState.pending);
    expect(scan.attempts, 1);
  });

  test('missing crop file fails the scan instead of looping forever', () async {
    await enqueue('gone');
    File((await repo.find('gone'))!.files['crop_q501']!).deleteSync();
    final adapter = FakeHttpAdapter((_) async => jsonResponse(201, {}));
    final result = await uploader(adapter).drain();
    expect(result.failed, 1);
    expect(adapter.requests, isEmpty);
    expect((await repo.find('gone'))!.state, ScanState.failed);
  });
}
