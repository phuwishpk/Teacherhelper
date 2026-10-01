import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_providers.dart';
import 'package:eduvision/features/exams/exam_copy_questions_screen.dart';
import 'package:eduvision/features/exams/exam_import_models.dart';
import 'package:eduvision/features/exams/exam_import_repository.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';
import 'exam_import_fakes.dart';
import 'exam_test_helpers.dart';

/// "คัดลอกจากข้อสอบเดิม" (DESIGN §22.4 item 3): the teacher's own earlier
/// questions, filtered, picked one by one or by section, then copied.
void main() {
  LibraryPage firstPage() => LibraryPage.fromJson({
    'data': [
      libraryJson(
        id: 101,
        examId: 30,
        examTitle: 'สอบปลายภาค 2568',
        sectionId: 301,
        position: 1,
        prompt: '1/2 + 1/4 เท่ากับเท่าใด',
        key: {
          'accepted_options': [3],
        },
      ),
      libraryJson(
        id: 102,
        examId: 30,
        examTitle: 'สอบปลายภาค 2568',
        sectionId: 301,
        position: 2,
        prompt: 'เศษส่วนใดมากที่สุด',
      ),
      libraryJson(
        id: 201,
        examId: 20,
        examTitle: 'สอบย่อย 1',
        sectionId: 201,
        type: 'true_false',
        prompt: '0.5 เท่ากับ 1/2',
        key: {
          'accepted_options': [1],
        },
      ),
    ],
    'meta': {'per_page': 50, 'next_cursor': 'c2'},
  });

  Future<(FakeExamsRepository, FakeExamImportRepository)> pump(
    WidgetTester tester, {
    Map<String, dynamic>? detail,
  }) async {
    final exams = FakeExamsRepository(detail: detail);
    final imports = FakeExamImportRepository(exams: exams)
      ..pages[null] = firstPage()
      ..pages['c2'] = LibraryPage.fromJson({
        'data': [
          libraryJson(
            id: 103,
            examId: 30,
            examTitle: 'สอบปลายภาค 2568',
            sectionId: 301,
            position: 3,
            prompt: 'ข้อที่โหลดเพิ่ม',
          ),
        ],
        'meta': {'next_cursor': null},
      });
    await pumpExamScreen(
      tester,
      const ExamCopyQuestionsScreen(examId: 40),
      repo: exams,
      overrides: [
        examImportRepositoryProvider.overrideWithValue(imports),
        coursesProvider.overrideWith(
          (ref) async => const [
            Course(
              id: 3,
              code: 'ค15101',
              name: 'คณิตศาสตร์ 5',
              subjectId: 1,
              gradeLevel: 5,
              academicYear: 2569,
            ),
          ],
        ),
      ],
    );
    return (exams, imports);
  }

  testWidgets('lists own questions by exam and section and copies them', (
    tester,
  ) async {
    final (exams, imports) = await pump(tester);
    expect(imports.args('library').single, {
      'course_id': null,
      'exam_id': null,
      'q': '',
      'exclude_exam': 40,
      'cursor': null,
    });
    expect(find.text('สอบปลายภาค 2568'), findsOneWidget);
    expect(find.text('สอบย่อย 1'), findsOneWidget);
    expect(find.textContaining('1/2 + 1/4 เท่ากับเท่าใด'), findsOneWidget);
    expect(find.textContaining('เฉลย ค'), findsOneWidget);
    expect(find.textContaining('ไม่มีเฉลย'), findsOneWidget);
    expect(find.textContaining('เฉลย ถูก'), findsOneWidget);

    // A whole section, then one question off, then the next page.
    await tapVisible(tester, find.byKey(const ValueKey('copy_section_301')));
    expect(find.text('คัดลอก 2 ข้อ'), findsOneWidget);
    await tapVisible(tester, find.byKey(const ValueKey('copy_q_102')));
    expect(find.text('คัดลอก 1 ข้อ'), findsOneWidget);
    await tapVisible(tester, find.byKey(const ValueKey('copy_load_more')));
    expect(imports.args('library').last, containsPair('cursor', 'c2'));
    expect(find.textContaining('ข้อที่โหลดเพิ่ม'), findsOneWidget);
    expect(find.byKey(const ValueKey('copy_load_more')), findsNothing);
    await tapVisible(tester, find.byKey(const ValueKey('copy_q_201')));

    // Into section 1 of this exam.
    await tester.tap(find.byKey(const ValueKey('copy_target')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ตอนที่ 1 ปรนัย · ปรนัย 4 ตัวเลือก').last);
    await tester.pumpAndSettle();

    imports.copyResult = ExamCopyResult(
      created: 1,
      questionIds: const [90],
      skipped: const [
        CopySkipped(
          questionId: 201,
          reason: 'type_mismatch',
          reasonTh: 'ชนิดของข้อไม่ตรงกับตอนปลายทาง',
        ),
      ],
      exam: ExamDetail.fromJson(examJson()),
    );
    await tapVisible(tester, find.byKey(const ValueKey('copy_submit')));
    expect(imports.args('copyQuestions'), [
      {
        'ids': [101, 201],
        'section': 1,
      },
    ]);
    expect(find.text('คัดลอกแล้ว 1 ข้อ'), findsOneWidget);
    expect(
      find.text('• สอบย่อย 1 ข้อ 1: ชนิดของข้อไม่ตรงกับตอนปลายทาง'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('copy_result_ok')));
    await tester.pumpAndSettle();
    expect(find.text('stub-home'), findsOneWidget);
    expect(exams.args('get'), isNotEmpty);
  });

  testWidgets('filters by search, course and exam start over', (tester) async {
    final (_, imports) = await pump(tester);
    await tapVisible(tester, find.byKey(const ValueKey('copy_q_101')));

    await tester.enterText(
      find.byKey(const ValueKey('copy_search')),
      'เศษส่วน',
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(imports.args('library').last, containsPair('q', 'เศษส่วน'));
    expect(find.text('คัดลอก 0 ข้อ'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('copy_course')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
    await tester.pumpAndSettle();
    expect(imports.args('library').last, containsPair('course_id', 3));

    await tester.tap(find.byKey(const ValueKey('copy_exam')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('สอบย่อย 1').last);
    await tester.pumpAndSettle();
    expect(imports.args('library').last, containsPair('exam_id', 20));
  });

  testWidgets('an empty library and a load error', (tester) async {
    final exams = FakeExamsRepository();
    final imports = FakeExamImportRepository(exams: exams)
      ..failures['library'] = Exception('offline');
    await pumpExamScreen(
      tester,
      const ExamCopyQuestionsScreen(examId: 40),
      repo: exams,
      overrides: [
        examImportRepositoryProvider.overrideWithValue(imports),
        coursesProvider.overrideWith((ref) async => const <Course>[]),
      ],
    );
    expect(find.text('ลองใหม่'), findsOneWidget);
    await tester.tap(find.text('ลองใหม่'));
    await tester.pumpAndSettle();
    expect(find.text('ไม่พบข้อจากข้อสอบเดิม'), findsOneWidget);
    expect(find.byKey(const ValueKey('copy_course')), findsNothing);
  });

  testWidgets('a locked exam cannot take copies', (tester) async {
    await pump(tester, detail: examJson(lockedAt: '2026-10-01T02:00:00+00:00'));
    await tapVisible(tester, find.byKey(const ValueKey('copy_q_101')));
    expect(find.textContaining('โครงสร้างถูกล็อก'), findsOneWidget);
    final button = tester.widget<FilledButton>(
      find.byKey(const ValueKey('copy_submit')),
    );
    expect(button.onPressed, isNull);
  });
}
