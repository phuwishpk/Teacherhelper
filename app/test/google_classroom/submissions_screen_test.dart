import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/google_classroom/submissions_screen.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';
import 'google_fakes.dart';

class _Assignments extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async =>
      Assignment(id: id, classroomId: 7, subjectId: 1, title: 'เศษส่วน');
}

/// A review repository whose queue cannot be read.
class _BrokenQueue extends FakeReviewRepository {
  @override
  Future<ReviewQueue> queue(int assignmentId) async =>
      throw apiError(500, {'message': 'ล่ม', 'code': 'server_error'});
}

const _jpg = [
  GoogleAttachment(
    driveFileId: 'a',
    title: 'IMG_1.jpg',
    mimeType: 'image/jpeg',
  ),
];

SubmissionStudent _student(int id, int number) =>
    SubmissionStudent(id: id, name: 'นักเรียน $number', studentNumber: number);

final _rows = [
  GoogleSubmission(
    id: 31,
    googleSubmissionId: 'Cg31',
    state: SubmissionImportState.imported,
    student: _student(4567, 12),
    attachments: _jpg,
    alternateLink: 'https://classroom.google.com/s/31',
    late: true,
  ),
  GoogleSubmission(
    id: 32,
    googleSubmissionId: 'Cg32',
    state: SubmissionImportState.gradeFailed,
    student: _student(4568, 13),
    attachments: const [
      GoogleAttachment(
        driveFileId: 'c',
        title: 'scan.pdf',
        mimeType: 'application/pdf',
      ),
    ],
    lastError: 'ProjectPermissionDenied',
  ),
  const GoogleSubmission(
    id: 33,
    googleSubmissionId: 'Cg33',
    state: SubmissionImportState.newSubmission,
    attachments: _jpg,
  ),
  GoogleSubmission(
    id: 34,
    googleSubmissionId: 'Cg34',
    state: SubmissionImportState.unsupported,
    student: _student(4569, 14),
    attachments: const [
      GoogleAttachment(
        driveFileId: 'd',
        title: 'งาน.docx',
        mimeType:
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      ),
    ],
    lastError: 'ไฟล์ Word ตรวจไม่ได้',
  ),
  GoogleSubmission(
    id: 35,
    googleSubmissionId: 'Cg35',
    state: SubmissionImportState.waitingKey,
    student: _student(4570, 15),
    attachments: _jpg,
  ),
  GoogleSubmission(
    id: 36,
    googleSubmissionId: 'Cg36',
    state: SubmissionImportState.imported,
    student: _student(4571, 16),
    attachments: _jpg,
  ),
  GoogleSubmission(
    id: 37,
    googleSubmissionId: 'Cg37',
    state: SubmissionImportState.rejectedLate,
    student: _student(4572, 17),
    attachments: _jpg,
    late: true,
  ),
  GoogleSubmission(
    id: 38,
    googleSubmissionId: 'Cg38',
    state: SubmissionImportState.newSubmission,
    student: _student(4573, 18),
    attachments: _jpg,
    lastError: 'ต้องเชื่อมบัญชี Google ใหม่',
  ),
];

Map<String, dynamic> _summary(
  int id,
  int studentId,
  String status, {
  bool regradePending = false,
  int responses = 3,
  int reviewed = 1,
  double? total,
}) => {
  'id': id,
  'status': status,
  'student': {'id': studentId, 'name': 'x', 'student_number': null},
  'channel': 'classroom',
  'late': false,
  'regrade_pending': regradePending,
  'response_count': responses,
  'reviewed_count': reviewed,
  'total_score': total,
};

Map<String, dynamic> _meta() => {
  'submissions': [
    _summary(70, 4567, 'needs_review'),
    _summary(71, 4568, 'published', total: 8, reviewed: 3),
    _summary(72, 4571, 'published', regradePending: true, total: 5),
  ],
};

Future<(FakeGoogleRepository, FakeReviewRepository)> _pump(
  WidgetTester tester, {
  FakeGoogleRepository? google,
  FakeReviewRepository? review,
}) async {
  tester.view.physicalSize = const Size(1000, 4000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final repo = google ?? FakeGoogleRepository(submissionRows: _rows);
  final reviews = review ?? FakeReviewRepository(meta: _meta());
  await pumpScreen(
    tester,
    const GoogleSubmissionsScreen(assignmentId: 12),
    overrides: [
      googleClassroomEnabledProvider.overrideWithValue(true),
      googleClassroomRepositoryProvider.overrideWithValue(repo),
      googleAuthProvider.overrideWithValue(FakeGoogleAuth()),
      assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
      reviewRepositoryProvider.overrideWithValue(reviews),
    ],
    extraRoutes: [
      GoRoute(
        path: '/assignments/:id/review',
        builder: (_, state) =>
            Scaffold(body: Text('review-${state.pathParameters['id']}')),
      ),
    ],
  );
  return (repo, reviews);
}

String _progress(WidgetTester tester, int row) {
  final chip = find.byKey(ValueKey('progress_$row'));
  return tester
      .widgetList<Text>(find.descendant(of: chip, matching: find.byType(Text)))
      .map((t) => t.data)
      .join();
}

/// DESIGN §19.4: the server downloads and grades Classroom hand-ins; the
/// screen shows the sync state and the grading state of every row.
void main() {
  testWidgets('shows the sync and grading state of every hand-in', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('งานที่ส่ง: เศษส่วน'), findsOneWidget);
    expect(find.text('ส่งใน Classroom 8 คน'), findsOneWidget);
    expect(
      find.text(
        'รอดาวน์โหลด 2 · รับไฟล์แล้ว 2 · รออนุมัติเฉลย 1 · ไฟล์ใช้ไม่ได้ 1 · '
        'ส่งช้า ไม่รับ 1 · ส่งคะแนนกลับไม่สำเร็จ 1 · ส่งช้า 2',
      ),
      findsOneWidget,
    );
    // Nothing is downloaded or scanned on the phone any more.
    expect(find.textContaining('สแกนทั้งหมด'), findsNothing);
    expect(find.textContaining('ไม่ต้องสแกนในเครื่องนี้'), findsOneWidget);

    expect(_progress(tester, 31), 'รอครูตรวจทาน');
    expect(find.text('AI ตรวจแล้ว ตรวจทานแล้ว 1/3 ข้อ'), findsOneWidget);
    expect(_progress(tester, 32), 'เผยแพร่ผลแล้ว');
    expect(find.text('คะแนนรวม 8'), findsOneWidget);
    expect(_progress(tester, 33), 'ยังไม่ได้จับคู่นักเรียน');
    expect(_progress(tester, 34), 'ตรวจไม่ได้');
    expect(
      find.textContaining('ใช้ไม่ได้: ไฟล์ Word ตรวจไม่ได้'),
      findsOneWidget,
    );
    expect(_progress(tester, 35), 'รออนุมัติเฉลย');
    expect(_progress(tester, 36), 'ส่งใหม่ รอครูกดตรวจ');
    expect(_progress(tester, 37), 'ไม่รับงานส่งช้า');
    expect(_progress(tester, 38), 'รอดาวน์โหลด');
    expect(
      find.text('ยังดาวน์โหลดไม่ได้: ต้องเชื่อมบัญชี Google ใหม่'),
      findsOneWidget,
    );
    expect(find.textContaining('ProjectPermissionDenied'), findsOneWidget);
    expect(find.text('ส่งคะแนนกลับอีกครั้ง (1)'), findsOneWidget);
    expect(find.text('มี 1 คนส่งงานใหม่ รอครูกด "ตรวจ"'), findsOneWidget);

    // "ตรวจ" only for the new hand-in that waits for the teacher.
    expect(find.byKey(const ValueKey('grade_36')), findsOneWidget);
    expect(find.byKey(const ValueKey('grade_31')), findsNothing);
    // Returnable states follow the server (new, imported, needs_retake).
    expect(find.byKey(const ValueKey('return_31')), findsOneWidget);
    expect(find.byKey(const ValueKey('return_33')), findsOneWidget);
    expect(find.byKey(const ValueKey('return_32')), findsNothing);
    expect(find.byKey(const ValueKey('return_34')), findsNothing);
  });

  testWidgets('"ตรวจ" grades the new hand-in and shows it grading', (
    tester,
  ) async {
    final (_, reviews) = await _pump(tester);
    final calls = reviews.queueCalls;

    await tester.tap(find.byKey(const ValueKey('grade_36')));
    await tester.pumpAndSettle();

    expect(reviews.graded, [72]);
    expect(reviews.queueCalls, greaterThan(calls), reason: 'queue reloads');
    expect(_progress(tester, 36), 'AI กำลังตรวจ');
    expect(find.byKey(const ValueKey('grade_36')), findsNothing);
    expect(
      find.text(
        'กำลังตรวจงานของ นักเรียน 16 (เลขที่ 16) ดึงรายการใหม่อีกครั้งในไม่กี่นาที',
      ),
      findsOneWidget,
    );
  });

  testWidgets('nothing to grade any more is explained', (tester) async {
    final reviews = FakeReviewRepository(meta: _meta())
      ..gradeError = apiError(409, {
        'message': 'ไม่มีงาน',
        'errors': null,
        'code': 'nothing_to_grade',
      });
    await _pump(tester, review: reviews);
    await tester.tap(find.byKey(const ValueKey('grade_36')));
    await tester.pumpAndSettle();
    expect(find.text('ไม่มีงานที่ส่งใหม่รอตรวจแล้ว'), findsOneWidget);
  });

  testWidgets('without the review queue the rows still show', (tester) async {
    await _pump(tester, review: _BrokenQueue());
    expect(_progress(tester, 31), 'รับไฟล์แล้ว');
    expect(find.text('กำลังเริ่มตรวจ'), findsWidgets);
    expect(find.byKey(const ValueKey('grade_36')), findsNothing);
  });

  testWidgets('return for retake posts the reason', (tester) async {
    final (repo, _) = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('return_31')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('retake_reason')),
      'ถ่ายให้เห็นทั้งหน้า',
    );
    await tester.tap(find.byKey(const ValueKey('retake_confirm')));
    await tester.pumpAndSettle();

    expect(repo.returns.single, (31, 'ถ่ายให้เห็นทั้งหน้า'));
    expect(find.text('ตีกลับให้ถ่ายใหม่แล้ว'), findsOneWidget);
    expect(_progress(tester, 31), 'รอส่งใหม่');
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

  testWidgets('refresh syncs again and reloads the grading state', (
    tester,
  ) async {
    final (repo, reviews) = await _pump(tester);
    final loads = repo.submissionLoads;
    final queues = reviews.queueCalls;
    await tester.tap(find.byTooltip('ดึงงานที่ส่งอีกครั้ง'));
    await tester.pumpAndSettle();
    expect(repo.submissionLoads, loads + 1);
    expect(reviews.queueCalls, greaterThan(queues));
  });

  testWidgets('"ตรวจทาน" opens the review queue', (tester) async {
    await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('open_review')));
    await tester.pumpAndSettle();
    expect(find.text('review-12'), findsOneWidget);
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

  testWidgets('no hand-ins yet', (tester) async {
    await _pump(tester, google: FakeGoogleRepository());
    expect(
      find.text('ยังไม่มีนักเรียนส่งงานใน Classroom ที่มีไฟล์แนบ'),
      findsOneWidget,
    );
  });

  group('rowProgress', () {
    final scheme = ColorScheme.fromSeed(seedColor: Colors.teal);
    GoogleSubmission row(SubmissionImportState state) => GoogleSubmission(
      id: 1,
      googleSubmissionId: 'x',
      state: state,
      student: _student(1, 1),
    );
    SubmissionSummary summary(String status) => SubmissionSummary.fromJson(
      _summary(9, 1, status, responses: 4, reviewed: 4),
    );

    test('follows the submission once the server holds the files', () {
      final graded = row(SubmissionImportState.graded);
      expect(
        rowProgress(graded, summary('grading'), scheme).label,
        'AI กำลังตรวจ',
      );
      expect(
        rowProgress(graded, summary('reviewed'), scheme).label,
        'ตรวจทานครบ รอเผยแพร่',
      );
      expect(
        rowProgress(graded, summary('awaiting_scan'), scheme).label,
        'รอตรวจ',
      );
      expect(
        rowProgress(graded, summary('published'), scheme).detail,
        isNull,
        reason: 'no total yet',
      );
    });

    test('legacy needs_retake rows ask for a new photo', () {
      final p = rowProgress(
        row(SubmissionImportState.needsRetake),
        null,
        scheme,
      );
      expect(p.label, 'ต้องถ่ายใหม่');
    });
  });

  group('GoogleSubmission.fromJson', () {
    test('reads the Phase 8 states and the late flag', () {
      final s = GoogleSubmission.fromJson({
        'id': 5,
        'google_submission_id': 'Cg5',
        'state': 'unsupported',
        'student': null,
        'late': true,
        'attachments': <Object>[],
        'last_error': 'ไฟล์ใหญ่เกิน 10 MB',
        'updated_at': '2026-09-30T01:00:00Z',
      });
      expect(s.state, SubmissionImportState.unsupported);
      expect(s.late, isTrue);
      expect(s.lastError, 'ไฟล์ใหญ่เกิน 10 MB');
      expect(s.updatedAt, DateTime.utc(2026, 9, 30, 1));
      expect(
        SubmissionImportState.fromApi('waiting_key'),
        SubmissionImportState.waitingKey,
      );
      expect(
        SubmissionImportState.fromApi('rejected_late'),
        SubmissionImportState.rejectedLate,
      );
      expect(
        SubmissionImportState.fromApi('???'),
        SubmissionImportState.newSubmission,
      );
    });
  });
}
