import 'package:eduvision/features/student/student_subject_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:eduvision/features/charts/charts_repository.dart';
import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:flutter_riverpod/misc.dart';

import '../charts/chart_fixtures.dart';
import '../gradebook/gradebook_fakes.dart';
import '../helpers/fake_charts.dart';
import '../helpers/pump_screen.dart';
import 'student_fixtures.dart';

final _now = DateTime.utc(2026, 10, 2, 3);

Future<void> _pump(
  WidgetTester tester,
  String key, {
  FakeOverviewRepository? overview,
  FakeGradebookRepository? gradebook,
  List<Override> extra = const [],
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    StudentSubjectScreen(initialKey: key, now: () => _now),
    overrides: [
      ...studentViewOverrides(overview: overview, gradebook: gradebook),
      ...extra,
    ],
    extraRoutes: [
      GoRoute(
        path: '/student/assignments/:aid/hand-in',
        builder: (_, s) => Text('hand-in ${s.pathParameters['aid']}'),
      ),
      GoRoute(
        path: '/student/results/:sid',
        builder: (_, s) => Text('result ${s.pathParameters['sid']}'),
      ),
    ],
  );
}

/// One subject of "วิชาของฉัน" (DESIGN §24.11, §24.13): its work, results,
/// grade and charts, and switching to another subject.
void main() {
  testWidgets('shows the subject\'s own work and results only', (tester) async {
    await _pump(tester, keyMath5);

    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    expect(find.text('ป.5/1 · 2569 · ครูสมศรี'), findsOneWidget);
    for (final tab in ['งาน', 'ผล', 'เกรด', 'กราฟ']) {
      expect(find.widgetWithText(Tab, tab), findsOneWidget);
    }
    expect(find.byKey(const ValueKey('subject_closed_banner')), findsNothing);

    // "งาน": both assignments of this course in this classroom, nothing else.
    expect(find.text('เศษส่วน ชุดที่ 1'), findsOneWidget);
    expect(find.text('เศษส่วน ชุดที่ 2'), findsOneWidget);
    expect(find.text('อ่านจับใจความ'), findsNothing);
    expect(find.text('ทบทวนการคูณ'), findsNothing);
    await tester.tap(find.text('เศษส่วน ชุดที่ 1'));
    await tester.pumpAndSettle();
    expect(find.text('hand-in 1'), findsOneWidget);
    Navigator.of(tester.element(find.text('hand-in 1'))).pop();
    await tester.pumpAndSettle();

    // "ผล": this course's result, not the old classroom's.
    await tester.tap(find.widgetWithText(Tab, 'ผล'));
    await tester.pumpAndSettle();
    expect(find.text('บวกเศษส่วน'), findsOneWidget);
    expect(find.text('สูตรคูณแม่ 7'), findsNothing);
  });

  testWidgets('the grade tab asks for this classroom\'s publication', (
    tester,
  ) async {
    final gradebook = FakeGradebookRepository();
    await _pump(tester, keyMath5, gradebook: gradebook);
    await tester.tap(find.widgetWithText(Tab, 'เกรด'));
    await tester.pumpAndSettle();
    expect(find.text('เกรด 3'), findsOneWidget);
    expect(gradebook.gradeClassroomIds, [7]);
  });

  testWidgets('the charts tab shows the student\'s own course charts', (
    tester,
  ) async {
    final mastery = FakeCourseMastery();
    await _pump(
      tester,
      keyMath5,
      extra: [
        courseMasteryRepositoryProvider.overrideWithValue(mastery),
        chartsRepositoryProvider.overrideWithValue(FakeChartsRepository()),
      ],
    );
    await tester.tap(find.widgetWithText(Tab, 'กราฟ'));
    await tester.pumpAndSettle();
    expect(find.text('ความเข้าใจของฉัน'), findsOneWidget);
    expect(mastery.calls, isNotEmpty);
    expect(mastery.calls.every((c) => c.own), isTrue);
  });

  testWidgets('a grade not published yet says so in the tab', (tester) async {
    final gradebook = FakeGradebookRepository()..myGradeBody = null;
    await _pump(tester, keyScience5, gradebook: gradebook);
    await tester.tap(find.widgetWithText(Tab, 'เกรด'));
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่ได้ประกาศเกรด'), findsOneWidget);
  });

  testWidgets('empty work and results of a subject say so', (tester) async {
    await _pump(tester, keyScience5);
    expect(find.text('ไม่มีงานของวิชานี้'), findsOneWidget);
    await tester.tap(find.widgetWithText(Tab, 'ผล'));
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่มีผลของวิชานี้'), findsOneWidget);
  });

  testWidgets('work without a course has no grade or charts', (tester) async {
    await _pump(tester, keyThai);
    expect(find.text('อื่นๆ (ภาษาไทย)'), findsOneWidget);
    expect(find.widgetWithText(Tab, 'เกรด'), findsNothing);
    expect(find.widgetWithText(Tab, 'กราฟ'), findsNothing);
    expect(find.text('อ่านจับใจความ'), findsOneWidget);
  });

  testWidgets('an old classroom is read-only', (tester) async {
    await _pump(tester, keyMath4);
    expect(
      find.text('ห้องเก่า อ่านอย่างเดียว ส่งงานเพิ่มไม่ได้'),
      findsOneWidget,
    );
    expect(find.text('ทบทวนการคูณ'), findsOneWidget);
    expect(find.text('ห้องเก่า ส่งไม่ได้แล้ว'), findsOneWidget);
  });

  testWidgets('"เปลี่ยนวิชา" switches to another subject in place', (
    tester,
  ) async {
    await _pump(tester, keyMath5);
    await tester.tap(find.byKey(const ValueKey('subject_switch')));
    await tester.pumpAndSettle();
    expect(find.text('เปลี่ยนวิชา'), findsWidgets);
    expect(find.byKey(const ValueKey('switch_$keyMath4')), findsOneWidget);
    expect(
      tester
          .widget<ListTile>(find.byKey(const ValueKey('switch_$keyMath5')))
          .selected,
      isTrue,
    );

    await tester.tap(find.byKey(const ValueKey('switch_$keyThai')));
    await tester.pumpAndSettle();
    expect(find.text('อื่นๆ (ภาษาไทย)'), findsOneWidget);
    expect(find.text('อ่านจับใจความ'), findsOneWidget);
    expect(find.text('เศษส่วน ชุดที่ 1'), findsNothing);
    expect(find.widgetWithText(Tab, 'เกรด'), findsNothing);

    // Dismissing the sheet keeps the subject.
    await tester.tap(find.byKey(const ValueKey('subject_switch')));
    await tester.pumpAndSettle();
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();
    expect(find.text('อื่นๆ (ภาษาไทย)'), findsOneWidget);
  });

  testWidgets('one subject only has no switch', (tester) async {
    final body = studentOverviewJson();
    body['groups'] = [(body['groups'] as List).first];
    await _pump(tester, keyMath5, overview: FakeOverviewRepository(body));
    expect(find.byKey(const ValueKey('subject_switch')), findsNothing);
  });

  testWidgets('an unknown subject says it is not found', (tester) async {
    await _pump(tester, 'c99:1');
    expect(find.text('ไม่พบรายวิชานี้'), findsOneWidget);
  });

  testWidgets('an error offers to try again', (tester) async {
    final overview = FakeOverviewRepository()..error = Exception('offline');
    await _pump(tester, keyMath5, overview: overview);
    expect(find.text('ลองใหม่'), findsOneWidget);
    overview.error = null;
    await tester.tap(find.text('ลองใหม่'));
    await tester.pumpAndSettle();
    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
  });
}
