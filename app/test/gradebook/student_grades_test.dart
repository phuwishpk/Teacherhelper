import 'package:eduvision/features/gradebook/student_grades.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'gradebook_fakes.dart';

Future<FakeGradebookRepository> _pump(
  WidgetTester tester,
  Widget screen, [
  FakeGradebookRepository? repo,
]) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final r = repo ?? FakeGradebookRepository();
  await pumpScreen(
    tester,
    screen,
    overrides: gradebookOverrides(r),
    extraRoutes: [stubRoute('/student/courses/:id/grade', 'grade')],
  );
  return r;
}

/// The student's own published grades (DESIGN §23.7, §23.9, §23.12).
void main() {
  testWidgets('"เกรดของฉัน" lists published courses and opens one', (
    tester,
  ) async {
    await _pump(tester, const Scaffold(body: MyGradesPage()));
    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    expect(find.text('3'), findsOneWidget);
    expect(find.text('ร'), findsOneWidget);
    expect(find.textContaining('คะแนนรวม 74'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('my_grade_4')));
    await tester.pumpAndSettle();
    expect(find.text('grade /student/courses/4/grade'), findsOneWidget);
  });

  testWidgets('no published grade yet', (tester) async {
    final repo = FakeGradebookRepository()..myGradesBody = [];
    await _pump(tester, const Scaffold(body: MyGradesPage()), repo);
    expect(find.text('ยังไม่มีเกรดที่ประกาศ'), findsOneWidget);
  });

  testWidgets('the breakdown shows categories, items, excused and dropped', (
    tester,
  ) async {
    await _pump(tester, const StudentGradeScreen(courseId: 4));
    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    expect(find.text('เกรด 3'), findsOneWidget);
    expect(find.text('คะแนนรวม 73.8 (ปัดเป็น 74)'), findsOneWidget);
    expect(find.text('ได้ 73.33% = 22 จาก 30 คะแนน'), findsOneWidget);
    expect(find.text('ไม่มีคะแนนในหมวดนี้'), findsOneWidget);
    expect(find.textContaining('เฉลี่ย'), findsNothing);
    expect(find.textContaining('อันดับ'), findsNothing);

    await tester.tap(find.text('การบ้าน (30%)'));
    await tester.pumpAndSettle();
    expect(find.text('8/10'), findsOneWidget);
    expect(find.text('ยกเว้น'), findsOneWidget);
    expect(find.textContaining('ตัดออก'), findsOneWidget);
    final dropped = tester.widget<Text>(find.text('0/10'));
    expect(dropped.style?.decoration, TextDecoration.lineThrough);
  });

  testWidgets('a course without a published grade says so', (tester) async {
    final repo = FakeGradebookRepository()..myGradeBody = null;
    await _pump(tester, const StudentGradeScreen(courseId: 4), repo);
    expect(find.text('ยังไม่ได้ประกาศเกรด'), findsOneWidget);
  });
}
