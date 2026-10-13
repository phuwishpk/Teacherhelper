import 'package:eduvision/features/help/help_content.dart';
import 'package:eduvision/features/help/help_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

/// The in-app guide (คู่มือการใช้งาน) behind the shells' help button.
void main() {
  test('every topic has steps or situations, and ids are unique per role', () {
    for (final role in HelpRole.values) {
      final topics = helpTopicsFor(role);
      expect(topics, isNotEmpty);
      expect(topics.map((t) => t.id).toSet().length, topics.length);
      for (final t in topics) {
        expect(t.title, isNotEmpty);
        expect(t.intro, isNotEmpty);
        expect(
          t.steps.isNotEmpty || t.useCases.isNotEmpty,
          isTrue,
          reason: t.id,
        );
      }
    }
  });

  testWidgets('a phone lists the teacher topics and expands one', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await pumpScreen(tester, const HelpScreen(role: HelpRole.teacher));

    expect(find.text('คู่มือการใช้งาน สำหรับครู'), findsOneWidget);
    expect(find.text('ห้องเรียนและนักเรียน'), findsOneWidget);
    expect(find.text('ขั้นตอน'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('help_topic_join')));
    await tester.pumpAndSettle();
    expect(find.text('ขั้นตอน'), findsOneWidget);
    expect(find.text('สถานการณ์ที่พบบ่อย'), findsOneWidget);
    expect(find.text('ครูไม่มีรายชื่อล่วงหน้า'), findsOneWidget);
  });

  testWidgets('search narrows the topics and can find nothing', (tester) async {
    tester.view.physicalSize = const Size(390, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await pumpScreen(tester, const HelpScreen(role: HelpRole.student));

    expect(find.byType(ExpansionTile), findsNWidgets(studentHelpTopics.length));

    await tester.enterText(find.byKey(const ValueKey('help_search')), 'ล็อก');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('help_topic_login')), findsOneWidget);
    expect(find.byKey(const ValueKey('help_topic_grades')), findsNothing);

    await tester.enterText(find.byKey(const ValueKey('help_search')), 'xyz');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('help_empty')), findsOneWidget);
  });

  testWidgets('a desktop shows the list beside the open topic', (tester) async {
    tester.view.physicalSize = const Size(1440, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await pumpScreen(tester, const HelpScreen(role: HelpRole.teacher));

    // The first topic opens by itself; the list stays beside it.
    expect(find.byType(ExpansionTile), findsNothing);
    expect(find.text('ขั้นตอน'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('help_topic_review')));
    await tester.pumpAndSettle();
    expect(find.text('นักเรียนขอให้ตรวจใหม่'), findsOneWidget);
  });
}
