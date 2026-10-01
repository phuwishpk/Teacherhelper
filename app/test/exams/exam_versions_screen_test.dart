import 'package:eduvision/features/exams/exam_versions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

/// "ชุดข้อสอบ" (DESIGN §22.5).
void main() {
  testWidgets('shows each version\'s order, option order and key', (
    tester,
  ) async {
    await pumpExamScreen(tester, const ExamVersionsScreen(examId: 40));

    expect(find.text('ชุด ก'), findsOneWidget);
    expect(find.text('ชุด ข'), findsOneWidget);
    expect(find.text('ชุด ก: ลำดับต้นฉบับ'), findsOneWidget);
    expect(find.text('ตอนที่ 1 ปรนัย'), findsOneWidget);
    expect(find.text('เฉลย ค'), findsOneWidget);
    expect(find.text('เฉลย ผิด'), findsOneWidget);
    expect(find.text('เฉลย 0.5'), findsOneWidget);

    await tester.tap(find.text('ชุด ข'));
    await tester.pumpAndSettle();
    final q11 = find.byKey(const ValueKey('version_2_item_2'));
    expect(
      find.descendant(of: q11, matching: find.text('ข้อต้นฉบับ 1')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: q11,
        matching: find.text('ตัวเลือกที่พิมพ์ ก ข ค ง… = ต้นฉบับ ค ก ง ข'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: q11, matching: find.text('เฉลย ก')),
      findsOneWidget,
    );
  });

  testWidgets('changes the number of versions and reshuffles', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamVersionsScreen(examId: 40),
    );

    await tester.tap(find.text('3 ชุด'));
    await tester.pumpAndSettle();
    expect(repo.args('updateSettings'), [
      {'version_count': 3},
    ]);
    expect(find.text('ใช้ 3 ชุดแล้ว'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('versions_reshuffle')));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'สุ่มใหม่'));
    await tester.pumpAndSettle();
    expect(repo.args('reshuffle'), [40]);
    expect(find.text('สุ่มชุดใหม่แล้ว'), findsOneWidget);
  });

  testWidgets('a single version has no tabs and nothing to reshuffle', (
    tester,
  ) async {
    await pumpExamScreen(
      tester,
      const ExamVersionsScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(versionCount: 1),
        versionsBody: versionsJson(count: 1),
      ),
    );
    expect(find.byType(TabBar), findsNothing);
    expect(find.text('ชุด ก: ลำดับต้นฉบับ'), findsOneWidget);
    final reshuffle = tester.widget<ButtonStyleButton>(
      find.byKey(const ValueKey('versions_reshuffle')),
    );
    expect(reshuffle.onPressed, isNull);
  });

  testWidgets('printed versions are fixed', (tester) async {
    final repo = await pumpExamScreen(
      tester,
      const ExamVersionsScreen(examId: 40),
      repo: FakeExamsRepository(
        detail: examJson(lockedAt: '2026-10-01T00:00:00Z'),
        versionsBody: versionsJson(lockedAt: '2026-10-01T00:00:00Z'),
      ),
    );
    expect(
      find.textContaining('พิมพ์แล้ว ลำดับของทุกชุดคงที่'),
      findsOneWidget,
    );
    await tester.tap(find.text('3 ชุด'));
    await tester.pumpAndSettle();
    expect(repo.args('updateSettings'), isEmpty);
    final reshuffle = tester.widget<ButtonStyleButton>(
      find.byKey(const ValueKey('versions_reshuffle')),
    );
    expect(reshuffle.onPressed, isNull);
  });

  testWidgets('an error of the server is shown', (tester) async {
    final repo = FakeExamsRepository()..failNext = StateError('x');
    await pumpExamScreen(
      tester,
      const ExamVersionsScreen(examId: 40),
      repo: repo,
    );
    await tester.tap(find.text('4 ชุด'));
    await tester.pumpAndSettle();
    expect(find.text('เกิดข้อผิดพลาดที่ไม่คาดคิด'), findsOneWidget);
  });
}
