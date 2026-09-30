import 'package:eduvision/features/exams/exam_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

/// The exam page (DESIGN §22.2–§22.5): sections and questions, the key
/// state and its approval, the structure lock.
void main() {
  testWidgets('lists the sections and questions with their state', (
    tester,
  ) async {
    await pumpExamScreen(tester, const ExamScreen(examId: 40));

    expect(find.text('สอบกลางภาค'), findsOneWidget);
    expect(find.textContaining('ป.5/2'), findsOneWidget);
    expect(find.text('ตรวจด้วยแอป'), findsOneWidget);
    expect(find.text('4 ข้อ'), findsOneWidget);
    expect(find.text('เต็ม 5 คะแนน'), findsOneWidget);
    expect(find.text('2 ชุด (ก–ข)'), findsOneWidget);
    expect(find.text('ตอนที่ 1 ปรนัย'), findsOneWidget);
    expect(find.textContaining('ปรนัย 4 ตัวเลือก · ข้อ 1–2'), findsOneWidget);
    expect(find.text('2 + 2 เท่ากับเท่าใด'), findsOneWidget);
    expect(find.textContaining('เฉลย ค'), findsOneWidget);
    expect(find.text('ยังไม่ได้กรอก'), findsOneWidget);
    expect(find.textContaining('เฉลย ผิด'), findsOneWidget);
    expect(find.textContaining('เฉลย 0.5'), findsOneWidget);
    expect(find.text('เฉลยยังไม่ครบ 1 ข้อ'), findsOneWidget);
    expect(
      find.textContaining('ข้อ 2: ยังไม่อนุมัติ, ยังไม่มีเฉลย'),
      findsOneWidget,
    );
    final approve = tester.widget<FilledButton>(
      find.byKey(const ValueKey('exam_approve_key')),
    );
    expect(approve.onPressed, isNull, reason: 'the key is not complete');
    expect(find.byKey(const ValueKey('exam_unlock')), findsNothing);
  });

  testWidgets('adds a true/false section with blank questions', (tester) async {
    final repo = await pumpExamScreen(tester, const ExamScreen(examId: 40));

    await tester.tap(find.byKey(const ValueKey('exam_add_section')));
    await tester.pumpAndSettle();
    expect(find.text('เพิ่มตอนที่ 4'), findsOneWidget);
    await tester.tap(find.text('ถูก/ผิด').last);
    await tester.pumpAndSettle();
    expect(find.text('ข้อถูก/ผิดมี 2 วงเสมอ (ถ ผ)'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('section_title')),
      'ถูกผิด',
    );
    await tester.enterText(find.byKey(const ValueKey('section_count')), '5');
    await tapVisible(tester, find.byKey(const ValueKey('section_submit')));

    expect(repo.args('addSection'), [
      {
        'title': 'ถูกผิด',
        'instructions': null,
        'type': 'true_false',
        'default_points': 1.0,
        'question_count': 5,
      },
    ]);
    expect(find.text('เพิ่มตอนแล้ว'), findsOneWidget);
  });

  testWidgets('a numeric section takes digits, sign and decimal', (
    tester,
  ) async {
    final repo = await pumpExamScreen(tester, const ExamScreen(examId: 40));

    await tester.tap(find.byKey(const ValueKey('exam_add_section')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('เติมตัวเลข').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('section_digits_3')));
    await tapVisible(tester, find.byKey(const ValueKey('section_negative')));
    await tapVisible(tester, find.byKey(const ValueKey('section_decimal')));
    await tester.enterText(find.byKey(const ValueKey('section_count')), '101');
    await tapVisible(tester, find.byKey(const ValueKey('section_submit')));
    expect(find.text('จำนวนข้อ 0–100'), findsOneWidget);
    expect(repo.args('addSection'), isEmpty);

    await tester.enterText(find.byKey(const ValueKey('section_count')), '3');
    await tapVisible(tester, find.byKey(const ValueKey('section_submit')));
    final body = repo.args('addSection').single as Map;
    expect(body['type'], 'numeric');
    expect(body['numeric'], {
      'digits': 3,
      'allow_negative': true,
      'allow_decimal': true,
    });
    expect(body.containsKey('option_count'), isFalse);
  });

  testWidgets('edits, moves and deletes a section from its menu', (
    tester,
  ) async {
    final repo = await pumpExamScreen(tester, const ExamScreen(examId: 40));

    await tester.tap(find.byKey(const ValueKey('exam_section_menu_1')));
    await tester.pumpAndSettle();
    expect(find.text('ย้ายขึ้น'), findsNothing);
    await tester.tap(find.text('ย้ายลง'));
    await tester.pumpAndSettle();
    expect(repo.args('updateSection'), [
      {'id': 1, 'position': 2},
    ]);

    await tester.tap(find.byKey(const ValueKey('exam_section_menu_1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('แก้ไขตอน'));
    await tester.pumpAndSettle();
    expect(find.text('แก้ไขตอนที่ 1'), findsOneWidget);
    expect(find.byKey(const ValueKey('section_count')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('section_options_5')));
    await tapVisible(tester, find.byKey(const ValueKey('section_submit')));
    expect(repo.args('updateSection').last, {'id': 1, 'option_count': 5});

    await tapVisible(tester, find.byKey(const ValueKey('exam_section_menu_2')));
    expect(find.text('ย้ายขึ้น'), findsOneWidget);
    await tester.tap(find.text('ลบตอน'));
    await tester.pumpAndSettle();
    expect(find.textContaining('ข้อทั้งหมด 1 ข้อ'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'ลบ'));
    await tester.pumpAndSettle();
    expect(repo.args('deleteSection'), [2]);
  });

  testWidgets('approves a complete key', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(detail: examJson(keyComplete: true)),
    );

    expect(
      find.text('เฉลยครบทุกข้อแล้ว อนุมัติเพื่อพิมพ์และสแกน'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('exam_approve_key')));
    await tester.pumpAndSettle();
    expect(repo.args('approveKey'), [40]);
    expect(find.text('อนุมัติเฉลยแล้ว ข้อสอบพร้อมใช้'), findsOneWidget);
    expect(find.textContaining('อนุมัติเฉลยแล้ว แก้เฉลยได้'), findsOneWidget);
    expect(find.text('พร้อมใช้'), findsOneWidget);
  });

  testWidgets('a locked structure offers the unlock and hides structural '
      'actions', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(lockedAt: '2026-10-01T02:00:00+00:00'),
      ),
    );

    expect(find.byKey(const ValueKey('exam_add_section')), findsNothing);
    expect(find.byKey(const ValueKey('exam_add_question_1')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('exam_section_menu_1')));
    await tester.pumpAndSettle();
    expect(find.text('ลบตอน'), findsNothing);
    expect(find.text('ย้ายลง'), findsNothing);
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('exam_unlock')));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'ปลดล็อก'));
    await tester.pumpAndSettle();
    expect(repo.args('unlockStructure'), [40]);
    expect(find.byKey(const ValueKey('exam_add_section')), findsOneWidget);
  });

  testWidgets('a manual exam needs no key approval', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(method: 'manual', status: 'ready'),
      ),
    );

    expect(find.text('ครูตรวจเอง'), findsOneWidget);
    expect(find.textContaining('ไม่ต้องอนุมัติเฉลย'), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_approve_key')), findsNothing);
    expect(find.text('เต็ม 30 คะแนน'), findsOneWidget);
    expect(find.text('ตารางเฉลย (ไม่บังคับ)'), findsOneWidget);
  });

  testWidgets('an empty exam explains how to start', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(detail: examJson(sections: const [])),
    );
    expect(find.text('ยังไม่มีตอน'), findsOneWidget);
    final key = tester.widget<ButtonStyleButton>(
      find.byKey(const ValueKey('exam_open_key')),
    );
    expect(key.onPressed, isNull);
  });

  testWidgets('"สแกนกระดาษคำตอบ" opens once the key is approved', (
    tester,
  ) async {
    await pumpExamScreen(tester, const ExamScreen(examId: 40));
    final disabled = tester.widget<ButtonStyleButton>(
      find.byKey(const ValueKey('exam_open_scan')),
    );
    expect(disabled.onPressed, isNull);
    await unmountScreen(tester);

    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(
          status: 'ready',
          keyApprovedAt: '2026-10-01T02:00:00Z',
          keyComplete: true,
        ),
      ),
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_open_scan')));
    expect(find.text('scan /exams/40/scan'), findsOneWidget);
  });

  testWidgets('"ตรวจทานและประกาศผล" opens once the key is approved', (
    tester,
  ) async {
    await pumpExamScreen(tester, const ExamScreen(examId: 40));
    expect(find.byKey(const ValueKey('exam_open_results')), findsNothing);
    await unmountScreen(tester);

    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(
          status: 'ready',
          keyApprovedAt: '2026-10-01T02:00:00Z',
          keyComplete: true,
        ),
      ),
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_open_results')));
    expect(find.text('results /exams/40/results'), findsOneWidget);
  });

  testWidgets('an exam graded by the teacher has no scanning', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(detail: examJson(method: 'manual')),
    );
    expect(find.byKey(const ValueKey('exam_open_scan')), findsNothing);
    expect(find.byKey(const ValueKey('exam_open_results')), findsNothing);
  });

  testWidgets('opens the key grid, the versions, a question and a new one', (
    tester,
  ) async {
    await pumpExamScreen(tester, const ExamScreen(examId: 40));

    await tester.tap(find.byKey(const ValueKey('exam_open_key')));
    await tester.pumpAndSettle();
    expect(find.text('answer-key /exams/40/answer-key'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('exam_open_versions')));
    await tester.pumpAndSettle();
    expect(find.text('versions /exams/40/versions'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('exam_open_print')));
    await tester.pumpAndSettle();
    expect(find.text('print /exams/40/print'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('exam_question_11')));
    await tester.pumpAndSettle();
    expect(find.text('question /exams/40/questions/11'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tapVisible(tester, find.byKey(const ValueKey('exam_add_question_3')));
    expect(
      find.text('question-new /exams/40/sections/3/questions/new'),
      findsOneWidget,
    );
  });
}
