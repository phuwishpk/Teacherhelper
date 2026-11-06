import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_section_dialog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §22.20.1: the section dialog offers the written types only for an
/// app exam with QR sheets, and a written section has no options or digits.
void main() {
  Future<ExamSectionDraft?> openDialog(
    WidgetTester tester, {
    required bool allowWritten,
    required void Function(Future<ExamSectionDraft?>) onResult,
  }) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => onResult(
                showExamSectionDialog(context, allowWritten: allowWritten),
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    return null;
  }

  testWidgets(
    'an open section has no options or digits and starts at 5 points',
    (tester) async {
      late Future<ExamSectionDraft?> result;
      await openDialog(tester, allowWritten: true, onResult: (f) => result = f);

      await tester.tap(find.text('อัตนัย'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('section_options_4')), findsNothing);
      expect(find.byKey(const ValueKey('section_digits_2')), findsNothing);
      final points = tester.widget<TextFormField>(
        find.byKey(const ValueKey('section_points')),
      );
      expect(points.controller!.text, '5');

      await tester.tap(find.byKey(const ValueKey('section_submit')));
      await tester.pumpAndSettle();
      final draft = (await result)!;
      expect(draft.type, ExamSectionType.open);
      expect(draft.optionCount, isNull);
      expect(draft.numeric, isNull);
      expect(draft.defaultPoints, 5);
      expect(draft.questionCount, 10);
      final json = draft.toCreateJson();
      expect(json['type'], 'open');
      expect(json.containsKey('option_count'), isFalse);
      expect(json.containsKey('numeric'), isFalse);
    },
  );

  testWidgets('a show_work section can be chosen too', (tester) async {
    late Future<ExamSectionDraft?> result;
    await openDialog(tester, allowWritten: true, onResult: (f) => result = f);

    await tester.tap(find.text('แสดงวิธีทำ'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('section_submit')));
    await tester.pumpAndSettle();

    expect((await result)!.type, ExamSectionType.showWork);
  });

  testWidgets('points the teacher typed survive a change of type', (
    tester,
  ) async {
    late Future<ExamSectionDraft?> result;
    await openDialog(tester, allowWritten: true, onResult: (f) => result = f);

    await tester.enterText(find.byKey(const ValueKey('section_points')), '3');
    await tester.tap(find.text('อัตนัย'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('section_submit')));
    await tester.pumpAndSettle();

    expect((await result)!.defaultPoints, 3);
  });

  testWidgets(
    'written types are not offered for a manual or shared-sheet exam',
    (tester) async {
      late Future<ExamSectionDraft?> result;
      await openDialog(
        tester,
        allowWritten: false,
        onResult: (f) => result = f,
      );

      expect(find.text('อัตนัย'), findsNothing);
      expect(find.text('แสดงวิธีทำ'), findsNothing);
      expect(
        find.text(
          'ข้อเขียนใช้กับข้อสอบตรวจด้วยแอปที่ใช้กระดาษแบบ QR รายคนเท่านั้น',
        ),
        findsOneWidget,
      );
      expect(find.text('ปรนัย'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('section_submit')));
      await tester.pumpAndSettle();
      expect((await result)!.type, ExamSectionType.mcq);
    },
  );
}
