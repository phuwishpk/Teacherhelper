import 'dart:io';

import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/attachment_downloader.dart';
import 'package:eduvision/features/google_classroom/classroom_importer.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/scan/offline_cache_repository.dart';
import 'package:eduvision/features/scan/scan_file_store.dart';
import 'package:eduvision/features/scan/scan_processor.dart';
import 'package:eduvision/features/upload_queue/scan_queue_repository.dart';
import 'package:eduvision/platform/attachment_rasterizer.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;

import '../helpers/fake_http_adapter.dart';
import '../scan/scan_fixtures.dart';

class _Assignments extends Fake implements AssignmentsRepository {
  /// The server cannot be reached: scans wait as `needs_layout`.
  bool offline = false;

  @override
  Future<List<LayoutVersion>> layouts(int assignmentId, {int? version}) async {
    if (offline) {
      throw DioException(
        requestOptions: RequestOptions(path: '/assignments/$assignmentId'),
        type: DioExceptionType.connectionError,
      );
    }
    return [
      LayoutVersion(
        version: 2,
        pages: [sampleLayoutPage(page: 1), sampleLayoutPage(page: 2)],
      ),
    ];
  }

  @override
  Future<Assignment> get(int id) async =>
      Assignment(id: id, classroomId: 7, subjectId: 1, title: 'เศษส่วน');
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
    RosterStudent(studentId: 4568, studentNumber: 13, name: 'ด.ช. สมชาย'),
  ];
}

/// Hands out numbered tokens and records invalidations.
class _FakeAuth implements GoogleAuthGateway {
  int issued = 0;
  final invalidated = <String>[];
  final emails = <String?>[];

  /// Thrown instead of the second token (the renewal after a 401).
  GoogleAuthException? renewalError;

  @override
  Future<DriveAccessToken> driveAccessToken({String? expectedEmail}) async {
    emails.add(expectedEmail);
    if (emails.length > 1 && renewalError != null) throw renewalError!;
    issued++;
    return DriveAccessToken(value: 'token-$issued', email: 'kru@school.ac.th');
  }

  @override
  Future<void> invalidate(DriveAccessToken token) async =>
      invalidated.add(token.value);

  @override
  Future<GoogleServerAuth> requestServerAuthCode() =>
      throw UnimplementedError();

  @override
  Future<void> signOut() async {}
}

/// Writes one JPEG per page next to the input, like the Kotlin plugin.
class _FakeRasterizer implements AttachmentRasterizer {
  _FakeRasterizer(this.outDir);

  final Directory outDir;
  int pages = 2;

  /// Pages in the file; more than [pages] when the plugin cut a long PDF.
  int? totalPages;
  Object? error;
  final calls = <(String, String)>[];

  @override
  bool get isSupported => true;

  @override
  Future<RasterizedPages> rasterize(String path, String mimeType) async {
    calls.add((path, mimeType));
    if (error case final e?) throw e;
    final dir = await Directory(
      p.join(outDir.path, 'scan_pipeline', 'raster${calls.length}'),
    ).create(recursive: true);
    return RasterizedPages([
      for (var i = 1; i <= pages; i++)
        (await File(
          p.join(dir.path, 'page_$i.jpg'),
        ).writeAsBytes([0xFF, 0xD8])).path,
    ], totalPages: totalPages);
  }
}

GoogleSubmission _submission({
  int id = 31,
  List<GoogleAttachment> attachments = const [
    GoogleAttachment(
      driveFileId: 'f1',
      title: 'IMG_1.jpg',
      mimeType: 'image/jpeg',
    ),
  ],
  SubmissionStudent? student = const SubmissionStudent(
    id: 4567,
    name: 'ด.ญ. สมหญิง',
    studentNumber: 12,
  ),
}) => GoogleSubmission(
  id: id,
  googleSubmissionId: 'Cg4ItestSub$id',
  state: SubmissionImportState.newSubmission,
  student: student,
  attachments: attachments,
);

/// DESIGN §18.2 "รับงาน": Drive download on the phone -> PDF/HEIC to pages
/// -> detectPage/cropPage -> upload queue with source = classroom.
void main() {
  late Directory tmp;
  late AppDatabase db;
  late ScanQueueRepository queue;
  late FakeScanPipeline pipeline;
  late _FakeRasterizer rasterizer;
  late _FakeAuth auth;
  late FakeHttpAdapter drive;
  late ClassroomImporter importer;
  late _Assignments assignments;
  late int queuedSignals;
  late Directory workRoot;
  var ids = 0;
  final served = <String>[];
  int? failNextWith;

  /// Drive answers request number n (1-based) with this status.
  final failOn = <int, int>{};

  setUp(() async {
    tmp = await Directory.systemTemp.createTemp('classroom_importer_test');
    workRoot = Directory(p.join(tmp.path, 'classroom_import'));
    db = AppDatabase(NativeDatabase.memory());
    queue = ScanQueueRepository(db);
    assignments = _Assignments();
    pipeline = FakeScanPipeline(cropDir: Directory(p.join(tmp.path, 'cache')));
    rasterizer = _FakeRasterizer(Directory(p.join(tmp.path, 'cache')));
    auth = _FakeAuth();
    queuedSignals = 0;
    ids = 0;
    served.clear();
    failNextWith = null;
    failOn.clear();
    drive = FakeHttpAdapter((options) async {
      served.add(options.headers['Authorization'] as String);
      if (failNextWith ?? failOn[served.length] case final status?) {
        failNextWith = null;
        return jsonResponse(status, {'error': status});
      }
      return ResponseBody.fromBytes([0xFF, 0xD8, 0xFF, 1, 2], 200);
    });
    final processor = ScanProcessor(
      pipeline: pipeline,
      offlineCache: OfflineCacheRepository(
        db,
        classrooms: _Classrooms(),
        assignments: assignments,
      ),
      assignments: assignments,
      classrooms: _Classrooms(),
      queue: queue,
      files: ScanFileStore(() async => Directory(p.join(tmp.path, 'store'))),
      onQueued: () => queuedSignals++,
      clock: () => DateTime.utc(2026, 10, 1, 2, 15),
      newId: () => 'scan-${++ids}',
    );
    importer = ClassroomImporter(
      processor: processor,
      rasterizer: rasterizer,
      downloader: DriveAttachmentDownloader(
        dio: Dio()..httpClientAdapter = drive,
      ),
      auth: auth,
      workRoot: () async => workRoot,
    );
  });

  tearDown(() async {
    await db.close();
    if (await tmp.exists()) await tmp.delete(recursive: true);
  });

  Future<SubmissionImport> run(GoogleSubmission s) async {
    final session = await importer.openSession(
      expectedEmail: 'kru@school.ac.th',
    );
    return importer.importSubmission(s, assignmentId: 123, session: session);
  }

  test(
    'a JPEG is scanned as it is and queued with source = classroom',
    () async {
      final progress = <String>[];
      final session = await importer.openSession(
        expectedEmail: 'kru@school.ac.th',
      );
      final result = await importer.importSubmission(
        _submission(),
        assignmentId: 123,
        session: session,
        onProgress: progress.add,
      );

      expect(auth.emails, ['kru@school.ac.th']);
      expect(served, ['Bearer token-1']);
      expect(rasterizer.calls, isEmpty, reason: 'OpenCV reads JPEG itself');
      expect(pipeline.detectCalls.single, endsWith('f1.jpg'));
      final outcome = result.outcomes.single as ImageQueued;
      expect(outcome.label, 'IMG_1.jpg');
      expect(outcome.page, 1);
      expect(outcome.student, 'ด.ญ. สมหญิง (เลขที่ 12)');
      expect(outcome.identityNote, isNull);
      expect(result.hasProblems, isFalse);
      expect(result.queuedCount, 1);

      final scan = (await queue.listAll()).single;
      expect(scan.clientScanId, outcome.clientScanId);
      expect(scan.meta['source'], 'classroom');
      expect(scan.meta['google_submission_id'], 'Cg4ItestSub31');
      expect(scan.meta['qr'], sampleQr);
      expect(queuedSignals, 1);
      expect(progress, isNotEmpty);
      expect(
        await Directory(p.join(workRoot.path, 'submission_31')).exists(),
        isFalse,
        reason: 'the download folder is cleaned up',
      );
    },
  );

  test('a PDF is rasterized page by page and the original deleted', () async {
    pipeline.nextDetections.addAll([
      goodDetection(),
      goodDetection(qr: 'EV1.123.4567.2.2.K7Q3M2PA'),
    ]);
    final result = await run(
      _submission(
        attachments: const [
          GoogleAttachment(
            driveFileId: 'pdf1',
            title: 'งาน.pdf',
            mimeType: 'application/pdf',
          ),
        ],
      ),
    );
    final (path, mime) = rasterizer.calls.single;
    expect(path, endsWith('pdf1.pdf'));
    expect(mime, 'application/pdf');
    expect(await File(path).exists(), isFalse);
    expect(result.outcomes.map((o) => o.label), [
      'งาน.pdf หน้า 1',
      'งาน.pdf หน้า 2',
    ]);
    expect(result.outcomes, everyElement(isA<ImageQueued>()));
    expect(pipeline.detectCalls, hasLength(2));
    expect(await queue.listAll(), hasLength(2));
  });

  test('HEIC goes through the rasterizer too', () async {
    rasterizer.pages = 1;
    final result = await run(
      _submission(
        attachments: const [
          GoogleAttachment(
            driveFileId: 'h1',
            title: 'IMG_2.HEIC',
            mimeType: 'image/heic',
          ),
        ],
      ),
    );
    expect(rasterizer.calls.single.$2, 'image/heic');
    expect(result.outcomes.single.label, 'IMG_2.HEIC');
    expect(result.outcomes.single, isA<ImageQueued>());
  });

  test(
    'an unusable photo gives Thai reasons and leaves nothing queued',
    () async {
      pipeline.detection = goodDetection(missing: [2]);
      final result = await run(_submission());
      final outcome = result.outcomes.single as ImageRejected;
      expect(outcome.reasons.single, contains('มุมล่างขวา'));
      expect(outcome.canOverride, isFalse);
      expect(result.hasProblems, isTrue);
      expect(result.problems.single, contains('มุมล่างขวา'));
      expect(await queue.listAll(), isEmpty);
      expect(
        await Directory(p.join(workRoot.path, 'submission_31')).exists(),
        isFalse,
      );
    },
  );

  test('a blurry photo can still be used by the teacher', () async {
    pipeline.detection = goodDetection(blur: 5);
    final submission = _submission();
    final result = await run(submission);
    final rejected = result.outcomes.single as ImageRejected;
    expect(rejected.canOverride, isTrue);
    expect(await File(rejected.analysis!.imagePath).exists(), isTrue);

    final next = await importer.acceptDespiteBlur(
      rejected,
      submission: submission,
      assignmentId: 123,
    );
    expect(next, isA<ImageQueued>());
    expect((await queue.listAll()).single.meta['source'], 'classroom');
    expect(result.replace(rejected, next).hasProblems, isFalse);
  });

  test('discardPending deletes kept pictures', () async {
    pipeline.detection = goodDetection(blur: 5);
    final result = await run(_submission());
    final kept = (result.outcomes.single as ImageRejected).analysis!.imagePath;
    await importer.discardPending(result.outcomes);
    expect(await File(kept).exists(), isFalse);
  });

  test('a worksheet of another assignment is refused', () async {
    pipeline.detection = goodDetection(qr: 'EV1.999.4567.1.2.SIG');
    final result = await run(_submission());
    final outcome = result.outcomes.single;
    expect(outcome, isA<ImageRejected>());
    expect((outcome as ImageRejected).reasons.single, contains('#999'));
    expect(await queue.listAll(), isEmpty);
  });

  test('the spare worksheet (student 0) is accepted from Classroom', () async {
    pipeline.detection = goodDetection(qr: 'EV1.123.0.1.2.SPARE');
    final result = await run(_submission());
    final outcome = result.outcomes.single as ImageQueued;
    expect(outcome.student, contains('ใบงานสำรอง'));
    expect(outcome.identityNote, isNull);
    expect(
      (await queue.listAll()).single.meta['google_submission_id'],
      'Cg4ItestSub31',
    );
  });

  test(
    'a QR of another student is kept with a note (identity mismatch)',
    () async {
      pipeline.detection = goodDetection(qr: 'EV1.123.4568.1.2.SIG');
      final result = await run(_submission());
      final outcome = result.outcomes.single as ImageQueued;
      expect(outcome.student, contains('ด.ช. สมชาย'));
      expect(outcome.identityNote, contains('ด.ญ. สมหญิง'));
    },
  );

  test('a rejected token is renewed once and the download retried', () async {
    failNextWith = 401;
    final result = await run(_submission());
    expect(result.outcomes.single, isA<ImageQueued>());
    expect(auth.invalidated, ['token-1']);
    expect(served, ['Bearer token-1', 'Bearer token-2']);
  });

  test('download and conversion failures are reported per file', () async {
    failNextWith = 404;
    rasterizer.error = const RasterizeException('pdf_unreadable');
    final result = await run(
      _submission(
        attachments: const [
          GoogleAttachment(
            driveFileId: 'gone',
            title: 'a.jpg',
            mimeType: 'image/jpeg',
          ),
          GoogleAttachment(
            driveFileId: 'p',
            title: 'b.pdf',
            mimeType: 'application/pdf',
          ),
          GoogleAttachment(
            driveFileId: 'doc',
            title: 'c',
            mimeType: 'application/vnd.google-apps.document',
          ),
        ],
      ),
    );
    final reasons = [
      for (final o in result.outcomes) (o as AttachmentFailed).reason,
    ];
    expect(reasons[0], contains('ไม่พบไฟล์'));
    expect(reasons[1], contains('PDF'));
    expect(reasons[2], contains('รูปถ่าย'));
    expect(served, hasLength(2), reason: 'the Google Doc is never requested');
    expect(result.problems, hasLength(3));
  });

  test('a submission without attachments is a problem', () async {
    final result = await run(_submission(attachments: const []));
    expect(result.outcomes, isEmpty);
    expect(result.hasProblems, isTrue);
    expect(result.problems, ['ไม่มีไฟล์แนบในงานที่ส่ง']);
  });

  test(
    'a PDF cut at the page limit says which pages were not scanned',
    () async {
      rasterizer.totalPages = 25;
      pipeline.nextDetections.addAll([
        goodDetection(),
        goodDetection(qr: 'EV1.123.4567.2.2.K7Q3M2PA'),
      ]);
      final result = await run(
        _submission(
          attachments: const [
            GoogleAttachment(
              driveFileId: 'long',
              title: 'ยาว.pdf',
              mimeType: 'application/pdf',
            ),
          ],
        ),
      );
      expect(result.outcomes.map((o) => o.label), [
        'ยาว.pdf หน้า 1',
        'ยาว.pdf หน้า 2',
        'ยาว.pdf หน้า 3–25',
      ]);
      final note = result.outcomes.last as AttachmentFailed;
      expect(note.reason, contains('PDF มี 25 หน้า'));
      expect(note.reason, contains('หน้าที่ 3–25 ยังไม่ได้สแกน'));
      expect(result.hasProblems, isTrue);
      expect(result.problems.single, note.reason);
      expect(await queue.listAll(), hasLength(2));
    },
  );

  group('a spare worksheet from an unmatched account', () {
    test('is not queued and asks the teacher to match first', () async {
      pipeline.detection = goodDetection(qr: 'EV1.123.0.1.2.SPARE');
      final result = await run(_submission(student: null));
      final outcome = result.outcomes.single as ImageNeedsMatch;
      expect(outcome.reason, contains('จับคู่นักเรียนที่หน้าห้องเรียน'));
      expect(result.needsMatchCount, 1);
      expect(
        result.hasProblems,
        isFalse,
        reason: 'nothing for the student to redo',
      );
      expect(await queue.listAll(), isEmpty);
      expect(queuedSignals, 0);
      expect(
        await Directory(
          p.join(tmp.path, 'cache', 'scan_pipeline'),
        ).list(recursive: true).where((e) => e is File).isEmpty,
        isTrue,
        reason: 'the crops are deleted',
      );
    });

    test('is not kept for later either when the layout is offline', () async {
      assignments.offline = true;
      pipeline.detection = goodDetection(qr: 'EV1.123.0.1.2.SPARE');
      final result = await run(_submission(student: null));
      expect(result.outcomes.single, isA<ImageNeedsMatch>());
      expect(await queue.listAll(), isEmpty);
    });

    test('a named worksheet from that account is kept by its QR', () async {
      final result = await run(_submission(student: null));
      final outcome = result.outcomes.single as ImageQueued;
      expect(outcome.student, 'ด.ญ. สมหญิง (เลขที่ 12)');
      expect(await queue.listAll(), hasLength(1));
    });
  });

  test(
    'a page still waiting in the upload queue is not queued twice',
    () async {
      final first = await run(_submission());
      expect(first.outcomes.single, isA<ImageQueued>());

      // "ดาวน์โหลดและสแกน" again before the upload finished.
      final again = await run(_submission());
      final skipped = again.outcomes.single as ImageAlreadyQueued;
      expect(skipped.page, 1);
      expect(skipped.student, 'ด.ญ. สมหญิง (เลขที่ 12)');
      expect(again.hasProblems, isFalse);
      expect(again.queuedCount, 0);
      expect(await queue.listAll(), hasLength(1));
      expect(queuedSignals, 1);
    },
  );

  test('a phone that cannot store the file says so per file', () async {
    await File(workRoot.path).create(recursive: true);
    final result = await run(_submission());
    expect(
      (result.outcomes.single as AttachmentFailed).reason,
      const LocalStorageFailed().message,
    );
    expect(served, isEmpty);
  });

  test(
    'a refused token renewal stops the import and keeps what was done',
    () async {
      auth.renewalError = const GoogleAuthCanceled();
      failOn[2] = 401;
      final result = await run(
        _submission(
          attachments: const [
            GoogleAttachment(
              driveFileId: 'a',
              title: 'a.jpg',
              mimeType: 'image/jpeg',
            ),
            GoogleAttachment(
              driveFileId: 'b',
              title: 'b.jpg',
              mimeType: 'image/jpeg',
            ),
            GoogleAttachment(
              driveFileId: 'c',
              title: 'c.jpg',
              mimeType: 'image/jpeg',
            ),
          ],
        ),
      );
      expect(result.outcomes.single, isA<ImageQueued>());
      expect(result.isComplete, isFalse);
      expect(result.interruption, const GoogleAuthCanceled().message);
      expect(result.authFailure, isA<GoogleAuthCanceled>());
      expect(result.hasProblems, isFalse, reason: 'not the student\'s problem');
      expect(result.problems, isEmpty);
      expect(auth.invalidated, ['token-1']);
      expect(served, hasLength(2), reason: 'the third file is not requested');
      expect(await queue.listAll(), hasLength(1));
      expect(
        await Directory(p.join(workRoot.path, 'submission_31')).exists(),
        isFalse,
      );
    },
  );

  test('an unexpected error deletes pictures kept for a decision', () async {
    pipeline.detection = goodDetection(blur: 5);
    rasterizer.error = StateError('plugin gone');
    await expectLater(
      run(
        _submission(
          attachments: const [
            GoogleAttachment(
              driveFileId: 'a',
              title: 'a.jpg',
              mimeType: 'image/jpeg',
            ),
            GoogleAttachment(
              driveFileId: 'b',
              title: 'b.pdf',
              mimeType: 'application/pdf',
            ),
          ],
        ),
      ),
      throwsA(isA<StateError>()),
    );
    expect(
      await Directory(p.join(workRoot.path, 'submission_31')).exists(),
      isFalse,
      reason: 'the blurry a.jpg kept for a decision is gone too',
    );
  });

  group('ClassroomImportController', () {
    late ProviderContainer container;

    setUp(() {
      container = ProviderContainer(
        overrides: [classroomImporterProvider.overrideWithValue(importer)],
      );
      container.listen(classroomImportProvider(123), (_, _) {});
    });

    tearDown(() => container.dispose());

    ClassroomImportController controller() =>
        container.read(classroomImportProvider(123).notifier);

    ClassroomImportState state() =>
        container.read(classroomImportProvider(123));

    test(
      'a refused token renewal stops the batch without a row left running',
      () async {
        auth.renewalError = const GoogleAuthFailed(
          'เข้าสู่ระบบ Google ไม่สำเร็จ',
        );
        failOn[1] = 401;
        await expectLater(
          controller().run([
            _submission(id: 31),
            _submission(id: 32),
          ], expectedEmail: 'kru@school.ac.th'),
          throwsA(
            isA<ClassroomImportStopped>()
                .having((e) => e.done, 'done', 0)
                .having((e) => e.total, 'total', 2)
                .having((e) => e.message, 'message', contains('เสร็จ 0 จาก 2')),
          ),
        );
        expect(state().running, isFalse);
        expect(state().batch, isNull);
        final row = state().rows[31]!;
        expect(row.running, isFalse);
        expect(row.result!.interruption, 'เข้าสู่ระบบ Google ไม่สำเร็จ');
        expect(state().rows.containsKey(32), isFalse, reason: 'batch stopped');
        expect(served, hasLength(1));
      },
    );

    test('an unexpected error leaves the row stopped with a reason', () async {
      rasterizer.error = StateError('plugin gone');
      await expectLater(
        controller().run([
          _submission(
            attachments: const [
              GoogleAttachment(
                driveFileId: 'p',
                title: 'b.pdf',
                mimeType: 'application/pdf',
              ),
            ],
          ),
        ]),
        throwsA(isA<StateError>()),
      );
      expect(state().running, isFalse);
      expect(state().rows[31]!.result!.interruption, contains('ไม่สำเร็จ'));

      // The buttons work again: a new run goes through.
      rasterizer.error = null;
      pipeline.nextDetections.addAll([
        goodDetection(),
        goodDetection(qr: 'EV1.123.4567.2.2.K7Q3M2PA'),
      ]);
      await controller().run([
        _submission(
          attachments: const [
            GoogleAttachment(
              driveFileId: 'p',
              title: 'b.pdf',
              mimeType: 'application/pdf',
            ),
          ],
        ),
      ]);
      expect(state().rows[31]!.result!.queuedCount, 2);
      expect(state().rows[31]!.result!.isComplete, isTrue);
    });
  });
}
