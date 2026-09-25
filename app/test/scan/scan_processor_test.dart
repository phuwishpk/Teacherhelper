import 'dart:io';

import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/scan/offline_cache_repository.dart';
import 'package:eduvision/features/scan/scan_file_store.dart';
import 'package:eduvision/features/scan/scan_meta.dart';
import 'package:eduvision/features/scan/scan_processor.dart';
import 'package:eduvision/features/scan/scan_quality.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;

import 'scan_fixtures.dart';

DioException _http(int? status, {String code = 'not_found'}) => DioException(
  requestOptions: RequestOptions(path: '/assignments/123/layouts'),
  response: status == null
      ? null
      : Response(
          requestOptions: RequestOptions(path: '/assignments/123/layouts'),
          statusCode: status,
          data: {'message': 'ไม่พบข้อมูล', 'errors': null, 'code': code},
        ),
  type: status == null
      ? DioExceptionType.connectionError
      : DioExceptionType.badResponse,
);

class FakeAssignments extends Fake implements AssignmentsRepository {
  /// Pages served for (assignment 123, version 2); null = offline.
  List<Map<String, dynamic>>? pages = [
    sampleLayoutPage(page: 1),
    sampleLayoutPage(page: 2),
  ];
  int? failStatus;
  String failCode = 'not_found';
  final layoutCalls = <int?>[];
  final getCalls = <int>[];

  @override
  Future<List<LayoutVersion>> layouts(int assignmentId, {int? version}) async {
    layoutCalls.add(version);
    if (failStatus case final s?) throw _http(s, code: failCode);
    final served = pages;
    if (served == null) throw _http(null);
    return [LayoutVersion(version: 2, pages: served)];
  }

  @override
  Future<Assignment> get(int id) async {
    getCalls.add(id);
    if (pages == null) throw _http(null);
    return Assignment(id: id, classroomId: 7, subjectId: 1, title: 'เศษส่วน');
  }
}

class FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
  ];
}

void main() {
  late Directory tmp;
  late Directory store;
  late AppDatabase db;
  late OfflineCacheRepository cache;
  late ScanQueueRepository queue;
  late FakeAssignments assignments;
  late FakeScanPipeline pipeline;
  late ScanProcessor processor;
  late int queuedSignals;
  var ids = 0;

  Future<String> photo([String name = 'photo.jpg']) async {
    final f = File(p.join(tmp.path, 'camera', name));
    await f.create(recursive: true);
    await f.writeAsBytes([0xFF, 0xD8, 0xFF]);
    return f.path;
  }

  ScanProcessor makeProcessor({DigitReader? digitReader}) => ScanProcessor(
    pipeline: pipeline,
    offlineCache: cache,
    assignments: assignments,
    classrooms: FakeClassrooms(),
    queue: queue,
    files: ScanFileStore(() async => store),
    onQueued: () => queuedSignals++,
    digitReader: digitReader,
    clock: () => DateTime.utc(2026, 10, 1, 2, 15),
    newId: () => 'scan-${++ids}',
  );

  setUp(() async {
    tmp = await Directory.systemTemp.createTemp('scan_processor_test');
    store = Directory(p.join(tmp.path, 'store'));
    db = AppDatabase(NativeDatabase.memory());
    assignments = FakeAssignments();
    cache = OfflineCacheRepository(
      db,
      classrooms: FakeClassrooms(),
      assignments: assignments,
    );
    queue = ScanQueueRepository(db);
    pipeline = FakeScanPipeline(cropDir: Directory(p.join(tmp.path, 'cache')));
    queuedSignals = 0;
    ids = 0;
    processor = makeProcessor();
  });

  tearDown(() async {
    await db.close();
    await tmp.delete(recursive: true);
  });

  Future<void> cacheSampleLayout() =>
      cache.cacheLayout(123, 2, [sampleLayoutPage(page: 1)]);

  Future<void> cacheSampleRoster() => cache.replaceRoster(7, const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
  ]);

  group('analyze', () {
    test('uses the cached layout and roster without the network', () async {
      await cacheSampleLayout();
      await cacheSampleRoster();

      final result = await processor.analyze(await photo());

      expect(result, isA<ScanReady>());
      final ready = result as ScanReady;
      expect(ready.qr.assignmentId, 123);
      expect(ready.layout.page, 1);
      expect(ready.student!.name, 'ด.ญ. สมหญิง');
      expect(ready.alreadyQueued, isFalse);
      expect(ready.capturedAt, DateTime.utc(2026, 10, 1, 2, 15));
      expect(assignments.layoutCalls, isEmpty);
      expect(assignments.getCalls, isEmpty);
      expect(pipeline.cropCalls.single, ready.layout.encode());
    });

    test('fetches and caches a missing layout when online', () async {
      final ready = await processor.analyze(await photo()) as ScanReady;

      expect(assignments.layoutCalls, [2]);
      expect(ready.layout.version, 2);
      expect(
        await cache.layoutPage(assignmentId: 123, version: 2, page: 2),
        isNotNull,
        reason: 'every page of the version is cached',
      );
      // The roster was not cached either: fetched once for this assignment.
      expect(ready.student!.studentNumber, 12);
      expect(assignments.getCalls, [123]);

      await processor.analyze(await photo('second.jpg'));
      expect(assignments.layoutCalls, [2], reason: 'now served from drift');
      expect(assignments.getCalls, [123]);
    });

    test('offline without a cached layout -> needs_layout', () async {
      assignments.pages = null;

      final result = await processor.analyze(await photo());

      expect(result, isA<ScanNeedsLayout>());
      final scan = result as ScanNeedsLayout;
      expect(scan.qr.page, 1);
      expect(scan.student, isNull);
      expect(scan.reason, contains('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'));
      expect(pipeline.cropCalls, isEmpty);
    });

    test('an assignment the server does not know is rejected', () async {
      assignments.failStatus = 404;

      final result = await processor.analyze(await photo()) as ScanRejected;

      expect(result.issues.single, isA<AssignmentUnknown>());
      expect(result.canOverride, isFalse);
    });

    test('a layout version the server does not have is not blamed on '
        'another teacher', () async {
      // LayoutController: 404 layout_unknown when the teacher owns the
      // assignment but not this layout version.
      assignments
        ..failStatus = 404
        ..failCode = 'layout_unknown';

      final result = await processor.analyze(await photo()) as ScanRejected;

      final issue = result.issues.single as LayoutPageUnknown;
      expect((issue.page, issue.version), (1, 2));
      expect(issue.message, isNot(contains('ครูคนอื่น')));
      expect(
        await cache.layoutPage(assignmentId: 123, version: 2, page: 1),
        isNull,
      );
    });

    test('403 on the layouts is still an unknown assignment', () async {
      assignments
        ..failStatus = 403
        ..failCode = 'forbidden';

      final result = await processor.analyze(await photo()) as ScanRejected;

      expect(result.issues.single, isA<AssignmentUnknown>());
    });

    test('a page that is not in the layout version is rejected', () async {
      pipeline.detection = goodDetection(qr: 'EV1.123.4567.3.2.K7Q3M2PA');

      final result = await processor.analyze(await photo()) as ScanRejected;

      expect(result.issues.single, isA<LayoutPageUnknown>());
    });

    test('detection problems stop before any layout lookup', () async {
      pipeline.detection = goodDetection(missing: [3], qr: null);

      final result = await processor.analyze(await photo()) as ScanRejected;

      expect(result.issues.map((i) => i.runtimeType), [
        MarkersMissing,
        QrUnreadable,
      ]);
      expect(assignments.layoutCalls, isEmpty);
      expect(pipeline.cropCalls, isEmpty);
    });

    test('a blurry page can be kept on purpose', () async {
      await cacheSampleLayout();
      pipeline.detection = goodDetection(blur: 10);

      final rejected = await processor.analyze(await photo()) as ScanRejected;
      expect(rejected.canOverride, isTrue);

      final result = await processor.acceptDespiteBlur(rejected);
      expect(result, isA<ScanReady>());
    });

    test('native failures become a Thai retake message', () async {
      pipeline.detectError = const ScanPipelineException('image_unreadable');
      final r1 = await processor.analyze(await photo()) as ScanRejected;
      expect(r1.issues.single.message, 'เปิดไฟล์ภาพไม่ได้ ลองถ่ายใหม่');

      pipeline.detectError = null;
      pipeline.cropError = const ScanPipelineException('layout_invalid');
      await cacheSampleLayout();
      final r2 = await processor.analyze(await photo()) as ScanRejected;
      expect(r2.issues.single, isA<ProcessingFailed>());
      expect(r2.issues.single.message, contains('layout'));
    });
  });

  group('confirm', () {
    test('queues a pending scan with files moved out of the cache', () async {
      await cacheSampleLayout();
      await cacheSampleRoster();
      final photoPath = await photo();
      final ready = await processor.analyze(photoPath) as ScanReady;

      final id = await processor.confirm(ready);

      expect(id, 'scan-1');
      final row = (await queue.find(id))!;
      expect(row.state, ScanState.pending);
      expect(row.meta['client_scan_id'], id);
      expect(row.meta['qr'], sampleQr);
      expect(row.meta['scanned_at'], '2026-10-01T02:15:00.000Z');
      expect((row.meta['regions'] as List), hasLength(3));
      expect(row.files.keys, [
        'page',
        'crop_q501',
        'crop_q502',
        'crop_q503',
        'crop_q503_final',
      ]);
      for (final path in row.files.values) {
        expect(p.isWithin(p.join(store.path, id), path), isTrue);
        expect(File(path).existsSync(), isTrue);
      }
      expect(p.basename(row.files['crop_q503_final']!), 'crop_q503_final.webp');
      expect(File(photoPath).existsSync(), isFalse, reason: 'photo dropped');
      expect(File(ready.crops.warpedPagePath).existsSync(), isFalse);
      expect(
        Directory(p.dirname(ready.crops.warpedPagePath)).existsSync(),
        isFalse,
        reason: 'the empty pipeline output folder is removed',
      );
      expect(
        Directory(p.dirname(photoPath)).existsSync(),
        isTrue,
        reason: 'the camera folder is not ours to remove',
      );
      expect(queuedSignals, 1);

      // The same page again: flagged on the confirm screen.
      final again = await processor.analyze(await photo('again.jpg'));
      expect((again as ScanReady).alreadyQueued, isTrue);
    });

    test('attaches the digit reader answers', () async {
      await cacheSampleLayout();
      processor = makeProcessor(
        digitReader: (crops) async => {
          for (final c in crops.regions)
            if (c.cnnInput != null)
              c.regionId: const CnnReading(text: '5', confidence: 0.9),
        },
      );
      final ready = await processor.analyze(await photo()) as ScanReady;
      final row = (await queue.find(await processor.confirm(ready)))!;
      final regions = (row.meta['regions'] as List).cast<Map>();
      expect(regions[1]['cnn'], {'text': '5', 'confidence': 0.9});
      expect(regions[2]['cnn'], {'text': '5', 'confidence': 0.9});
    });

    test('a failing digit reader does not block the scan', () async {
      await cacheSampleLayout();
      processor = makeProcessor(digitReader: (_) => throw StateError('boom'));
      final ready = await processor.analyze(await photo()) as ScanReady;
      final row = (await queue.find(await processor.confirm(ready)))!;
      expect(row.state, ScanState.pending);
    });

    test('a crop missing from the pipeline output is rejected', () async {
      await cacheSampleLayout();
      final bad = _DroppingPipeline(pipeline);
      processor = ScanProcessor(
        pipeline: bad,
        offlineCache: cache,
        assignments: assignments,
        classrooms: FakeClassrooms(),
        queue: queue,
        files: ScanFileStore(() async => store),
        onQueued: () {},
      );

      final result = await processor.analyze(await photo()) as ScanRejected;

      expect(result.issues.single, isA<ProcessingFailed>());
      expect(File(bad.last!.warpedPagePath).existsSync(), isFalse);
    });

    test('discard deletes the photo and the crops', () async {
      await cacheSampleLayout();
      final photoPath = await photo();
      final ready = await processor.analyze(photoPath) as ScanReady;

      await processor.discard(ready);

      expect(File(photoPath).existsSync(), isFalse);
      for (final c in ready.crops.regions) {
        expect(File(c.imagePath).existsSync(), isFalse);
      }
      expect(
        Directory(p.dirname(ready.crops.warpedPagePath)).existsSync(),
        isFalse,
      );
      expect(await queue.listAll(), isEmpty);
    });
  });

  group('Google Classroom source', () {
    const spareQr = 'EV1.123.0.1.2.K7Q3M2PA';
    const source = ScanSource.classroom(googleSubmissionId: 'sub-1');

    test('the camera still refuses a spare worksheet', () async {
      await cacheSampleLayout();
      pipeline.detection = goodDetection(qr: spareQr);

      final result = await processor.analyze(await photo()) as ScanRejected;

      expect(result.issues.single, isA<SpareWorksheet>());
      expect(pipeline.cropCalls, isEmpty);
    });

    test('a spare worksheet from a submission is cropped and queued with '
        'its source', () async {
      await cacheSampleLayout();
      pipeline.detection = goodDetection(qr: spareQr);

      final ready =
          await processor.analyze(await photo(), source: source) as ScanReady;

      expect(ready.source, source);
      expect(ready.qr.studentId, 0);
      expect(ready.student, isNull);
      expect(assignments.getCalls, isEmpty, reason: 'no roster lookup for 0');

      final row = (await queue.find(await processor.confirm(ready)))!;
      expect(row.state, ScanState.pending);
      expect(row.meta['qr'], spareQr);
      expect(row.meta['source'], 'classroom');
      expect(row.meta['google_submission_id'], 'sub-1');
      expect(row.meta['regions'], hasLength(3));
    });

    test('camera scans carry no source field', () async {
      await cacheSampleLayout();
      final ready = await processor.analyze(await photo()) as ScanReady;
      final row = (await queue.find(await processor.confirm(ready)))!;
      expect(row.meta.containsKey('source'), isFalse);
      expect(row.meta.containsKey('google_submission_id'), isFalse);
    });

    test(
      'spare worksheets of different submissions are not duplicates',
      () async {
        await cacheSampleLayout();
        pipeline.detection = goodDetection(qr: spareQr);
        final first =
            await processor.analyze(await photo('a.jpg'), source: source)
                as ScanReady;
        await processor.confirm(first);

        final other =
            await processor.analyze(
                  await photo('b.jpg'),
                  source: const ScanSource.classroom(
                    googleSubmissionId: 'sub-2',
                  ),
                )
                as ScanReady;
        expect(other.alreadyQueued, isFalse);

        final same =
            await processor.analyze(await photo('c.jpg'), source: source)
                as ScanReady;
        expect(same.alreadyQueued, isTrue);
      },
    );

    test('the blur override keeps the source', () async {
      await cacheSampleLayout();
      pipeline.detection = goodDetection(qr: spareQr, blur: 10);

      final rejected =
          await processor.analyze(await photo(), source: source)
              as ScanRejected;
      expect(rejected.source, source);

      final kept = await processor.acceptDespiteBlur(rejected);
      expect(kept, isA<ScanReady>());
      expect(kept.source, source);
    });

    test(
      'an offline spare worksheet keeps its source until it is cropped',
      () async {
        assignments.pages = null;
        pipeline.detection = goodDetection(qr: spareQr);
        final offline =
            await processor.analyze(await photo(), source: source)
                as ScanNeedsLayout;
        expect(offline.source, source);

        final id = await processor.keepForLater(offline);
        var row = (await queue.find(id))!;
        expect(row.meta['source'], 'classroom');
        expect(row.meta['google_submission_id'], 'sub-1');

        assignments.pages = [sampleLayoutPage()];
        final run = await processor.processNeedsLayout();
        expect((run.processed, run.waiting, run.failed), (1, 0, 0));

        row = (await queue.find(id))!;
        expect(row.state, ScanState.pending);
        expect(row.meta['source'], 'classroom');
        expect(row.meta['google_submission_id'], 'sub-1');
        expect(row.meta['regions'], hasLength(3));
      },
    );
  });

  group('needs_layout', () {
    test('keeps the photo, then crops it once the layout arrives', () async {
      assignments.pages = null;
      final photoPath = await photo();
      final offline = await processor.analyze(photoPath) as ScanNeedsLayout;

      final id = await processor.keepForLater(offline);

      var row = (await queue.find(id))!;
      expect(row.state, ScanState.needsLayout);
      expect(row.files.keys, [rawFileField]);
      expect(p.isWithin(store.path, row.files[rawFileField]!), isTrue);
      expect(File(photoPath).existsSync(), isFalse);
      expect(row.meta, {
        'client_scan_id': id,
        'qr': sampleQr,
        'scanned_at': '2026-10-01T02:15:00.000Z',
        'blur_score': 182.4,
      });
      expect(queuedSignals, 0, reason: 'nothing to upload yet');

      // Still offline: nothing happens.
      var run = await processor.processNeedsLayout();
      expect((run.processed, run.waiting, run.failed), (0, 1, 0));

      // Back online.
      assignments.pages = [sampleLayoutPage()];
      final raw = row.files[rawFileField]!;
      run = await processor.processNeedsLayout();
      expect((run.processed, run.waiting, run.failed), (1, 0, 0));

      row = (await queue.find(id))!;
      expect(row.state, ScanState.pending);
      expect(row.meta['client_scan_id'], id);
      expect(row.meta['scanned_at'], '2026-10-01T02:15:00.000Z');
      expect(row.meta['regions'], hasLength(3));
      expect(row.files.keys, contains('crop_q503_final'));
      expect(row.files.containsKey(rawFileField), isFalse);
      for (final path in row.files.values) {
        expect(File(path).existsSync(), isTrue);
      }
      expect(File(raw).existsSync(), isFalse);
      expect(pipeline.detectCalls.last, raw);
      expect(queuedSignals, 1);
    });

    test('a scan whose assignment is gone fails with a reason', () async {
      assignments.pages = null;
      final offline = await processor.analyze(await photo()) as ScanNeedsLayout;
      final id = await processor.keepForLater(offline);

      assignments.failStatus = 403;
      final run = await processor.processNeedsLayout();

      expect(run.failed, 1);
      final row = (await queue.find(id))!;
      expect(row.state, ScanState.failed);
      expect(row.lastError, contains('#123'));
    });

    test('a missing raw photo fails the row', () async {
      await queue.enqueue(
        clientScanId: 'lost',
        meta: {'client_scan_id': 'lost', 'qr': sampleQr},
        files: {rawFileField: p.join(tmp.path, 'gone.jpg')},
        state: ScanState.needsLayout,
      );

      final run = await processor.processNeedsLayout();

      expect(run.failed, 1);
      expect((await queue.find('lost'))!.state, ScanState.failed);
    });
  });
}

/// Returns crops without the final-answer box.
class _DroppingPipeline implements ScanPipeline {
  _DroppingPipeline(this.inner);

  final FakeScanPipeline inner;
  PageCrops? last;

  @override
  bool get isSupported => true;

  @override
  Future<PageDetection> detectPage(String imagePath) =>
      inner.detectPage(imagePath);

  @override
  Future<PageCrops> cropPage(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) async {
    final crops = await inner.cropPage(imagePath, detection, layoutJson);
    crops.regions.removeWhere((c) => c.regionId.endsWith('_final'));
    return last = crops;
  }
}
