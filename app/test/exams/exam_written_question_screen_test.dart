import 'package:eduvision/features/exams/exam_answer_key_screen.dart';
import 'package:eduvision/features/exams/exam_question_screen.dart';
import 'package:eduvision/features/exams/exam_screen.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/pump_screen.dart';
import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

/// An exam with the choice sections of [examJson] and the written sections
/// of [writtenSectionsJson]: q51 open (draft rubric), q52 open (blank),
/// q61 show_work (approved rubric, final answer -31).
FakeExamsRepository withWritten() => FakeExamsRepository(
  detail: examJson(
    sections: [
      ...(examJson()['sections'] as List).cast<Map<String, dynamic>>(),
      ...writtenSectionsJson(),
    ],
  ),
);

/// DESIGN §22.20.2: a written question is edited with its prompt, points,
/// lines and (show_work) final answer; its key is the rubric.
void main() {
  testWidgets('an open question has lines and a rubric, no options or key', (
    tester,
  ) async {
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 51),
      repo: withWritten(),
    );

    expect(find.byKey(const ValueKey('exam_question_prompt')), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_question_points')), findsOneWidget);
    final lines = tester.widget<TextFormField>(
      find.byKey(const ValueKey('exam_question_lines')),
    );
    expect(lines.controller!.text, '8');
    expect(find.text('rubric ยังไม่อนุมัติ'), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_question_rubric')), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_option_text_1')), findsNothing);
    expect(find.byKey(const ValueKey('exam_question_lock')), findsNothing);
    expect(find.byKey(const ValueKey('exam_question_values')), findsNothing);
    expect(find.byKey(const ValueKey('exam_question_final')), findsNothing);
    expect(find.text('เฉลย'), findsNothing);
  });

  testWidgets('a show_work question saves its lines and final answers', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 61),
      repo: withWritten(),
    );

    final fin = tester.widget<TextFormField>(
      find.byKey(const ValueKey('exam_question_final')),
    );
    expect(fin.controller!.text, '-31');
    expect(find.text('rubric อนุมัติแล้ว'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('exam_question_final')),
      ' 12 , ๑๒ ,',
    );
    await tester.enterText(
      find.byKey(const ValueKey('exam_question_lines')),
      '9',
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    await tester.pumpAndSettle();

    final call = repo.calls.firstWhere((c) => c.$1 == 'updateQuestion');
    final body = (call.$2 as Map).cast<String, Object?>();
    expect(body['id'], 61);
    expect(body['answer_lines'], 9);
    expect(body['answer_key'], {
      'final': {
        'accepted': ['12', '๑๒'],
      },
    });
    expect(body.containsKey('options'), isFalse);
    expect(body.containsKey('lock_options'), isFalse);
  });

  testWidgets('lines outside 1–15 are refused before saving', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 51),
      repo: withWritten(),
    );

    await tester.enterText(
      find.byKey(const ValueKey('exam_question_lines')),
      '16',
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    await tester.pump();

    expect(find.text('จำนวนบรรทัด 1–15'), findsOneWidget);
    expect(repo.calls.where((c) => c.$1 == 'updateQuestion'), isEmpty);
  });

  testWidgets('"ตั้ง rubric" opens the rubric screen of the homework', (
    tester,
  ) async {
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 51),
      repo: withWritten(),
    );

    await tapVisible(
      tester,
      find.byKey(const ValueKey('exam_question_rubric')),
    );
    await tester.pumpAndSettle();

    expect(
      find.text('rubric /assignments/40/questions/51/rubric'),
      findsOneWidget,
    );
  });

  testWidgets('a question not saved yet cannot open the rubric', (
    tester,
  ) async {
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, sectionId: 5),
      repo: withWritten(),
    );

    final button = tester.widget<OutlinedButton>(
      find.byKey(const ValueKey('exam_question_rubric')),
    );
    expect(button.onPressed, isNull);
    expect(find.text('บันทึกข้อก่อนแล้วจึงตั้ง rubric'), findsOneWidget);
  });

  testWidgets('a blank written question says the rubric approves it', (
    tester,
  ) async {
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 52),
      repo: withWritten(),
    );

    expect(
      find.textContaining('ตั้ง rubric แล้วข้อนี้จะอนุมัติเอง'),
      findsOneWidget,
    );
    expect(find.textContaining('ใส่เฉลย'), findsNothing);
  });

  testWidgets(
    'an approved written question with a draft rubric shows only the rubric status',
    (tester) async {
      await pumpExamScreen(
        tester,
        const ExamQuestionScreen(examId: 40, questionId: 51),
        repo: withWritten(),
      );

      expect(find.textContaining('ข้อนี้ยังไม่อนุมัติ'), findsNothing);
      expect(find.text('rubric ยังไม่อนุมัติ'), findsOneWidget);
    },
  );

  testWidgets(
    'a written question tile shows its rubric chip, not "ยังไม่มีเฉลย"',
    (tester) async {
      await pumpExamScreen(
        tester,
        const ExamScreen(examId: 40),
        repo: withWritten(),
      );

      final q51 = find.byKey(const ValueKey('exam_question_51'));
      final q61 = find.byKey(const ValueKey('exam_question_61'));
      await tester.scrollUntilVisible(q61, 300);
      expect(
        find.descendant(of: q51, matching: find.text('rubric ยังไม่อนุมัติ')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: q61, matching: find.text('rubric อนุมัติแล้ว')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: q51, matching: find.text('ยังไม่มีเฉลย')),
        findsNothing,
      );
      expect(
        find.descendant(of: q51, matching: find.textContaining('8 บรรทัด')),
        findsOneWidget,
      );
    },
  );

  testWidgets('the key table lists choice questions only', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
      repo: withWritten(),
    );

    expect(find.textContaining('มีเฉลยแล้ว 3/4 ข้อ'), findsOneWidget);
    expect(find.byKey(const ValueKey('key_chip_51_1')), findsNothing);
    expect(find.byKey(const ValueKey('key_values_61')), findsNothing);
    expect(find.text('เขียนตอบ'), findsNothing);
  });

  for (final width in [390.0, 820.0, 1440.0]) {
    testWidgets('the written question form fits ${width.toInt()} px', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      await pumpScreen(
        tester,
        const ExamQuestionScreen(examId: 40, questionId: 61),
        overrides: [
          examsRepositoryProvider.overrideWithValue(withWritten()),
          documentFilePickerProvider.overrideWithValue(FakeDocumentPicker()),
        ],
        extraRoutes: examStubRoutes,
      );

      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('exam_question_final')), findsOneWidget);
    });
  }
}
