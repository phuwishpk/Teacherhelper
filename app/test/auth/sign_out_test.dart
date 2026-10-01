import 'dart:io';

import 'package:drift/drift.dart' show DatabaseConnection, Value;
import 'package:drift/native.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/local_user_data.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/core/db/database_provider.dart';
import 'package:eduvision/features/auth/sign_out_action.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/features/upload_queue/upload_worker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

const _teacherA = User(id: 1, name: 'ครูเอ', role: 'teacher');
const _teacherB = User(id: 2, name: 'ครูบี', role: 'teacher');

class _FakeAuth extends Fake implements AuthRepository {
  _FakeAuth(this.user);

  User user;
  int logouts = 0;

  @override
  Future<String> login({
    required String email,
    required String password,
  }) async => 'tok-${user.id}';

  @override
  Future<User> me() async => user;

  @override
  Future<void> logout() async => logouts++;
}

class _BrokenLocalData extends Fake implements LocalUserData {
  @override
  Future<void> wipe() async => throw StateError('no local database');
}

class _RecordingScheduler implements UploadScheduler {
  final events = <String>[];

  @override
  Future<void> initialize() async => events.add('initialize');

  @override
  Future<void> requestUpload() async => events.add('request');

  @override
  Future<void> cancelPending() async => events.add('cancel');
}

AppDatabase _memoryDb() => AppDatabase(
  DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true),
);

/// Seeds what teacher A leaves on the device: a queued scan with an image
/// file, a cached roster row, a cached layout page and the digit model.
Future<File?> _seed(AppDatabase db, {Directory? dir}) async {
  File? page;
  if (dir != null) {
    page = File('${dir.path}/page.webp')..writeAsBytesSync([1, 2, 3]);
  }
  await ScanQueueRepository(db).enqueue(
    clientScanId: 'scan-a',
    meta: const {'client_scan_id': 'scan-a', 'regions': []},
    files: {if (page != null) 'page': page.path},
  );
  await db
      .into(db.cachedRosters)
      .insert(
        CachedRostersCompanion.insert(
          classroomId: 7,
          studentId: 4567,
          studentNumber: 12,
          name: 'ด.ญ. สมหญิง',
        ),
      );
  await db
      .into(db.cachedLayouts)
      .insert(
        CachedLayoutsCompanion.insert(
          assignmentId: 123,
          version: 2,
          page: 1,
          json: '{}',
          cachedAt: DateTime.utc(2026, 10, 1),
        ),
      );
  await db
      .into(db.cachedExamKits)
      .insert(
        CachedExamKitsCompanion.insert(
          assignmentId: const Value(301),
          kitHash: 'h',
          json: '{}',
          fetchedAt: DateTime.utc(2026, 10, 1),
        ),
      );
  await db
      .into(db.modelCache)
      .insert(
        ModelCacheCompanion.insert(
          name: 'digit_crnn',
          version: '0.1.0',
          sha256: 'abc',
          path: '/models/digit_crnn.tflite',
          downloadedAt: DateTime.utc(2026, 10, 1),
        ),
      );
  return page;
}

Future<Map<String, int>> _counts(AppDatabase db) async => {
  'scan_queue': (await db.select(db.scanQueue).get()).length,
  'cached_rosters': (await db.select(db.cachedRosters).get()).length,
  'cached_layouts': (await db.select(db.cachedLayouts).get()).length,
  'cached_exam_kits': (await db.select(db.cachedExamKits).get()).length,
  'model_cache': (await db.select(db.modelCache).get()).length,
};

const _wiped = {
  'scan_queue': 0,
  'cached_rosters': 0,
  'cached_layouts': 0,
  'cached_exam_kits': 0,
  'model_cache': 1,
};
const _untouched = {
  'scan_queue': 1,
  'cached_rosters': 1,
  'cached_layouts': 1,
  'cached_exam_kits': 1,
  'model_cache': 1,
};

void main() {
  group('SessionNotifier and local data', () {
    late AppDatabase db;
    late Directory tmp;
    late _RecordingScheduler scheduler;

    setUp(() async {
      db = _memoryDb();
      tmp = await Directory.systemTemp.createTemp('sign_out_test');
      scheduler = _RecordingScheduler();
    });

    tearDown(() async {
      await db.close();
      await tmp.delete(recursive: true);
    });

    ProviderContainer container(_FakeAuth auth, InMemoryTokenStorage storage) {
      final c = ProviderContainer(
        overrides: [
          authRepositoryProvider.overrideWithValue(auth),
          tokenStorageProvider.overrideWithValue(storage),
          appDatabaseProvider.overrideWithValue(db),
          uploadSchedulerProvider.overrideWithValue(scheduler),
        ],
      );
      addTearDown(c.dispose);
      return c;
    }

    test('signOut deletes queue, files and offline cache', () async {
      final page = await _seed(db, dir: tmp);
      final auth = _FakeAuth(_teacherA);
      final storage = InMemoryTokenStorage(
        token: 'tok-1',
        user: _teacherA.toJson(),
        dataOwner: 1,
      );
      final c = container(auth, storage);
      await c.read(sessionProvider.notifier).restore();

      await c.read(sessionProvider.notifier).signOut();

      expect(c.read(sessionProvider), isA<SignedOut>());
      expect(auth.logouts, 1);
      expect(await _counts(db), _wiped);
      expect(await page!.exists(), isFalse, reason: 'handwriting crops gone');
      expect(scheduler.events, ['cancel'], reason: 'WorkManager task dropped');
      expect(storage.token, isNull);
      expect(storage.user, isNull);
      expect(storage.dataOwner, isNull);
    });

    test('a 401 keeps the data so the same teacher can still upload', () async {
      await _seed(db);
      final storage = InMemoryTokenStorage(token: 'tok-1', dataOwner: 1);
      final c = container(_FakeAuth(_teacherA), storage);

      await c.read(sessionProvider.notifier).forceSignOut();
      expect(await _counts(db), _untouched);
      expect(storage.dataOwner, 1);

      await c
          .read(sessionProvider.notifier)
          .signIn(email: 'a@example.com', password: 'secret');
      expect(c.read(sessionProvider), isA<SignedIn>());
      expect(await _counts(db), _untouched, reason: 'same owner signs back in');
      expect(scheduler.events, isEmpty);
    });

    test('another user signing in gets a wiped device', () async {
      await _seed(db);
      final storage = InMemoryTokenStorage(dataOwner: 1);
      final c = container(_FakeAuth(_teacherB), storage);

      await c
          .read(sessionProvider.notifier)
          .signIn(email: 'b@example.com', password: 'secret');

      expect((c.read(sessionProvider) as SignedIn).user.id, 2);
      expect(await _counts(db), _wiped);
      expect(scheduler.events, ['cancel']);
      expect(storage.dataOwner, 2);
    });

    test('a failed wipe still signs out but keeps the owner', () async {
      final storage = InMemoryTokenStorage(token: 'tok-1', dataOwner: 1);
      final c = ProviderContainer(
        overrides: [
          authRepositoryProvider.overrideWithValue(_FakeAuth(_teacherA)),
          tokenStorageProvider.overrideWithValue(storage),
          localUserDataProvider.overrideWithValue(_BrokenLocalData()),
        ],
      );
      addTearDown(c.dispose);

      await c.read(sessionProvider.notifier).signOut();
      expect(c.read(sessionProvider), isA<SignedOut>());
      expect(storage.token, isNull);
      expect(storage.dataOwner, 1, reason: 'next other user retries the wipe');
    });

    test('first sign-in on a device just records the owner', () async {
      final storage = InMemoryTokenStorage();
      final c = container(_FakeAuth(_teacherA), storage);
      await c
          .read(sessionProvider.notifier)
          .signIn(email: 'a@example.com', password: 'secret');
      expect(storage.dataOwner, 1);
      expect(scheduler.events, isEmpty);
    });
  });

  group('confirmSignOut', () {
    late AppDatabase db;
    late _RecordingScheduler scheduler;

    setUp(() {
      db = _memoryDb();
      scheduler = _RecordingScheduler();
    });

    tearDown(() => db.close());

    Future<ProviderContainer> pumpButton(
      WidgetTester tester,
      InMemoryTokenStorage storage,
    ) async {
      final container = await pumpScreen(
        tester,
        Consumer(
          builder: (context, ref, _) => Scaffold(
            body: TextButton(
              onPressed: () => confirmSignOut(context, ref),
              child: const Text('ออกจากระบบ'),
            ),
          ),
        ),
        overrides: [
          authRepositoryProvider.overrideWithValue(_FakeAuth(_teacherA)),
          tokenStorageProvider.overrideWithValue(storage),
          appDatabaseProvider.overrideWithValue(db),
          uploadSchedulerProvider.overrideWithValue(scheduler),
        ],
      );
      await tester.runAsync(
        () => container.read(sessionProvider.notifier).restore(),
      );
      await tester.pump();
      return container;
    }

    testWidgets('unsent scans: asks first, cancel keeps everything', (
      tester,
    ) async {
      await tester.runAsync(() => _seed(db));
      final storage = InMemoryTokenStorage(
        token: 'tok-1',
        user: _teacherA.toJson(),
        dataOwner: 1,
      );
      final container = await pumpButton(tester, storage);
      expect(container.read(sessionProvider), isA<SignedIn>());

      await tester.tap(find.text('ออกจากระบบ'));
      await tester.pumpAndSettle();
      expect(find.text('ยังมีสแกนที่ยังไม่ได้ส่ง 1 รายการ'), findsOneWidget);

      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(container.read(sessionProvider), isA<SignedIn>());
      expect(await tester.runAsync(() => _counts(db)), _untouched);
      await unmountScreen(tester);
    });

    testWidgets('unsent scans: confirming wipes and signs out', (tester) async {
      await tester.runAsync(() => _seed(db));
      final storage = InMemoryTokenStorage(
        token: 'tok-1',
        user: _teacherA.toJson(),
        dataOwner: 1,
      );
      final container = await pumpButton(tester, storage);

      await tester.tap(find.text('ออกจากระบบ'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ลบและออกจากระบบ'));
      await tester.runAsync(() => Future<void>.delayed(Duration.zero));
      await tester.pumpAndSettle();

      expect(container.read(sessionProvider), isA<SignedOut>());
      expect(await tester.runAsync(() => _counts(db)), _wiped);
      await unmountScreen(tester);
    });

    testWidgets('nothing unsent: signs out without a dialog', (tester) async {
      final storage = InMemoryTokenStorage(
        token: 'tok-1',
        user: _teacherA.toJson(),
        dataOwner: 1,
      );
      final container = await pumpButton(tester, storage);

      await tester.tap(find.text('ออกจากระบบ'));
      await tester.runAsync(() => Future<void>.delayed(Duration.zero));
      await tester.pumpAndSettle();

      expect(find.byType(AlertDialog), findsNothing);
      expect(container.read(sessionProvider), isA<SignedOut>());
      await unmountScreen(tester);
    });
  });
}
