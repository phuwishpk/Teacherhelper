import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/exams/exam_scan_models.dart';
import 'package:eduvision/features/exams/exam_scan_repository.dart';
import 'package:eduvision/features/exams/exam_sheet_scanner.dart';
import 'package:eduvision/features/scan/scan_file_store.dart';
import 'package:eduvision/features/upload_queue/queued_scan.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/platform/answer_sheet_pipeline.dart';
import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_scan_fakes.dart';

/// DESIGN §22.9, §22.10: photo -> checks -> bubble fill -> score on the
/// phone -> `scan_queue` (kind exam_sheet); the key sheet; the frame gate,
/// the duplicate rule and the running summary.
void main() {
  late AppDatabase db;
  late ScanQueueRepository queue;
  late Directory tmp;
  late FakeAnswerSheetPipeline pipeline;
  late ExamSheetScanner scanner;
  late int queuedCalls;
  var ids = 0;

  setUp(() async {
    db = AppDatabase(NativeDatabase.memory());
    queue = ScanQueueRepository(db);
    tmp = await Directory.systemTemp.createTemp('exam_scanner_test');
    pipeline = FakeAnswerSheetPipeline(dir: tmp);
    queuedCalls = 0;
    scanner = ExamSheetScanner(
      pipeline: pipeline,
      queue: queue,
      files: ScanFileStore(() async => Directory('${tmp.path}/store')),
      onQueued: () => queuedCalls++,
      clock: () => DateTime.utc(2026, 10, 15, 3),
      newId: () =>
          '11111111-2222-3333-4444-${(++ids).toString().padLeft(12, '0')}',
    );
  });

  tearDown(() async {
    await db.close();
    await tmp.delete(recursive: true);
  });

  Future<String> photo() async {
    final f = File('${tmp.path}/shot.jpg');
    await f.writeAsBytes([9]);
    return f.path;
  }

  group('scan', () {
    test('scores version ข on the phone and queues an exam_sheet', () async {
      pipeline.readings.add(
        (w) => readingJson(w, marks: {1: 1, 2: 2, 3: 1, 4: '12'}, version: 2),
      );
      final path = await photo();
      final outcome = await scanner.scan(
        path,
        sampleKit(),
        pageOneVersion: (_) => null,
      );

      expect(outcome, isA<ExamSheetQueued>());
      final q = outcome as ExamSheetQueued;
      expect(q.version.versionNo, 2);
      expect(q.versionLabel, 'ข');
      expect(q.student!.studentNumber, 1);
      expect(q.score!.score, 4); // s1, s2 and the number right, s3 wrong
      expect(q.score!.maxScore, 5);
      expect(q.waiting, isNull);
      expect(queuedCalls, 1);
      expect(await File(path).exists(), isFalse);
      expect(jsonDecode(pipeline.layoutsRead.single)['sheet'], 'exam');

      final row = (await queue.find(q.clientScanId))!;
      expect(row.kind, ScanKind.examSheet);
      expect(row.state, ScanState.pending);
      expect(row.meta['qr'], sheetQr());
      expect(row.meta['scanned_at'], '2026-10-15T03:00:00.000Z');
      expect(row.meta['device_score'], 4);
      expect(row.meta['version_fill'], {'1': 0.0, '2': 0.9});
      expect((row.meta['rows'] as Map)['1'], {
        '1': 0.9,
        '2': 0.0,
        '3': 0.0,
        '4': 0.0,
      });
      expect(
        ((row.meta['digits'] as Map)['4'] as Map)['columns'],
        hasLength(2),
      );
      expect(row.files.keys, ['page']);
      expect(await File(row.files['page']!).exists(), isTrue);
    });

    test('page 2 takes page 1 version; unknown waits for page 1', () async {
      final kit = sampleKit(pageCount: 2);
      pipeline.detection = detectionFor(sheetQr(page: 2));
      pipeline.readings.add((w) => readingJson(w, marks: {5: 1}, page: 2));

      final waiting =
          await scanner.scan(await photo(), kit, pageOneVersion: (_) => null)
              as ExamSheetQueued;
      expect(waiting.version.versionNo, isNull);
      expect(waiting.score, isNull);
      expect(waiting.waiting, 'รอหน้า 1');

      final known =
          await scanner.scan(
                await photo(),
                kit,
                pageOneVersion: (id) => id == 11 ? 2 : null,
              )
              as ExamSheetQueued;
      expect(known.version.source, 'page_one');
      expect(known.score!.score, 1);
      expect((await queue.listAll()).length, 2);
    });

    test('an unreadable version bubble asks the teacher', () async {
      pipeline.readings.add((w) => readingJson(w, marks: {1: 1}));
      final q =
          await scanner.scan(
                await photo(),
                sampleKit(),
                pageOneVersion: (_) => null,
              )
              as ExamSheetQueued;
      expect(q.waiting, 'ให้ครูเลือกชุด');
      expect(
        (await queue.listAll()).single.meta.containsKey('device_score'),
        isFalse,
      );
    });

    Future<String> rejected(PageDetection detection) async {
      pipeline.detection = detection;
      pipeline.readings.add((w) => readingJson(w, version: 1));
      final outcome = await scanner.scan(
        await photo(),
        sampleKit(),
        pageOneVersion: (_) => null,
      );
      expect(outcome, isA<ExamSheetRejected>());
      return (outcome as ExamSheetRejected).message;
    }

    test('refuses what is not a sheet of this exam', () async {
      expect(
        await rejected(detectionFor(sheetQr(), missing: [2])),
        contains('มุมล่างขวา'),
      );
      expect(await rejected(detectionFor(null)), contains('QR'));
      expect(
        await rejected(detectionFor('EV1.301.11.1.1.Q2M7K3PA')),
        contains('ใบงานของการบ้าน'),
      );
      expect(
        await rejected(detectionFor('hello')),
        contains('ไม่ใช่กระดาษคำตอบ'),
      );
      expect(
        await rejected(detectionFor('EVX1.999.11.1.1.Q2M7K3PA')),
        contains('ข้อสอบอื่น'),
      );
      expect(
        await rejected(detectionFor(sheetQr(student: 0))),
        contains('กระดาษเฉลย'),
      );
      expect(
        await rejected(detectionFor(sheetQr(layout: 2))),
        contains('เตรียมสแกน'),
      );
      expect(
        await rejected(detectionFor(sheetQr(student: 99))),
        contains('รายชื่อห้อง'),
      );
      expect(
        await rejected(detectionFor(sheetQr(page: 3))),
        contains('ไม่มีหน้า 3'),
      );
      expect(await queue.listAll(), isEmpty);
      expect(pipeline.layoutsRead, isEmpty);
    });

    test('a blurry photo is kept so the teacher may use it anyway', () async {
      pipeline.detection = detectionFor(sheetQr(), blur: 10);
      pipeline.readings.add((w) => readingJson(w, version: 1));
      final path = await photo();
      final r =
          await scanner.scan(path, sampleKit(), pageOneVersion: (_) => null)
              as ExamSheetRejected;
      expect(r.blurOnly, isTrue);
      expect(r.keptPhoto, path);
      expect(await File(path).exists(), isTrue);

      final again = await scanner.scan(
        path,
        sampleKit(),
        pageOneVersion: (_) => null,
        acceptBlur: true,
      );
      expect(again, isA<ExamSheetQueued>());
    });

    test('a pipeline failure is reported', () async {
      pipeline.readError = const ScanPipelineException('pipeline_failed');
      pipeline.readings.add((w) => readingJson(w));
      final r = await scanner.scan(
        await photo(),
        sampleKit(),
        pageOneVersion: (_) => null,
      );
      expect(
        (r as ExamSheetRejected).message,
        contains('ประมวลผลภาพไม่สำเร็จ'),
      );
    });
  });

  group('readKeySheet', () {
    late FakeExamScanRepository repo;

    setUp(() {
      repo = FakeExamScanRepository()
        ..proposal = (qr, v) => KeySheetProposal.fromJson({
          'version_no': v ?? 2,
          'page': 1,
          'proposal': [
            {
              'question_id': 501,
              'sheet_no': 1,
              'type': 'mcq',
              'accepted_options': [3],
              'doubtful': false,
              'differs': true,
            },
          ],
        });
      pipeline.readings.add((w) => readingJson(w, version: 2));
    });

    Future<List<Map<String, dynamic>>> pages(int version) async {
      expect(version, 1);
      return [layoutPage()];
    }

    test('reads the key sheet and asks the server for a proposal', () async {
      pipeline.detection = detectionFor(sheetQr(student: 0));
      final path = await photo();
      final r = await scanner.readKeySheet(
        path,
        examId,
        layoutPages: pages,
        repository: repo,
        versionNo: 2,
      );
      expect((r as KeySheetRead).proposal.items.single.key!.options, [3]);
      expect(repo.keyReads.single.versionNo, 2);
      expect(await File(path).exists(), isFalse);
      expect(await queue.listAll(), isEmpty);
    });

    test('refuses a student sheet, another exam and a missing page', () async {
      pipeline.detection = detectionFor(sheetQr());
      expect(
        (await scanner.readKeySheet(
                  await photo(),
                  examId,
                  layoutPages: pages,
                  repository: repo,
                )
                as KeySheetRejected)
            .message,
        contains('ไม่ใช่กระดาษเฉลย'),
      );
      pipeline.detection = detectionFor('EVX1.7.0.1.1.Q2M7K3PA');
      expect(
        await scanner.readKeySheet(
          await photo(),
          examId,
          layoutPages: pages,
          repository: repo,
        ),
        isA<KeySheetRejected>(),
      );
      pipeline.detection = detectionFor(sheetQr(student: 0, page: 2));
      expect(
        (await scanner.readKeySheet(
                  await photo(),
                  examId,
                  layoutPages: pages,
                  repository: repo,
                )
                as KeySheetRejected)
            .message,
        contains('หน้า 2'),
      );
    });

    test('server errors become the rejection message', () async {
      pipeline.detection = detectionFor(sheetQr(student: 0));
      final r = await scanner.readKeySheet(
        await photo(),
        examId,
        layoutPages: (_) async =>
            throw apiError(422, 'version_unknown', 'อ่านชุดไม่ได้'),
        repository: repo,
      );
      expect((r as KeySheetRejected).message, contains('อ่านชุดไม่ได้'));
    });
  });

  group('FrameGate', () {
    test('fires on the second good frame in a row of the same sheet', () {
      final gate = FrameGate(examId: examId);
      expect(gate.offer(goodFrame()), isNull);
      expect(gate.offer(goodFrame()), sheetQr());
      // The streak starts again after firing.
      expect(gate.offer(goodFrame()), isNull);
      expect(gate.offer(goodFrame(student: 12)), isNull);
      expect(gate.offer(goodFrame(student: 12)), sheetQr(student: 12));
    });

    test('a bad frame breaks the streak', () {
      final gate = FrameGate(examId: examId);
      expect(gate.offer(goodFrame()), isNull);
      expect(
        gate.offer(
          FrameDetection(markersFound: 3, qrPayload: sheetQr(), blurScore: 150),
        ),
        isNull,
      );
      expect(gate.offer(goodFrame()), isNull);
      expect(
        gate.offer(
          FrameDetection(markersFound: 4, qrPayload: sheetQr(), blurScore: 5),
        ),
        isNull,
      );
      expect(
        gate.offer(
          FrameDetection(
            markersFound: 4,
            qrPayload: 'EVX1.9.11.1.1.AAAAAAAA',
            blurScore: 150,
          ),
        ),
        isNull,
      );
      expect(
        gate.offer(
          FrameDetection(
            markersFound: 4,
            qrPayload: sheetQr(student: 0),
            blurScore: 150,
          ),
        ),
        isNull,
      );
      expect(gate.offer(goodFrame()), isNull);
      expect(gate.offer(goodFrame()), sheetQr());
    });
  });

  group('ExamScanSession', () {
    test('duplicates: silent within 5 seconds, then ask', () {
      final session = ExamScanSession(sampleKit());
      final t = DateTime.utc(2026, 10, 15, 3);
      final qr = ExamQr.tryParse(sheetQr())!;
      expect(session.check(qr, t), DuplicateVerdict.fresh);
      session.record(queued(at: t));
      expect(
        session.check(qr, t.add(const Duration(seconds: 4))),
        DuplicateVerdict.silent,
      );
      expect(
        session.check(qr, t.add(const Duration(seconds: 6))),
        DuplicateVerdict.seen,
      );
      expect(
        session.check(ExamQr.tryParse(sheetQr(student: 12))!, t),
        DuplicateVerdict.fresh,
      );
    });

    test('page 1 version comes from this session, then the server', () {
      final session = ExamScanSession(sampleKit(pageCount: 2));
      expect(session.pageOneVersion(11), isNull);
      session.server = ExamSheetStatus.fromJson({
        'data': [
          {
            'student_id': 12,
            'pages_received': [1],
            'version_no': 1,
          },
        ],
        'summary': {'max_score': 10},
      });
      expect(session.pageOneVersion(12), 1);
      session.record(queued(version: 2, pageCount: 2));
      expect(session.pageOneVersion(11), 2);
    });

    test('the summary counts complete students from all sources', () {
      final session = ExamScanSession(sampleKit(pageCount: 2));
      expect(session.summary().text, 'สแกนแล้ว 0/3 คน ยังขาด เลขที่ 1, 2, 3');

      session.record(queued(page: 1, pageCount: 2));
      session.setQueue([
        QueuedScan(
          clientScanId: 'q',
          state: ScanState.pending,
          kind: ScanKind.examSheet,
          meta: {'qr': sheetQr(page: 2)},
          files: const {},
          attempts: 0,
          lastError: null,
          serverScanId: null,
          nextAttemptAt: null,
          createdAt: DateTime.utc(2026),
          updatedAt: DateTime.utc(2026),
        ),
        QueuedScan(
          clientScanId: 'failed',
          state: ScanState.failed,
          kind: ScanKind.examSheet,
          meta: {'qr': sheetQr(student: 13, page: 1)},
          files: const {},
          attempts: 0,
          lastError: 'x',
          serverScanId: null,
          nextAttemptAt: null,
          createdAt: DateTime.utc(2026),
          updatedAt: DateTime.utc(2026),
        ),
      ]);
      session.server = ExamSheetStatus.fromJson({
        'data': [
          {
            'student_id': 12,
            'pages_received': [1, 2],
          },
          {
            'student_id': 13,
            'pages_received': [2],
          },
        ],
        'summary': {'max_score': 10},
      });
      final summary = session.summary();
      expect(summary.text, 'สแกนแล้ว 2/3 คน ยังขาด เลขที่ 3');
      expect(summary.missing.single.missingPages, [1]);
    });

    test('a long list of missing numbers is cut', () {
      final many = kitJson()
        ..['roster'] = [
          for (var n = 1; n <= 14; n++)
            {'student_id': 100 + n, 'student_number': n, 'name': 'คน $n'},
        ];
      final summary = ExamScanSession(ExamScanKit.fromJson(many)).summary();
      expect(
        summary.text,
        endsWith('เลขที่ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 และอีก 4 คน'),
      );

      final done = ExamScanSummary(scanned: 3, total: 3, missing: const []);
      expect(done.text, 'สแกนแล้ว 3/3 คน ครบทุกคนแล้ว');
    });
  });

  test('readings go to the API with string sheet numbers', () {
    final reading = AnswerSheetReading.fromJson(
      readingJson('/p.webp', marks: {1: 2, 4: '3'}, version: 1),
    );
    final json = reading.toApiJson();
    expect(json['version_fill'], {'1': 0.9, '2': 0.0});
    expect((json['rows'] as Map).keys, ['1', '2', '3']);
    expect((((json['digits'] as Map)['4'] as Map)['columns'] as List).first, {
      '0': 0.0,
      '1': 0.0,
      '2': 0.0,
      '3': 0.9,
    });
    expect(reading.blurScore, 150);
    expect(reading.baseline, 0.03);
  });

  test('kit helpers: pages, students and sheet numbers', () {
    final kit = sampleKit(pageCount: 2);
    expect(kit.printed, isTrue);
    expect(kit.layoutPage(2)!['page'], 2);
    expect(kit.layoutPage(3), isNull);
    expect(kit.sheetNumbersOn(2), {5, 6, 7, 8});
    expect(kit.student(12)!.name, 'ด.ช. สอง');
    expect(ExamScanKit.decode(kit.encode()).kitHash, kit.kitHash);
    expect(ExamScanKit.fromJson(kitJson(layoutVersion: null)).printed, isFalse);
  });
}
