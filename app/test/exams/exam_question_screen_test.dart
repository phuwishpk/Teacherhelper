import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/exams/exam_question_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart';
import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

PickedDocument _png([String name = 'fig.png']) =>
    PickedDocument(name: name, bytes: FakeExamsRepository.pixel);

/// Picks an image through the bottom sheet's "เลือกรูปจากเครื่อง".
Future<void> _pickFromDevice(WidgetTester tester, String fieldKey) async {
  await tapVisible(tester, find.byKey(ValueKey('${fieldKey}_pick')));
  await tester.tap(find.text('เลือกรูปจากเครื่อง'));
  await tester.pumpAndSettle();
}

/// The question form (DESIGN §22.2–§22.5).
void main() {
  testWidgets('edits an mcq question: texts, several accepted options, '
      'points and the suggested option lock', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 11),
    );

    expect(find.text('ข้อ 1'), findsOneWidget);
    expect(find.text('2 + 2 เท่ากับเท่าใด'), findsOneWidget);
    // Option ง reads "ถูกทุกข้อ": the lock is suggested (§22.5).
    expect(find.byKey(const ValueKey('exam_lock_suggestion')), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('exam_option_text_4')),
      '5',
    );
    await tester.pump();
    expect(find.byKey(const ValueKey('exam_lock_suggestion')), findsNothing);
    await tester.enterText(
      find.byKey(const ValueKey('exam_option_text_4')),
      'ทั้ง ก และ ข',
    );
    await tester.pump();
    await tapVisible(tester, find.text('ห้ามสลับ'));
    expect(find.byKey(const ValueKey('exam_lock_suggestion')), findsNothing);
    final lock = tester.widget<SwitchListTile>(
      find.byKey(const ValueKey('exam_question_lock')),
    );
    expect(lock.value, isTrue);

    await tapVisible(tester, find.byKey(const ValueKey('exam_key_choice_1')));
    await tester.enterText(
      find.byKey(const ValueKey('exam_question_points')),
      '2',
    );
    expect(find.byKey(const ValueKey('exam_question_approve')), findsNothing);
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));

    expect(repo.args('updateQuestion'), [
      {
        'id': 11,
        'prompt_text': '2 + 2 เท่ากับเท่าใด',
        'options': [
          {'text': '2'},
          {'text': '3'},
          {'text': '4'},
          {'text': 'ทั้ง ก และ ข'},
        ],
        'max_points': 2.0,
        'answer_key': {
          'accepted_options': [1, 3],
        },
        'lock_options': true,
      },
    ]);
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('fills in a blank question and approves it', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 12),
    );

    expect(find.textContaining('ข้อว่าง ยังไม่ได้กรอก'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('exam_question_prompt')),
      'ข้อใดเป็นจำนวนคู่',
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_key_choice_2')));
    await tapVisible(
      tester,
      find.byKey(const ValueKey('exam_question_approve')),
    );

    final body = repo.args('updateQuestion').single as Map;
    expect(body['approve'], isTrue);
    expect(body['prompt_text'], 'ข้อใดเป็นจำนวนคู่');
    expect(body['answer_key'], {
      'accepted_options': [2],
    });
    expect(find.text('บันทึกและอนุมัติข้อนี้แล้ว'), findsOneWidget);
  });

  testWidgets('a true/false key takes one answer', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 21),
    );

    expect(find.byKey(const ValueKey('exam_option_text_1')), findsNothing);
    expect(find.byKey(const ValueKey('exam_question_lock')), findsNothing);
    await tapVisible(tester, find.byKey(const ValueKey('exam_key_choice_1')));
    final wrong = tester.widget<FilterChip>(
      find.byKey(const ValueKey('exam_key_choice_2')),
    );
    expect(wrong.selected, isFalse);
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    final body = repo.args('updateQuestion').single as Map;
    expect(body['answer_key'], {
      'accepted_options': [1],
    });
    expect(body.containsKey('options'), isFalse);
  });

  testWidgets('a numeric key must fit the digit block', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 31),
    );

    expect(find.text('0.5'), findsOneWidget);
    expect(find.text('ช่องตัวเลข 2 หลัก · มีทศนิยม'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('exam_question_values')),
      '0.5, 12.5',
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    expect(find.textContaining('ยาวเกินช่องตัวเลข'), findsOneWidget);
    expect(repo.args('updateQuestion'), isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('exam_question_values')),
      '.50, 1.5',
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    expect((repo.args('updateQuestion').single as Map)['answer_key'], {
      'accepted_values': ['0.5', '1.5'],
    });
  });

  testWidgets('a saved question uploads and removes images right away', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 11),
      repo: FakeExamsRepository(detail: examJson(q11Image: true)),
      picker: FakeDocumentPicker([_png('ภาพโจทย์.png')]),
    );

    // The saved prompt image is loaded through the API.
    expect(repo.args('image'), contains((option: false, id: 11)));
    expect(find.byType(Image), findsWidgets);

    await _pickFromDevice(tester, 'exam_option_image_2');
    expect(repo.args('uploadOptionImage'), [(112, 'ภาพโจทย์.png')]);
    expect(find.text('อัปโหลดภาพแล้ว'), findsOneWidget);

    await tapVisible(
      tester,
      find.byKey(const ValueKey('exam_prompt_image_remove')),
    );
    expect(repo.args('deleteQuestionImage'), [11]);
  });

  testWidgets('refuses a PDF as a question image', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 11),
      picker: FakeDocumentPicker([
        PickedDocument(name: 'exam.pdf', bytes: Uint8List(4)),
      ]),
    );

    await _pickFromDevice(tester, 'exam_prompt_image');
    expect(find.text('ใช้ได้เฉพาะรูป JPG, PNG หรือ WebP'), findsOneWidget);
    expect(repo.args('uploadQuestionImage'), isEmpty);
  });

  testWidgets('a new question keeps its images until the first save', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, sectionId: 1),
      picker: FakeDocumentPicker([_png()]),
    );

    expect(find.text('เพิ่มข้อ'), findsWidgets);
    await tester.enterText(
      find.byKey(const ValueKey('exam_question_prompt')),
      'รูปใดเป็นสามเหลี่ยม',
    );
    await _pickFromDevice(tester, 'exam_prompt_image');
    await _pickFromDevice(tester, 'exam_option_image_3');
    expect(find.text('ภาพใหม่ จะอัปโหลดเมื่อบันทึก'), findsNWidgets(2));
    expect(repo.args('uploadQuestionImage'), isEmpty);
    await tapVisible(tester, find.byKey(const ValueKey('exam_key_choice_3')));
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));

    final body = repo.args('addQuestion').single as Map;
    expect(body['section'], 1);
    expect(body['prompt_text'], 'รูปใดเป็นสามเหลี่ยม');
    expect(body['options'], hasLength(4));
    expect(body['lock_options'], isFalse);
    expect(repo.args('uploadQuestionImage'), [(99, 'fig.png')]);
    expect(repo.args('uploadOptionImage'), [(993, 'fig.png')]);
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('deletes a question after confirming', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 21),
    );

    await tester.tap(find.byKey(const ValueKey('exam_question_delete')));
    await tester.pumpAndSettle();
    expect(find.text('ลบข้อ 3?'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'ลบ'));
    await tester.pumpAndSettle();
    expect(repo.args('deleteQuestion'), [21]);
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('once printed the option lock and delete are fixed', (
    tester,
  ) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 11),
      repo: FakeExamsRepository(
        detail: examJson(lockedAt: '2026-10-01T00:00:00Z', q11Suggested: true),
      ),
    );

    expect(find.byKey(const ValueKey('exam_question_delete')), findsNothing);
    final lock = tester.widget<SwitchListTile>(
      find.byKey(const ValueKey('exam_question_lock')),
    );
    expect(lock.onChanged, isNull);
    expect(find.byKey(const ValueKey('exam_lock_suggestion')), findsNothing);
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    final body = repo.args('updateQuestion').single as Map;
    expect(body.containsKey('lock_options'), isFalse);
  });

  testWidgets('shows the server error and stays open', (tester) async {
    final repo = FakeExamsRepository()..failNext = StateError('boom');
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 21),
      repo: repo,
    );
    await tapVisible(tester, find.byKey(const ValueKey('exam_question_save')));
    expect(find.text('เกิดข้อผิดพลาดที่ไม่คาดคิด'), findsOneWidget);
    expect(find.text('ข้อ 3'), findsOneWidget);
  });

  testWidgets('an unknown question says so', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 999),
    );
    expect(find.text('ไม่พบข้อนี้'), findsOneWidget);
  });
}
