import 'dart:io';

import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_scan_repository.dart';
import 'package:eduvision/features/exams/exam_scan_screen.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/features/upload_queue/scan_uploader.dart';
import 'package:eduvision/platform/answer_sheet_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'exam_scan_fakes.dart';

/// DESIGN §22.15 build 3: scan kit, sheet status, key-sheet read; the kit
/// cache; exam pages going up as POST /exam-sheets; drift schema 2.
void main() {
  group('ApiExamScanRepository', () {
    late FakeHttpAdapter adapter;
    late ApiExamScanRepository repo;
    Object? body;

    setUp(() {
      adapter = FakeHttpAdapter((_) async => jsonResponse(200, body));
      repo = ApiExamScanRepository(fakeDio(adapter));
    });

    test('scanKit reads GET /exams/{id}/scan-kit', () async {
      body = {'data': kitJson()};
      final kit = await repo.scanKit(examId);
      expect(adapter.requests.last.uri.path, '/api/v1/exams/301/scan-kit');
      expect(kit.layoutVersion, 1);
      expect(kit.versions[2]!.key[4]!.acceptedValues, ['12']);
      expect(kit.versions[2]!.key[1]!.type, ExamSectionType.mcq);
      expect(kit.roster, hasLength(3));
    });

    test('sheetStatus reads GET /exams/{id}/sheet-status', () async {
      body = {
        'data': [
          {
            'student_id': 11,
            'student_number': 1,
            'name': 'หนึ่ง',
            'pages_received': [1],
            'page_count': 2,
            'version_no': 2,
            'score': 7.5,
            'status': 'needs_review',
            'doubt_count': 1,
            'needs_version': false,
          },
        ],
        'summary': {
          'scanned': 0,
          'total': 1,
          'missing_numbers': [1],
          'max_score': 20,
        },
      };
      final status = await repo.sheetStatus(examId);
      expect(adapter.requests.last.uri.path, '/api/v1/exams/301/sheet-status');
      final s = status.of(11)!;
      expect(
        [
          s.pagesReceived,
          s.pageCount,
          s.versionNo,
          s.score,
          s.status,
          s.doubtCount,
        ],
        [
          [1],
          2,
          2,
          7.5,
          'needs_review',
          1,
        ],
      );
      expect(status.maxScore, 20);
      expect(status.of(99), isNull);
    });

    test('keySheetRead POSTs the reading and parses the proposal', () async {
      body = {
        'data': {
          'version_no': 2,
          'page': 1,
          'proposal': [
            {
              'question_id': 501,
              'sheet_no': 2,
              'type': 'mcq',
              'accepted_options': [1, 3],
              'doubtful': false,
              'differs': true,
            },
            {
              'question_id': 504,
              'sheet_no': 4,
              'type': 'numeric',
              'accepted_values': ['12'],
              'doubtful': false,
              'differs': false,
            },
            {
              'question_id': 505,
              'sheet_no': 5,
              'type': 'true_false',
              'accepted_options': [],
              'doubtful': true,
              'differs': true,
            },
          ],
        },
      };
      final proposal = await repo.keySheetRead(
        examId,
        qr: sheetQr(student: 0),
        reading: AnswerSheetReading.fromJson(
          readingJson('/p.webp', version: 2),
        ),
        versionNo: 2,
      );
      final req = adapter.requests.last;
      expect(req.method, 'POST');
      expect(req.uri.path, '/api/v1/exams/301/key-sheet-read');
      final data = req.data as Map;
      expect(data['qr'], sheetQr(student: 0));
      expect(data['version_no'], 2);
      expect((data['rows'] as Map).keys, ['1', '2', '3']);
      expect(proposal.versionNo, 2);
      expect(proposal.items[0].key!.options, [1, 3]);
      expect(proposal.items[1].key!.values, ['12']);
      expect(proposal.items[2].key, isNull);
      expect(proposal.items[2].doubtful, isTrue);
    });
  });

  group('ExamKitCache', () {
    late AppDatabase db;
    var now = DateTime.utc(2026, 10, 1);

    setUp(() => db = AppDatabase(NativeDatabase.memory()));
    tearDown(() => db.close());

    test('saves, loads, and forgets kits after 30 days', () async {
      final cache = ExamKitCache(db, clock: () => now);
      expect(await cache.load(examId), isNull);
      await cache.save(sampleKit());
      final loaded = (await cache.load(examId))!;
      expect(loaded.kit.kitHash, sampleKit().kitHash);
      expect(loaded.fetchedAt, now);

      now = now.add(const Duration(days: 31));
      expect(await cache.load(examId), isNull);
    });

    test('a broken row is dropped', () async {
      final cache = ExamKitCache(db, clock: () => now);
      await db
          .into(db.cachedExamKits)
          .insert(
            CachedExamKitsCompanion.insert(
              assignmentId: const Value(examId),
              kitHash: 'x',
              json: 'not json',
              fetchedAt: now,
            ),
          );
      expect(await cache.load(examId), isNull);
      expect(await db.select(db.cachedExamKits).get(), isEmpty);
    });
  });

  group('loadExamKit', () {
    late FakeExamScanRepository repo;
    late FakeKitCache cache;

    setUp(() {
      repo = FakeExamScanRepository();
      cache = FakeKitCache();
    });

    test('online: fetches and caches', () async {
      final load = await loadExamKit(repo, cache, examId);
      expect(load.kit!.assignmentId, examId);
      expect(load.offline, isFalse);
      expect(cache.kits, contains(examId));
    });

    test('offline: the prepared copy', () async {
      await cache.save(sampleKit());
      repo.kitError = offlineError();
      final load = await loadExamKit(repo, cache, examId);
      expect(load.offline, isTrue);
      expect(load.kit, isNotNull);
      expect(load.fetchedAt, DateTime.utc(2026, 10, 1, 1));
    });

    test('offline without a copy says to prepare online first', () async {
      repo.kitError = offlineError();
      final load = await loadExamKit(repo, cache, examId);
      expect(load.kit, isNull);
      expect(load.error, contains('ต่ออินเทอร์เน็ตครั้งแรก'));
    });

    test('a refusal drops the cached key', () async {
      await cache.save(sampleKit());
      repo.kitError = apiError(
        409,
        'answer_key_not_approved',
        'ต้องอนุมัติเฉลยก่อนเตรียมสแกน',
      );
      final load = await loadExamKit(repo, cache, examId);
      expect(load.kit, isNull);
      expect(load.error, 'ต้องอนุมัติเฉลยก่อนเตรียมสแกน');
      expect(cache.removed, [examId]);
    });
  });

  group('upload', () {
    late AppDatabase db;
    late Directory tmp;

    setUp(() async {
      db = AppDatabase(NativeDatabase.memory());
      tmp = await Directory.systemTemp.createTemp('exam_upload_test');
    });

    tearDown(() async {
      await db.close();
      await tmp.delete(recursive: true);
    });

    test('exam sheets go to POST /exam-sheets, worksheets to /scans', () async {
      final queue = ScanQueueRepository(db);
      final page = File('${tmp.path}/page.webp')..writeAsBytesSync([1]);
      await queue.enqueue(
        clientScanId: 'exam',
        meta: {'client_scan_id': 'exam', 'qr': sheetQr()},
        files: {'page': page.path},
        kind: ScanKind.examSheet,
      );
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(201, {
          'scan_id': 9,
          'submission_id': 3,
          'state': 'active',
          'score': 4,
        }),
      );
      final uploader = ScanUploader(dio: fakeDio(adapter), repository: queue);
      final result = await uploader.drain();
      expect(result.done, 1);
      expect(adapter.requests.single.uri.path, '/api/v1/exam-sheets');
      expect((await queue.find('exam'))!.serverScanId, 9);
      expect(uploadPathFor(ScanKind.worksheet), '/scans');
    });
  });

  test(
    'schema 1 databases gain scan_queue.kind and cached_exam_kits',
    () async {
      const v1 = '''
      CREATE TABLE scan_queue (
        client_scan_id TEXT NOT NULL PRIMARY KEY, state TEXT NOT NULL,
        meta_json TEXT NOT NULL, files_json TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT,
        server_scan_id INTEGER, next_attempt_at TEXT,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
      CREATE TABLE cached_layouts (assignment_id INTEGER NOT NULL, version INTEGER NOT NULL,
        page INTEGER NOT NULL, json TEXT NOT NULL, cached_at TEXT NOT NULL,
        PRIMARY KEY (assignment_id, version, page));
      CREATE TABLE cached_rosters (classroom_id INTEGER NOT NULL, student_id INTEGER NOT NULL,
        student_number INTEGER NOT NULL, name TEXT NOT NULL, PRIMARY KEY (classroom_id, student_id));
      CREATE TABLE model_cache (name TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL,
        sha256 TEXT NOT NULL, path TEXT NOT NULL, downloaded_at TEXT NOT NULL);
      INSERT INTO scan_queue VALUES ('old', 'pending', '{}', '{}', 0, NULL, NULL, NULL,
        '2026-09-01T00:00:00.000Z', '2026-09-01T00:00:00.000Z');
      PRAGMA user_version = 1;
    ''';
      final db = AppDatabase(
        NativeDatabase.memory(setup: (raw) => raw.execute(v1)),
      );
      final rows = await db.select(db.scanQueue).get();
      expect(rows.single.kind, ScanKind.worksheet);
      await ExamKitCache(db).save(sampleKit());
      expect(await db.select(db.cachedExamKits).get(), hasLength(1));
      await db.close();
    },
  );
}
