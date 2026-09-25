import 'package:drift/drift.dart' show DatabaseConnection;
import 'package:drift/native.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/core/db/database_provider.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/features/upload_queue/upload_queue_screen.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

class _RecordingScheduler implements UploadScheduler {
  final events = <String>[];

  @override
  Future<void> initialize() async => events.add('initialize');

  @override
  Future<void> requestUpload() async => events.add('request');

  @override
  Future<void> cancelPending() async => events.add('cancel');
}

void main() {
  late AppDatabase db;
  late ScanQueueRepository repo;

  setUp(() {
    // closeStreamsSynchronously: drift otherwise parks a zero-length timer in
    // the widget test's FakeAsync zone whenever a query stream is cancelled;
    // if a test fails before unmountScreen() the timer never fires and the
    // tearDown db.close() below waits for it forever (the test hangs instead
    // of failing).
    db = AppDatabase(
      DatabaseConnection(
        NativeDatabase.memory(),
        closeStreamsSynchronously: true,
      ),
    );
    repo = ScanQueueRepository(db);
  });

  tearDown(() => db.close());

  // No files on purpose: widget tests run under FakeAsync, where real
  // dart:io work (multipart from file, delete on done) would never complete.
  // The multipart body itself is covered by scan_uploader_test.dart.
  Future<void> enqueue(String id, {ScanState state = ScanState.pending}) =>
      repo.enqueue(
        clientScanId: id,
        meta: {
          'client_scan_id': id,
          'qr': 'EV1.123.4567.1.2.K7Q3M2PA',
          'scanned_at': '2026-10-01T09:15:00+07:00',
          'blur_score': 100.0,
          'regions': [],
        },
        files: const {},
        state: state,
      );

  testWidgets('"ลองใหม่" on a rejected scan re-uploads it', (tester) async {
    await enqueue('bad');
    await repo.markFailed('bad', reason: 'QR ไม่ถูกต้อง (qr_invalid)');
    final scheduler = _RecordingScheduler();
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {'scan_id': 77, 'state': 'active'}),
    );

    await pumpScreen(
      tester,
      const UploadQueueScreen(),
      overrides: [
        appDatabaseProvider.overrideWithValue(db),
        dioProvider.overrideWithValue(fakeDio(adapter)),
        uploadSchedulerProvider.overrideWithValue(scheduler),
      ],
    );
    expect(find.text('ถูกปฏิเสธ'), findsOneWidget);
    expect(find.text('QR ไม่ถูกต้อง (qr_invalid)'), findsOneWidget);
    expect(find.text('การบ้าน #123 นักเรียน #4567 หน้า 1'), findsOneWidget);

    await tester.tap(find.text('ลองใหม่'));
    await tester.pumpAndSettle();

    expect(adapter.requests.single.uri.path, '/api/v1/scans');
    expect((await repo.find('bad'))!.state, ScanState.done);
    expect(find.text('ส่งแล้ว'), findsOneWidget);
    expect(
      scheduler.events,
      ['cancel'],
      reason:
          'WorkManager task cancelled before the foreground drain; '
          'nothing pending afterwards so no new request',
    );
    await unmountScreen(tester);
  });

  testWidgets(
    '"อัปโหลดตอนนี้" hands scans that still back off to WorkManager',
    (tester) async {
      await enqueue('later');
      await repo.scheduleRetry(
        'later',
        error: 'maintenance',
        nextAttemptAt: DateTime.now().add(const Duration(minutes: 5)),
      );
      final scheduler = _RecordingScheduler();
      final adapter = FakeHttpAdapter((_) async => jsonResponse(201, {}));

      await pumpScreen(
        tester,
        const UploadQueueScreen(),
        overrides: [
          appDatabaseProvider.overrideWithValue(db),
          dioProvider.overrideWithValue(fakeDio(adapter)),
          uploadSchedulerProvider.overrideWithValue(scheduler),
        ],
      );
      expect(find.text('รออัปโหลด'), findsOneWidget);
      expect(find.textContaining('ลองแล้ว 1 ครั้ง'), findsOneWidget);

      await tester.tap(find.text('อัปโหลดตอนนี้'));
      await tester.pumpAndSettle();

      expect(adapter.requests, isEmpty, reason: 'backoff not elapsed');
      expect(scheduler.events, ['cancel', 'request']);
      expect((await repo.find('later'))!.state, ScanState.pending);
      await unmountScreen(tester);
    },
  );

  testWidgets('a conflict offers "ยืนยันแทนที่" and finishes on success', (
    tester,
  ) async {
    await enqueue('c');
    await repo.markConflict('c', serverScanId: 42);
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {'scan_id': 42, 'state': 'active'}),
    );
    await pumpScreen(
      tester,
      const UploadQueueScreen(),
      overrides: [
        appDatabaseProvider.overrideWithValue(db),
        dioProvider.overrideWithValue(fakeDio(adapter)),
        uploadSchedulerProvider.overrideWithValue(_RecordingScheduler()),
      ],
    );
    expect(find.text('รอครูยืนยัน'), findsOneWidget);
    await tester.tap(find.text('ยืนยันแทนที่'));
    await tester.pumpAndSettle();
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/scans/42/confirm-replace',
    );
    expect((await repo.find('c'))!.state, ScanState.done);
    expect(find.text('ส่งแล้ว'), findsOneWidget);
    await unmountScreen(tester);
  });
}
