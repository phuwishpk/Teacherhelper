import 'package:eduvision/features/exams/exam_form_screen.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../gradebook/gradebook_fakes.dart';
import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

FakeCoursesRepository _courses() => FakeCoursesRepository([
  course(id: 3),
  course(
    id: 6,
    code: 'ค15102',
    classrooms: const [
      {'id': 8, 'name': 'ป.5/2'},
    ],
  ),
]);

Future<void> _pickDate(WidgetTester tester) async {
  await tapVisible(tester, find.byKey(const ValueKey('exam_date')));
  await tester.tap(find.text('OK'));
  await tester.pumpAndSettle();
}

/// "สร้างข้อสอบ" and "ตั้งค่าข้อสอบ" (DESIGN §22.1, §22.2, §22.15).
void main() {
  testWidgets('creates a manual exam with its full marks and opens it', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamFormScreen(initialClassroomId: 7),
      overrides: overrides(_courses()),
    );

    expect(find.text('สร้างข้อสอบ'), findsWidgets);
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    expect(find.text('กรอกชื่อข้อสอบ'), findsOneWidget);
    expect(find.text('ข้อสอบต้องกำหนดวันสอบ'), findsOneWidget);
    expect(repo.args('create'), isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('exam_title')),
      'สอบกลางภาค',
    );
    await tester.tap(
      find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
    await tester.pumpAndSettle();
    // The course's gradebook is not set up: no category to pick yet.
    expect(
      find.byKey(const ValueKey('gradebook_category_unconfigured')),
      findsOneWidget,
    );
    await _pickDate(tester);
    expect(find.textContaining('วันสอบ '), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('exam_duration')), '601');
    await tapVisible(tester, find.text('ครูตรวจเอง'));
    expect(find.textContaining('ไม่ต้องอนุมัติเฉลย'), findsOneWidget);
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    expect(find.text('เวลาสอบ 1–600 นาที'), findsOneWidget);
    expect(find.text('กรอกคะแนนเต็ม (มากกว่า 0)'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('exam_duration')), '50');
    await tester.enterText(find.byKey(const ValueKey('exam_full_marks')), '40');
    await tapVisible(tester, find.text('2 ชุด'));
    expect(find.textContaining('ชุด ก–ข'), findsOneWidget);
    await tapVisible(tester, find.byKey(const ValueKey('exam_show_key')));
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));

    final body = repo.args('create').single as Map;
    expect(body['kind'], 'exam');
    expect(body['classroom_id'], 7);
    expect(body['course_id'], 3);
    expect(body['title'], 'สอบกลางภาค');
    expect(body['grading_method'], 'manual');
    expect(body['manual_full_marks'], 40.0);
    expect(body['duration_minutes'], 50);
    expect(body['version_count'], 2);
    expect(body['show_key_to_students'], isTrue);
    final due = DateTime.parse(body['due_at'] as String).toLocal();
    expect((due.hour, due.minute), (23, 59));
    expect(find.text('exam /exams/77'), findsOneWidget);
  });

  testWidgets('an app exam sends no full marks', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamFormScreen(initialClassroomId: 7),
      overrides: overrides(_courses()),
    );
    await tester.enterText(find.byKey(const ValueKey('exam_title')), 'ย่อย');
    await tester.tap(
      find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
    await tester.pumpAndSettle();
    await _pickDate(tester);
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    final body = repo.args('create').single as Map;
    expect(body['grading_method'], 'app');
    expect(body.containsKey('manual_full_marks'), isFalse);
    expect(body['duration_minutes'], isNull);
  });

  testWidgets('edits the settings of an exam; the version count is fixed '
      'once printed', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamEditScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(lockedAt: '2026-10-01T00:00:00Z'),
      ),
      overrides: overrides(_courses()),
    );

    expect(find.text('ตั้งค่าข้อสอบ'), findsOneWidget);
    expect(find.text('สอบกลางภาค'), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_classroom')), findsNothing);
    expect(
      find.textContaining('พิมพ์แล้ว เปลี่ยนจำนวนชุดต้องปลดล็อก'),
      findsOneWidget,
    );
    final versions = tester.widget<SegmentedButton<int>>(
      find.byKey(const ValueKey('exam_versions')),
    );
    expect(versions.onSelectionChanged, isNull);

    await tester.enterText(find.byKey(const ValueKey('exam_duration')), '90');
    await tapVisible(tester, find.text('ครูตรวจเอง'));
    await tester.enterText(
      find.byKey(const ValueKey('exam_full_marks')),
      '25.5',
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));

    expect(repo.args('updateSettings'), [
      {
        'duration_minutes': 90,
        'manual_full_marks': 25.5,
        'grading_method': 'manual',
      },
    ]);
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('switching a manual exam back to the app asks first', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamEditScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(method: 'manual', status: 'ready'),
      ),
      overrides: overrides(_courses()),
    );

    expect(find.byKey(const ValueKey('exam_full_marks')), findsOneWidget);
    await tapVisible(tester, find.text(ExamGradingMethod.app.label));
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    expect(find.text('เปลี่ยนเป็นตรวจด้วยแอป?'), findsOneWidget);
    await tester.tap(find.text('ยกเลิก'));
    await tester.pumpAndSettle();
    expect(repo.args('updateSettings'), isEmpty);

    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    await tester.tap(find.widgetWithText(FilledButton, 'เปลี่ยน'));
    await tester.pumpAndSettle();
    expect(repo.args('updateSettings'), [
      {'grading_method': 'app'},
    ]);
  });

  testWidgets('shows the server\'s refusal', (tester) async {
    final repo = FakeExamsRepository();
    await pumpExamScreen(
      tester,
      const ExamEditScreen(examId: 40),
      repo: repo,
      overrides: overrides(_courses()),
    );
    await tester.enterText(find.byKey(const ValueKey('exam_title')), 'ใหม่');
    repo.failNext = StateError('x');
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    expect(find.text('เกิดข้อผิดพลาดที่ไม่คาดคิด'), findsOneWidget);
  });

  testWidgets('a configured course requires the exam category', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamFormScreen(initialClassroomId: 7),
      overrides: overrides(
        _courses(),
        gradebook: FakeGradebookRepository(settings: settingsJson()),
      ),
    );
    await tester.enterText(
      find.byKey(const ValueKey('exam_title')),
      'สอบกลางภาค',
    );
    await tester.tap(
      find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
    await tester.pumpAndSettle();
    await _pickDate(tester);
    expect(find.text('ยังไม่ระบุหมวด (ไม่นับ)'), findsNothing);
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    expect(find.text('เลือกหมวดคะแนนของข้อสอบ'), findsOneWidget);
    expect(repo.args('create'), isEmpty);

    await tapVisible(
      tester,
      find.byKey(const ValueKey('gradebook_category_3')),
    );
    await tester.tap(find.text('กลางภาค (20%)').last);
    await tester.pumpAndSettle();
    await tapVisible(tester, find.byKey(const ValueKey('exam_submit')));
    final body = repo.args('create').single as Map;
    expect(body['gradebook_category_id'], 11);
    expect(body.containsKey('excluded_from_grade'), isFalse);
  });
}
