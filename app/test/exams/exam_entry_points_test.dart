import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_detail_screen.dart';
import 'package:eduvision/features/assignments/assignments_page.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

class _Assignments extends Fake implements AssignmentsRepository {
  final exam = ExamDetail.fromJson(examJson()).exam;

  @override
  Future<List<Assignment>> list({int? classroomId}) async => [
    exam,
    const Assignment(id: 5, classroomId: 7, subjectId: 1, title: 'การบ้าน 1'),
  ];

  @override
  Future<Assignment> get(int id) async => exam;
}

/// Where exams meet the homework screens: the list, its buttons and an
/// assignment link that turns out to be an exam.
void main() {
  testWidgets('the list marks exams and opens the exam screen', (tester) async {
    await pumpExamScreen(
      tester,
      const Scaffold(
        body: AssignmentsPage(),
        floatingActionButton: AssignmentsFab(),
      ),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
        classroomsRepositoryProvider.overrideWithValue(FakeClassrooms()),
      ],
    );

    expect(find.text('ข้อสอบ'), findsOneWidget);
    expect(find.textContaining('สอบ 15 ต.ค.'), findsOneWidget);
    expect(find.text('รออนุมัติเฉลย'), findsOneWidget);
    expect(
      find.widgetWithText(FloatingActionButton, 'สร้างการบ้าน'),
      findsOneWidget,
    );
    await tester.tap(find.text('สอบกลางภาค'));
    await tester.pumpAndSettle();
    expect(find.text('exam /exams/40'), findsOneWidget);
  });

  testWidgets('"สร้างข้อสอบ" opens the exam form', (tester) async {
    await pumpExamScreen(
      tester,
      const Scaffold(floatingActionButton: AssignmentsFab()),
      overrides: const [],
    );
    await tester.tap(find.byKey(const ValueKey('exam_new_fab')));
    await tester.pumpAndSettle();
    // The stub of '/exams/:id' catches '/exams/new' in this test router.
    expect(find.text('exam /exams/new'), findsOneWidget);
  });

  testWidgets('an assignment link to an exam shows the exam screen', (
    tester,
  ) async {
    await pumpExamScreen(
      tester,
      const AssignmentDetailScreen(assignmentId: 40),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
        classroomsRepositoryProvider.overrideWithValue(FakeClassrooms()),
      ],
    );
    expect(find.byKey(const ValueKey('exam_add_section')), findsOneWidget);
    expect(find.text('ตอนที่ 1 ปรนัย'), findsOneWidget);
  });
}
