import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/document_read_screen.dart';
import 'package:eduvision/features/exams/exam_import_models.dart';
import 'package:eduvision/features/exams/exam_import_repository.dart';
import 'package:eduvision/features/exams/exam_import_review_screen.dart';
import 'package:eduvision/features/exams/exam_question_screen.dart';
import 'package:eduvision/features/exams/exam_screen.dart';
import 'package:eduvision/platform/document_page_renderer.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart';
import 'exam_fakes.dart';
import 'exam_import_fakes.dart';
import 'exam_test_helpers.dart';

/// From the exam page: attach the exam file, see the cost with "คำแนะนำ
/// ถึง AI", send the read and land on the review (DESIGN §22.4).
/// Scrolls the exam page to the button with [key] and taps it.
Future<void> scrollTap(WidgetTester tester, String key) async {
  final finder = find.byKey(ValueKey(key));
  await tester.scrollUntilVisible(finder, 200);
  await tapVisible(tester, finder);
}

void main() {
  const pdf = SourceDocument(
    id: 501,
    originalName: 'midterm.pdf',
    mimeType: 'application/pdf',
    pageCount: 2,
    sizeBytes: 120000,
    cachedPurposes: ['exam'],
  );

  testWidgets('attach, estimate with guidance, send, review', (tester) async {
    final exams = FakeExamsRepository();
    final imports = FakeExamImportRepository(exams: exams)
      ..importResult = ExamImportResult.fromJson({
        'cached': true,
        'extraction': {'id': 70, 'status': 'done', 'guidance': 'ข้ามตอน 3'},
        'applied': {'sections': 1, 'questions': 2, 'skipped': []},
        'figures_pending': [],
      });
    final keys = FakeAnswerKeys(answerKeyState())..uploadResult = const [pdf];
    final picker = FakeDocumentPicker([
      PickedDocument(name: 'midterm.pdf', bytes: Uint8List(4)),
    ]);
    final renderer = FakePageRenderer();
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: exams,
      picker: picker,
      overrides: [
        examImportRepositoryProvider.overrideWithValue(imports),
        answerKeyRepositoryProvider.overrideWithValue(keys),
        documentPageRendererProvider.overrideWithValue(renderer),
      ],
    );

    await scrollTap(tester, 'exam_import_file');
    expect(
      find.text(
        'อย่าแนบไฟล์ที่มีชื่อหรือคำตอบของนักเรียน ผลการอ่านใช้ร่วมกันทั้งโรงเรียน',
      ),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('exam_read_file')));
    await tester.pumpAndSettle();

    expect(keys.uploads.single.single.name, 'midterm.pdf');
    expect(find.byType(DocumentReadScreen), findsOneWidget);
    expect(find.text('ส่งให้ AI อ่านข้อสอบ'), findsWidgets);
    expect(find.textContaining('เคยอ่านไฟล์นี้แล้ว'), findsOneWidget);
    expect(find.textContaining('ประมาณ 0.42 บาท'), findsOneWidget);
    expect(imports.args('estimate').single, {
      'ids': [501],
      'from': null,
      'to': null,
      'g': null,
    });

    await tester.enterText(
      find.byKey(const ValueKey('ai_guidance')),
      'ข้ามตอน 3',
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(imports.args('estimate').last, containsPair('g', 'ข้ามตอน 3'));

    exams.detailJson = importedExamJson(pending: false);
    await tapVisible(tester, find.byKey(const ValueKey('send_key_request')));
    expect(imports.args('import').single, {
      'ids': [501],
      'from': null,
      'to': null,
      'g': 'ข้ามตอน 3',
    });
    expect(find.byType(ExamImportReviewScreen), findsOneWidget);
    expect(find.textContaining('อ่านได้ 1 ตอน 2 ข้อ'), findsOneWidget);
    expect(find.byKey(const ValueKey('read_review_q_41')), findsOneWidget);
  });

  testWidgets('a cancelled pick stops quietly', (tester) async {
    final keys = FakeAnswerKeys(answerKeyState());
    final picker = FakeDocumentPicker();
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      picker: picker,
      overrides: [answerKeyRepositoryProvider.overrideWithValue(keys)],
    );
    await scrollTap(tester, 'exam_import_file');
    await tester.tap(find.byKey(const ValueKey('exam_read_file')));
    await tester.pumpAndSettle();
    expect(keys.uploads, isEmpty);
    expect(find.byType(DocumentReadScreen), findsNothing);
  });

  testWidgets('the exam page leads to the drafts and the copy', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(detail: importedExamJson()),
    );
    expect(
      find.textContaining('ข้อที่อ่านจากไฟล์ยังไม่อนุมัติ 2 ข้อ'),
      findsOneWidget,
    );
    expect(
      find.textContaining('ยังไม่มีภาพประกอบจาก 1 หน้าเอกสาร'),
      findsOneWidget,
    );
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('exam_question_41')),
      200,
    );
    expect(find.text('ร่างจากไฟล์ ยังไม่อนุมัติ'), findsNWidgets(2));
    expect(find.text('ยังไม่มีภาพประกอบ'), findsOneWidget);

    await scrollTap(tester, 'exam_copy_questions');
    expect(
      find.text('copy-questions /exams/40/copy-questions'),
      findsOneWidget,
    );
  });

  testWidgets('a locked exam takes no reads or copies', (tester) async {
    await pumpExamScreen(
      tester,
      const ExamScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(lockedAt: '2026-10-01T02:00:00+00:00'),
      ),
    );
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('exam_import_file')),
      200,
    );
    for (final key in ['exam_import_file', 'exam_copy_questions']) {
      final b = tester.widget<OutlinedButton>(find.byKey(ValueKey(key)));
      expect(b.onPressed, isNull, reason: key);
    }
    expect(find.byKey(const ValueKey('exam_open_read_review')), findsNothing);
  });

  testWidgets('the question form shows the figure state and the box', (
    tester,
  ) async {
    final exams = FakeExamsRepository(detail: importedExamJson(cropped: false));
    await pumpExamScreen(
      tester,
      const ExamQuestionScreen(examId: 40, questionId: 41),
      repo: exams,
      overrides: [
        examImportRepositoryProvider.overrideWithValue(
          FakeExamImportRepository(exams: exams),
        ),
      ],
    );
    // Page 1 has an image: the prompt figure is being cropped; page 2 not.
    expect(find.text('กำลังตัดภาพประกอบ'), findsOneWidget);
    expect(find.text('ยังไม่มีภาพประกอบ'), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_option_recrop_2')), findsOneWidget);
    expect(find.byKey(const ValueKey('exam_option_recrop_1')), findsNothing);

    await tapVisible(tester, find.byKey(const ValueKey('exam_prompt_recrop')));
    expect(find.text('ภาพโจทย์ข้อ 5'), findsOneWidget);
    expect(find.byKey(const ValueKey('figure_crop_canvas')), findsOneWidget);
  });

  testWidgets('the read review route opens from the exam page', (tester) async {
    final exams = FakeExamsRepository(detail: importedExamJson());
    await pumpExamScreen(tester, const ExamScreen(examId: 40), repo: exams);
    await tapVisible(
      tester,
      find.byKey(const ValueKey('exam_open_read_review')),
    );
    expect(
      find.textContaining('read-review /exams/40/read-review'),
      findsOneWidget,
    );
  });
}
