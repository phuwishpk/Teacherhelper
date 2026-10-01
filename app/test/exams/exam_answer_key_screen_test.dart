import 'package:dio/dio.dart';
import 'package:eduvision/features/exams/exam_answer_key_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

DioException _unprocessable(Map<String, List<String>> errors) {
  final options = RequestOptions(path: '/exams/40/answer-key');
  return DioException(
    requestOptions: options,
    response: Response(
      requestOptions: options,
      statusCode: 422,
      data: {
        'message': 'ข้อมูลไม่ถูกต้อง',
        'code': 'validation_failed',
        'errors': errors,
      },
    ),
  );
}

/// "ตารางเฉลย" (DESIGN §22.3): one row per question, only changed rows
/// are sent, errors come back per row, then the approval.
void main() {
  testWidgets('saves only the changed rows', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
    );

    expect(find.textContaining('มีเฉลยแล้ว 3/4 ข้อ'), findsOneWidget);
    expect(find.text('บันทึกแล้ว'), findsOneWidget);
    // q11: ค -> ง (two taps), q12: ข, q21: ผิด -> ถูก (single answer).
    await tester.tap(find.byKey(const ValueKey('key_chip_11_4')));
    await tester.tap(find.byKey(const ValueKey('key_chip_11_3')));
    await tester.tap(find.byKey(const ValueKey('key_chip_12_2')));
    await tester.tap(find.byKey(const ValueKey('key_chip_21_1')));
    // q31: the same value written differently is no change.
    await tester.enterText(find.byKey(const ValueKey('key_values_31')), '.50');
    await tester.pump();
    expect(find.text('บันทึก (3 ข้อ)'), findsOneWidget);
    final approve = tester.widget<OutlinedButton>(
      find.byKey(const ValueKey('key_grid_approve')),
    );
    expect(approve.onPressed, isNull);

    await tester.tap(find.byKey(const ValueKey('key_grid_save')));
    await tester.pumpAndSettle();
    expect(repo.args('saveAnswerKey').single, [
      {
        'question_id': 11,
        'key': {
          'accepted_options': [4],
        },
      },
      {
        'question_id': 12,
        'key': {
          'accepted_options': [2],
        },
      },
      {
        'question_id': 21,
        'key': {
          'accepted_options': [1],
        },
      },
    ]);
    expect(find.text('บันทึกเฉลย 3 ข้อแล้ว'), findsOneWidget);
  });

  testWidgets('clearing a key sends an empty list', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
    );
    await tester.enterText(find.byKey(const ValueKey('key_values_31')), '');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('key_grid_save')));
    await tester.pumpAndSettle();
    expect(repo.args('saveAnswerKey').single, [
      {'question_id': 31, 'key': null},
    ]);
  });

  testWidgets('a number that does not fit is caught before sending', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
    );
    await tester.enterText(find.byKey(const ValueKey('key_values_31')), '-1');
    await tester.pump();
    expect(find.textContaining('ไม่มีช่องเครื่องหมายลบ'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('key_grid_save')));
    await tester.pumpAndSettle();
    expect(find.text('แก้ค่าที่ไม่ถูกต้อง 1 ข้อก่อนบันทึก'), findsOneWidget);
    expect(repo.args('saveAnswerKey'), isEmpty);
  });

  testWidgets('a 422 shows the message on the row it names', (tester) async {
    final repo = FakeExamsRepository();
    await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
      repo: repo,
    );
    await tester.tap(find.byKey(const ValueKey('key_chip_12_1')));
    await tester.enterText(find.byKey(const ValueKey('key_values_31')), '0.25');
    await tester.pump();
    repo.failNext = _unprocessable({
      'answers.1.accepted_values.0': ['ค่า 0.25 ยาวเกินช่องตัวเลข'],
    });
    await tester.tap(find.byKey(const ValueKey('key_grid_save')));
    await tester.pumpAndSettle();

    expect(find.text('ค่า 0.25 ยาวเกินช่องตัวเลข'), findsOneWidget);
    expect(find.text('ข้อมูลไม่ถูกต้อง'), findsOneWidget);
    // Typing again clears the server's message of that row.
    await tester.enterText(find.byKey(const ValueKey('key_values_31')), '0.2');
    await tester.pumpAndSettle();
    expect(find.text('ค่า 0.25 ยาวเกินช่องตัวเลข'), findsNothing);
  });

  testWidgets('approves a complete, saved key', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
      repo: FakeExamsRepository(detail: examJson(keyComplete: true)),
    );
    await tester.tap(find.byKey(const ValueKey('key_grid_approve')));
    await tester.pumpAndSettle();
    expect(repo.args('approveKey'), [40]);
    expect(find.text('อนุมัติแล้ว'), findsOneWidget);
  });

  testWidgets('a manual exam has no approval button', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamAnswerKeyScreen(examId: 40),
      repo: FakeExamsRepository(detail: examJson(method: 'manual')),
    );
    expect(find.byKey(const ValueKey('key_grid_approve')), findsNothing);
  });

  testWidgets('asks before leaving with unsaved rows', (tester) async {
    await pumpExamScreen(tester, const ExamAnswerKeyScreen(examId: 40));
    await tester.tap(find.byKey(const ValueKey('key_chip_12_1')));
    await tester.pump();
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่ได้บันทึก'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'ออก'));
    await tester.pumpAndSettle();
    expect(find.text('stub-home'), findsOneWidget);
  });
}
