import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/hand_in/hand_in_models.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';
import 'package:eduvision/features/hand_in/teacher_upload_screen.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/pump_screen.dart';
import 'hand_in_fakes.dart';

const _math = Assignment(
  id: 11,
  classroomId: 2,
  subjectId: 1,
  subjectName: 'คณิตศาสตร์',
  classroomName: 'ป.5/2',
  title: 'เศษส่วน',
  status: 'ready',
  submissionsCount: 3,
);

const _mathDraft = Assignment(
  id: 12,
  classroomId: 2,
  subjectId: 1,
  subjectName: 'คณิตศาสตร์',
  title: 'ใบงานยังไม่พิมพ์',
);

const _thai = Assignment(
  id: 13,
  classroomId: 3,
  subjectId: 2,
  subjectName: 'ภาษาไทย',
  classroomName: 'ป.6/1',
  title: 'เรียงความ',
  status: 'ready',
  mode: AssignmentMode.freeform,
);

const _web = Assignment(
  id: 14,
  classroomId: 2,
  subjectId: null,
  title: 'งานจากเว็บ Classroom',
  mode: AssignmentMode.freeform,
  source: 'classroom_web',
);

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.items);

  final List<Assignment> items;

  @override
  Future<List<Assignment>> list({int? classroomId}) async => items;
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 40, studentNumber: 1, name: 'ด.ช. กล้า'),
    RosterStudent(studentId: 41, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
  ];
}

class _Review extends Fake implements ReviewRepository {
  final graded = <int>[];

  @override
  Future<void> gradeSubmission(int submissionId) async =>
      graded.add(submissionId);
}

PickedDocument _file(String name) =>
    PickedDocument(name: name, bytes: Uint8List(4));

Future<_Review> _pump(
  WidgetTester tester,
  FakeHandIn handIn, {
  List<Assignment> assignments = const [_math, _mathDraft, _thai, _web],
  TeacherUploadArgs args = const TeacherUploadArgs(),
  FakeDocumentPicker? picker,
}) async {
  tester.view.physicalSize = const Size(1200, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final review = _Review();
  await pumpScreen(
    tester,
    TeacherUploadScreen(args: args),
    overrides: [
      handInRepositoryProvider.overrideWithValue(handIn),
      assignmentsRepositoryProvider.overrideWithValue(
        _Assignments(assignments),
      ),
      classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
      reviewRepositoryProvider.overrideWithValue(review),
      documentFilePickerProvider.overrideWithValue(
        picker ?? FakeDocumentPicker([_file('p1.jpg'), _file('p2.pdf')]),
      ),
    ],
    extraRoutes: [
      GoRoute(
        path: '/assignments/:id/review',
        builder: (_, state) => Text('review ${state.pathParameters['id']}'),
      ),
    ],
  );
  return review;
}

void main() {
  testWidgets('subject -> assignment -> student -> files -> ส่งตรวจ', (
    tester,
  ) async {
    final handIn = FakeHandIn(
      handedInByAssignment: {
        11: {
          41: const HandedIn(
            studentId: 41,
            submissionId: 90,
            status: 'needs_review',
            late: true,
          ),
        },
      },
    );
    await _pump(tester, handIn);

    // Subjects of the assignments that take uploads (not a worksheet draft).
    expect(find.text('คณิตศาสตร์'), findsOneWidget);
    expect(find.text('ภาษาไทย'), findsOneWidget);
    expect(find.text('ยังไม่ระบุวิชา'), findsOneWidget);
    expect(find.text('2. เลือกการบ้าน'), findsNothing);

    await tester.tap(find.text('คณิตศาสตร์'));
    await tester.pumpAndSettle();
    expect(find.text('เศษส่วน'), findsOneWidget);
    expect(find.text('ป.5/2 · ส่งแล้ว 3 คน'), findsOneWidget);
    expect(find.text('ใบงานยังไม่พิมพ์'), findsNothing);
    expect(find.text('เรียงความ'), findsNothing);

    await tester.tap(find.text('เศษส่วน'));
    await tester.pumpAndSettle();
    expect(find.text('3. เลือกนักเรียน'), findsOneWidget);
    expect(find.text('ส่งแล้ว 1 จาก 2 คน'), findsOneWidget);
    final somying = find.byKey(const ValueKey('upload_student_41'));
    expect(
      find.descendant(of: somying, matching: find.text('ส่งแล้ว')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: somying, matching: find.text('ส่งช้า')),
      findsOneWidget,
    );

    await tester.enterText(
      find.byKey(const ValueKey('upload_student_search')),
      '12',
    );
    await tester.pumpAndSettle();
    expect(find.text('ด.ช. กล้า'), findsNothing);
    await tester.enterText(
      find.byKey(const ValueKey('upload_student_search')),
      'ไม่มีคนนี้',
    );
    await tester.pumpAndSettle();
    expect(find.text('ไม่พบนักเรียนที่ตรงกับคำค้น'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('upload_student_search')),
      'สมหญิง',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ด.ญ. สมหญิง'));
    await tester.pumpAndSettle();

    final send = find.byKey(const ValueKey('upload_send'));
    expect(tester.widget<ButtonStyleButton>(send).onPressed, isNull);
    // file_picker only on this page: no camera button.
    expect(find.byKey(const ValueKey('hand_in_camera')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('hand_in_pick')));
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pumpAndSettle();

    final (assignmentId, studentId, files) = handIn.uploaded.single;
    expect(assignmentId, 11);
    expect(studentId, 41);
    expect(files.map((f) => f.name), ['p1.jpg', 'p2.pdf']);
    expect(find.text('ส่งตรวจแล้ว'), findsOneWidget);

    await tester.tap(find.text('ส่งงานนักเรียนคนต่อไป'));
    await tester.pumpAndSettle();
    // The assignment stays; the next student is picked.
    expect(find.text('3. เลือกนักเรียน'), findsOneWidget);
    expect(find.byKey(const ValueKey('upload_student_search')), findsOneWidget);
    expect(find.byKey(const ValueKey('upload_send')), findsNothing);
  });

  testWidgets('a regraded student waits for "ตรวจงานใหม่"', (tester) async {
    final handIn = FakeHandIn()
      ..uploadResult = const TeacherUploadResult(
        submissionId: 90,
        studentId: 40,
        pages: 2,
        regradePending: true,
      );
    final review = await _pump(
      tester,
      handIn,
      args: TeacherUploadArgs(assignmentId: 11, files: [_file('scan.jpg')]),
    );

    // Opened from the assignment: subject and assignment are already set.
    expect(find.text('เปลี่ยน'), findsOneWidget);
    await tester.tap(find.text('ด.ช. กล้า'));
    await tester.pumpAndSettle();
    expect(find.textContaining('scan.jpg'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('upload_send')));
    await tester.pumpAndSettle();

    expect(find.text('รับงานใหม่แล้ว รอครูกดตรวจ'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('upload_grade_now')));
    await tester.pumpAndSettle();
    expect(review.graded, [90]);
    expect(find.text('ส่งตรวจแล้ว'), findsOneWidget);

    await tester.tap(find.text('ไปหน้าตรวจทาน'));
    await tester.pumpAndSettle();
    expect(find.text('review 11'), findsOneWidget);
  });

  testWidgets('a key still pending keeps the work, errors are shown', (
    tester,
  ) async {
    final handIn = FakeHandIn()
      ..error = apiError(422, 'ไฟล์ใหญ่เกินไป', 'file_too_large')
      ..uploadResult = const TeacherUploadResult(
        submissionId: 91,
        studentId: 40,
        waitingKey: true,
      );
    await _pump(
      tester,
      handIn,
      args: const TeacherUploadArgs(assignmentId: 14),
    );

    expect(find.textContaining('รออนุมัติเฉลย'), findsOneWidget);
    await tester.tap(find.text('ด.ช. กล้า'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('hand_in_pick')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('upload_send')));
    await tester.pumpAndSettle();
    expect(find.text('ไฟล์ใหญ่เกินไป'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('upload_send')));
    await tester.pumpAndSettle();
    expect(find.text('เก็บงานไว้แล้ว'), findsOneWidget);
  });

  testWidgets('changing the assignment goes back to the list', (tester) async {
    await _pump(tester, FakeHandIn(), assignments: const [_math]);
    // One subject only: it is picked already.
    expect(find.text('เศษส่วน'), findsOneWidget);
    await tester.tap(find.text('เศษส่วน'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('เปลี่ยน'));
    await tester.pumpAndSettle();
    expect(find.text('3. เลือกนักเรียน'), findsNothing);
  });

  testWidgets('no assignment takes uploads yet', (tester) async {
    await _pump(tester, FakeHandIn(), assignments: const [_mathDraft]);
    expect(find.text('ยังไม่มีการบ้านที่รับงานได้'), findsOneWidget);
  });
}
