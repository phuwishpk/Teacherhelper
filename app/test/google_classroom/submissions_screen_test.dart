import 'dart:io';

import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/google_classroom/classroom_importer.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/google_classroom/submissions_screen.dart';
import 'package:eduvision/features/scan/scan_meta.dart';
import 'package:eduvision/features/scan/scan_processor.dart';
import 'package:eduvision/features/scan/scan_quality.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import '../scan/scan_fixtures.dart';
import 'google_fakes.dart';

class _Assignments extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async =>
      Assignment(id: id, classroomId: 7, subjectId: 1, title: 'เศษส่วน');
}

class _Session implements DriveSession {
  @override
  Future<File> download(GoogleAttachment attachment, Directory directory) =>
      throw UnimplementedError();
}

final _blurry = ScanRejected(
  imagePath: '/tmp/blurry.jpg',
  capturedAt: DateTime.utc(2026, 10, 1),
  detection: goodDetection(blur: 5),
  source: const ScanSource.classroom(googleSubmissionId: 'Cg33'),
  issues: const [TooBlurry(5, 60)],
);

/// Scripted importer: no Drive, no pipeline.
class _FakeImporter extends Fake implements ClassroomImporter {
  final imported = <int>[];
  final emails = <String?>[];
  final discarded = <ImageOutcome>[];
  GoogleAuthException? authError;

  /// Scripted per row; an [Error] or [Exception] is thrown.
  final results = <int, Object>{};

  @override
  bool get isSupported => true;

  @override
  Future<DriveSession> openSession({String? expectedEmail}) async {
    emails.add(expectedEmail);
    if (authError case final e?) throw e;
    return _Session();
  }

  @override
  Future<SubmissionImport> importSubmission(
    GoogleSubmission submission, {
    required int assignmentId,
    required DriveSession session,
    void Function(String status)? onProgress,
  }) async {
    imported.add(submission.id);
    onProgress?.call('กำลังดาวน์โหลด…');
    switch (results[submission.id]) {
      case final SubmissionImport result:
        return result;
      case final Object error?:
        throw error;
      case null:
        break;
    }
    return switch (submission.id) {
      31 => SubmissionImport(
        submissionId: 31,
        outcomes: const [
          ImageQueued(
            'IMG_1.jpg',
            clientScanId: 'scan-1',
            page: 1,
            student: 'ด.ญ. สมหญิง (เลขที่ 12)',
          ),
          ImageRejected(
            'IMG_2.jpg',
            reasons: [
              'มองไม่เห็นสัญลักษณ์ที่มุมล่างขวา ถอยกล้องออกหรือจัดกระดาษให้เห็นครบทั้ง 4 มุม',
            ],
          ),
        ],
      ),
      _ => SubmissionImport(
        submissionId: submission.id,
        outcomes: [
          ImageRejected(
            'IMG_3.jpg',
            reasons: const ['ภาพไม่คมชัด'],
            analysis: _blurry,
          ),
        ],
      ),
    };
  }

  @override
  Future<ImageOutcome> acceptDespiteBlur(
    ImageRejected rejected, {
    required GoogleSubmission submission,
    required int assignmentId,
  }) async => ImageQueued(
    rejected.label,
    clientScanId: 'scan-9',
    page: 2,
    student: 'ด.ช. สมชาย (เลขที่ 13)',
  );

  @override
  Future<void> discardPending(Iterable<ImageOutcome> outcomes) async =>
      discarded.addAll(outcomes);
}

const _rows = [
  GoogleSubmission(
    id: 31,
    googleSubmissionId: 'Cg31',
    state: SubmissionImportState.newSubmission,
    student: SubmissionStudent(
      id: 4567,
      name: 'ด.ญ. สมหญิง',
      studentNumber: 12,
    ),
    attachments: [
      GoogleAttachment(
        driveFileId: 'a',
        title: 'IMG_1.jpg',
        mimeType: 'image/jpeg',
      ),
      GoogleAttachment(
        driveFileId: 'b',
        title: 'IMG_2.jpg',
        mimeType: 'image/jpeg',
      ),
    ],
    alternateLink: 'https://classroom.google.com/s/31',
  ),
  GoogleSubmission(
    id: 32,
    googleSubmissionId: 'Cg32',
    state: SubmissionImportState.gradeFailed,
    student: SubmissionStudent(id: 4568, name: 'ด.ช. สมชาย', studentNumber: 13),
    attachments: [
      GoogleAttachment(
        driveFileId: 'c',
        title: 'scan.pdf',
        mimeType: 'application/pdf',
      ),
    ],
    lastError: 'ProjectPermissionDenied',
  ),
  GoogleSubmission(
    id: 33,
    googleSubmissionId: 'Cg33',
    state: SubmissionImportState.needsRetake,
    attachments: [
      GoogleAttachment(
        driveFileId: 'd',
        title: 'IMG_3.jpg',
        mimeType: 'image/jpeg',
      ),
    ],
    retakeReason: 'เงาบัง QR',
  ),
];

Future<(FakeGoogleRepository, _FakeImporter)> _pump(
  WidgetTester tester, {
  FakeGoogleRepository? google,
}) async {
  tester.view.physicalSize = const Size(1000, 3000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final repo = google ?? FakeGoogleRepository(submissionRows: _rows);
  final importer = _FakeImporter();
  await pumpScreen(
    tester,
    const GoogleSubmissionsScreen(assignmentId: 12),
    overrides: [
      googleClassroomEnabledProvider.overrideWithValue(true),
      googleClassroomRepositoryProvider.overrideWithValue(repo),
      googleAuthProvider.overrideWithValue(FakeGoogleAuth()),
      assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
      classroomImporterProvider.overrideWithValue(importer),
    ],
  );
  return (repo, importer);
}

ButtonStyleButton _button(WidgetTester tester, String key) =>
    tester.widget<ButtonStyleButton>(find.byKey(ValueKey(key)));

GoogleSubmission _row(int id, SubmissionImportState state) => GoogleSubmission(
  id: id,
  googleSubmissionId: 'Cg$id',
  state: state,
  student: SubmissionStudent(id: 4500 + id, name: 'นักเรียน $id'),
  attachments: const [
    GoogleAttachment(
      driveFileId: 'x',
      title: 'IMG.jpg',
      mimeType: 'image/jpeg',
    ),
  ],
);

/// DESIGN §18.7: the submissions list with states, download-and-scan (all
/// or one), the result of every picture and "ตีกลับให้ถ่ายใหม่".
void main() {
  testWidgets('lists every submission with its state', (tester) async {
    await _pump(tester);

    expect(find.text('งานที่ส่ง: เศษส่วน'), findsOneWidget);
    expect(find.text('ส่งใน Classroom 3 คน'), findsOneWidget);
    expect(
      find.text('ส่งแล้ว รอสแกน 1 · ต้องถ่ายใหม่ 1 · ส่งคะแนนกลับไม่สำเร็จ 1'),
      findsOneWidget,
    );
    expect(find.text('ด.ญ. สมหญิง (เลขที่ 12)'), findsOneWidget);
    expect(find.text('ยังไม่ได้จับคู่นักเรียน'), findsOneWidget);
    expect(find.text('เหตุผลที่ตีกลับ: เงาบัง QR'), findsOneWidget);
    expect(find.textContaining('ProjectPermissionDenied'), findsOneWidget);
    expect(find.text('ดาวน์โหลดและสแกนทั้งหมด (2)'), findsOneWidget);
    expect(find.text('ส่งคะแนนกลับอีกครั้ง (1)'), findsOneWidget);
    expect(_button(tester, 'scan_31').onPressed, isNotNull);
    expect(
      _button(tester, 'scan_32').onPressed,
      isNull,
      reason: 'grade_failed work was already graded and published',
    );
  });

  testWidgets('download-and-scan all shows the result of each picture', (
    tester,
  ) async {
    final (_, importer) = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('import_all')));
    await tester.pumpAndSettle();

    expect(importer.imported, [
      31,
      33,
    ], reason: 'only rows waiting to be scanned');
    expect(importer.emails, ['kru@school.ac.th']);
    expect(
      find.text('ผ่าน: ด.ญ. สมหญิง (เลขที่ 12) หน้า 1 (อยู่ในคิวอัปโหลด)'),
      findsOneWidget,
    );
    expect(
      find.textContaining('ต้องถ่ายใหม่: มองไม่เห็นสัญลักษณ์'),
      findsOneWidget,
    );
    expect(
      find.text('สแกนแล้ว 1 หน้า มี 2 งานที่ต้องให้นักเรียนถ่ายใหม่'),
      findsOneWidget,
    );
  });

  testWidgets('a blurry picture can be kept with one tap', (tester) async {
    final (_, importer) = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('scan_33')));
    await tester.pumpAndSettle();
    expect(importer.imported, [33]);

    await tester.tap(find.text('ภาพไม่คมแต่อ่านได้ ใช้ภาพนี้ต่อ'));
    await tester.pumpAndSettle();
    expect(
      find.text('ผ่าน: ด.ช. สมชาย (เลขที่ 13) หน้า 2 (อยู่ในคิวอัปโหลด)'),
      findsOneWidget,
    );
  });

  testWidgets('return for retake pre-fills the reasons and posts them', (
    tester,
  ) async {
    final (repo, _) = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('scan_31')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('return_31')));
    await tester.pumpAndSettle();
    final field = tester.widget<TextField>(
      find.byKey(const ValueKey('retake_reason')),
    );
    expect(field.controller!.text, contains('มุมล่างขวา'));

    await tester.enterText(
      find.byKey(const ValueKey('retake_reason')),
      'ถ่ายให้เห็นมุมล่างขวาด้วย',
    );
    await tester.tap(find.byKey(const ValueKey('retake_confirm')));
    await tester.pumpAndSettle();

    expect(repo.returns.single, (31, 'ถ่ายให้เห็นมุมล่างขวาด้วย'));
    expect(find.text('ตีกลับให้ถ่ายใหม่แล้ว'), findsOneWidget);
    expect(
      find.text('ส่งคืนงานใน Classroom และแจ้งนักเรียนแล้ว'),
      findsOneWidget,
    );
  });

  testWidgets('an empty reason is refused', (tester) async {
    final (repo, _) = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('return_33')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('retake_reason')), '  ');
    await tester.tap(find.byKey(const ValueKey('retake_confirm')));
    await tester.pumpAndSettle();
    expect(find.text('บอกนักเรียนว่าต้องถ่ายใหม่เพราะอะไร'), findsOneWidget);
    expect(repo.returns, isEmpty);
  });

  testWidgets('grade_failed rows can be sent again', (tester) async {
    final (repo, _) = await _pump(tester);
    final loads = repo.submissionLoads;
    await tester.tap(find.byKey(const ValueKey('retry_grades')));
    await tester.pumpAndSettle();
    expect(repo.retries, 1);
    expect(repo.submissionLoads, loads + 1, reason: 'the list reloads');
    expect(find.text('กำลังส่งคะแนนกลับอีกครั้ง 1 คน'), findsOneWidget);
  });

  testWidgets('on the web: no scanning, everything else works', (tester) async {
    tester.view.physicalSize = const Size(1000, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final repo = FakeGoogleRepository(submissionRows: _rows);
    await pumpScreen(
      tester,
      const GoogleSubmissionsScreen(assignmentId: 12),
      overrides: [
        googleClassroomEnabledProvider.overrideWithValue(true),
        googleClassroomRepositoryProvider.overrideWithValue(repo),
        googleAuthProvider.overrideWithValue(const DisabledGoogleAuth()),
        assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
        // What kIsWeb gives: the importer (drift, dart:io, the native
        // pipeline) is never built.
        classroomScanSupportedProvider.overrideWithValue(false),
        classroomImporterProvider.overrideWith(
          (ref) => throw StateError('the importer was built on the web'),
        ),
      ],
    );

    expect(find.byKey(const ValueKey('phone_only_note')), findsOneWidget);
    expect(
      find.textContaining('ดาวน์โหลดและสแกนงานที่ส่งต้องทำบนแอป Android'),
      findsOneWidget,
    );
    expect(_button(tester, 'import_all').onPressed, isNull);
    expect(_button(tester, 'scan_31').onPressed, isNull);
    expect(find.text('ส่งใน Classroom 3 คน'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('return_33')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('retake_reason')),
      'ถ่ายใหม่ให้เห็น QR',
    );
    await tester.tap(find.byKey(const ValueKey('retake_confirm')));
    await tester.pumpAndSettle();
    expect(repo.returns.single, (33, 'ถ่ายใหม่ให้เห็น QR'));

    await tester.tap(find.byKey(const ValueKey('retry_grades')));
    await tester.pumpAndSettle();
    expect(repo.retries, 1);

    await tester.tap(find.byTooltip('ดึงงานที่ส่งอีกครั้ง'));
    await tester.pumpAndSettle();
    expect(repo.submissionLoads, greaterThanOrEqualTo(3));
  });

  testWidgets('an expired connection says to reconnect', (tester) async {
    final repo = FakeGoogleRepository()
      ..error = DioException(
        requestOptions: RequestOptions(
          path: '/assignments/12/google-submissions',
        ),
        response: Response(
          requestOptions: RequestOptions(path: '/x'),
          statusCode: 409,
          data: {
            'message': 'invalid_grant',
            'errors': null,
            'code': 'google_reconnect_required',
          },
        ),
      );
    await _pump(tester, google: repo);
    expect(find.textContaining('กด "เชื่อมใหม่"'), findsOneWidget);
  });

  testWidgets('leaving the screen deletes pictures kept for a decision', (
    tester,
  ) async {
    final (_, importer) = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('scan_33')));
    await tester.pumpAndSettle();

    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(importer.discarded.whereType<ImageRejected>(), hasLength(1));
  });

  testWidgets('returned and graded rows cannot be scanned again', (
    tester,
  ) async {
    await _pump(
      tester,
      google: FakeGoogleRepository(
        submissionRows: [
          _row(41, SubmissionImportState.returnedForRetake),
          _row(42, SubmissionImportState.graded),
          _row(43, SubmissionImportState.imported),
          _row(44, SubmissionImportState.needsRetake),
        ],
      ),
    );
    expect(_button(tester, 'scan_41').onPressed, isNull);
    expect(_button(tester, 'scan_42').onPressed, isNull);
    expect(_button(tester, 'scan_43').onPressed, isNotNull);
    expect(_button(tester, 'scan_44').onPressed, isNotNull);
    expect(
      find.textContaining('รอนักเรียนส่งรูปใหม่ใน Classroom'),
      findsOneWidget,
    );
  });

  testWidgets('a sign-in refused part way stops the batch and frees the '
      'buttons', (tester) async {
    final (_, importer) = await _pump(tester);
    importer.results[31] = SubmissionImport(
      submissionId: 31,
      outcomes: const [
        ImageQueued(
          'IMG_1.jpg',
          clientScanId: 'scan-1',
          page: 1,
          student: 'ด.ญ. สมหญิง (เลขที่ 12)',
        ),
      ],
      interruption: const GoogleAuthCanceled().message,
      authFailure: const GoogleAuthCanceled(),
    );

    await tester.tap(find.byKey(const ValueKey('import_all')));
    await tester.pumpAndSettle();

    expect(importer.imported, [31], reason: 'row 33 is not started');
    expect(
      find.text(
        'หยุดดาวน์โหลดและสแกนแล้ว เพราะปิดหน้าลงชื่อเข้าใช้ Google '
        '(เสร็จ 0 จาก 2 งาน กดดาวน์โหลดอีกครั้งเพื่อทำต่อ)',
      ),
      findsOneWidget,
    );
    expect(
      find.text('ยังไม่เสร็จ: ${const GoogleAuthCanceled().message}'),
      findsOneWidget,
    );
    expect(
      find.text('ผ่าน: ด.ญ. สมหญิง (เลขที่ 12) หน้า 1 (อยู่ในคิวอัปโหลด)'),
      findsOneWidget,
    );
    expect(find.byType(LinearProgressIndicator), findsNothing);
    expect(_button(tester, 'import_all').onPressed, isNotNull);
    expect(_button(tester, 'scan_31').onPressed, isNotNull);
  });

  testWidgets('an unexpected error does not leave the row spinning', (
    tester,
  ) async {
    final (_, importer) = await _pump(tester);
    importer.results[33] = StateError('plugin gone');

    await tester.tap(find.byKey(const ValueKey('scan_33')));
    await tester.pumpAndSettle();

    expect(
      find.text('ยังไม่เสร็จ: ดาวน์โหลดหรือสแกนไม่สำเร็จ ลองอีกครั้ง'),
      findsOneWidget,
    );
    expect(find.byType(LinearProgressIndicator), findsNothing);
    expect(_button(tester, 'scan_33').onPressed, isNotNull);
  });

  testWidgets('unmatched spare sheets and pages already queued are explained', (
    tester,
  ) async {
    final (_, importer) = await _pump(tester);
    importer.results[33] = const SubmissionImport(
      submissionId: 33,
      outcomes: [
        ImageNeedsMatch('IMG_3.jpg'),
        ImageAlreadyQueued(
          'IMG_4.jpg',
          page: 2,
          student: 'ด.ช. สมชาย (เลขที่ 13)',
        ),
      ],
    );

    await tester.tap(find.byKey(const ValueKey('scan_33')));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('ยังสแกนไม่ได้: ใบงานสำรองไม่มีชื่อนักเรียน'),
      findsOneWidget,
    );
    expect(
      find.text(
        'ข้าม: ด.ช. สมชาย (เลขที่ 13) หน้า 2 รออยู่ในคิวอัปโหลดแล้ว ไม่ได้เพิ่มซ้ำ',
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'สแกนแล้ว 0 หน้า ข้าม 1 หน้าที่อยู่ในคิวแล้ว '
        'มี 1 งานที่ต้องจับคู่นักเรียนก่อน',
      ),
      findsOneWidget,
    );
  });
}
