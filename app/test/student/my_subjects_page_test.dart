import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/hand_in/student_assignments_page.dart';
import 'package:eduvision/features/results/results_page.dart';
import 'package:eduvision/features/student/my_subjects_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'student_fixtures.dart';

final _now = DateTime.utc(2026, 10, 2, 3);

Future<void> _pump(
  WidgetTester tester,
  Widget page, {
  FakeOverviewRepository? overview,
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    Scaffold(body: page),
    overrides: studentViewOverrides(overview: overview),
    extraRoutes: [
      stubRoute(AppRoutes.studentSubjectPath, 'subject'),
      stubRoute(AppRoutes.myGrades, 'grades'),
    ],
  );
}

/// "วิชาของฉัน" and the combined tabs of a student in two classrooms
/// (DESIGN §24.11, §24.13).
void main() {
  group('วิชาของฉัน', () {
    testWidgets('one card per course with its classroom, to-do and grade', (
      tester,
    ) async {
      await _pump(tester, const MySubjectsPage());

      expect(find.text('ต้องส่งอีก 3 งาน'), findsOneWidget);
      final math = find.byKey(const ValueKey('subject_$keyMath5'));
      expect(
        find.descendant(of: math, matching: find.text('ค15101 คณิตศาสตร์ 5')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: math, matching: find.text('ป.5/1 · 2569')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: math, matching: find.text('ครูสมศรี')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: math, matching: find.text('ต้องส่ง 2 งาน')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: math, matching: find.text('ผล 1 รายการ')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: math, matching: find.text('3')),
        findsOneWidget,
      );

      final science = find.byKey(const ValueKey('subject_$keyScience5'));
      expect(
        find.descendant(of: science, matching: find.text('ไม่มีงานค้าง')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: science,
          matching: find.byIcon(Icons.chevron_right),
        ),
        findsOneWidget,
        reason: 'no grade yet',
      );
      // Older work without a course is "อื่นๆ (วิชา)".
      expect(find.text('อื่นๆ (ภาษาไทย)'), findsOneWidget);

      // The closed classroom is folded away under "ห้องเก่า".
      expect(find.text('ห้องเก่า (1 รายวิชา)'), findsOneWidget);
      expect(find.text('ค14101 คณิตศาสตร์ 4'), findsNothing);
      await tester.tap(find.text('ห้องเก่า (1 รายวิชา)'));
      await tester.pumpAndSettle();
      expect(find.text('ค14101 คณิตศาสตร์ 4'), findsOneWidget);
      expect(find.text('ห้องเก่า · ป.4/1 · 2568'), findsOneWidget);
      expect(find.text('ร'), findsOneWidget);

      await tester.tap(find.text('ค15101 คณิตศาสตร์ 5'));
      await tester.pumpAndSettle();
      expect(
        find.text('subject ${AppRoutes.studentSubject(keyMath5)}'),
        findsOneWidget,
      );
    });

    testWidgets('"เกรดทั้งหมด" opens every published grade', (tester) async {
      await _pump(tester, const MySubjectsPage());
      await tester.tap(find.byKey(const ValueKey('subjects_all_grades')));
      await tester.pumpAndSettle();
      expect(find.text('grades /student/grades'), findsOneWidget);
    });

    testWidgets('a student in no classroom', (tester) async {
      await _pump(
        tester,
        const MySubjectsPage(),
        overview: FakeOverviewRepository({'classrooms': [], 'groups': []}),
      );
      expect(find.text('ยังไม่ได้อยู่ในห้องเรียน'), findsOneWidget);
    });

    testWidgets('a classroom without any course yet', (tester) async {
      await _pump(
        tester,
        const MySubjectsPage(),
        overview: FakeOverviewRepository({
          'classrooms': [room51],
          'groups': [],
        }),
      );
      expect(find.text('ยังไม่มีรายวิชา'), findsOneWidget);
      expect(find.textContaining('ห้อง ป.5/1 · 2569'), findsOneWidget);
    });

    testWidgets('only old classrooms: says so above "ห้องเก่า"', (
      tester,
    ) async {
      final body = studentOverviewJson();
      body['groups'] = [(body['groups'] as List).last];
      await _pump(
        tester,
        const MySubjectsPage(),
        overview: FakeOverviewRepository(body),
      );
      expect(find.text('ไม่มีงานค้าง'), findsOneWidget);
      expect(find.text('ไม่มีรายวิชาในห้องที่เปิดอยู่'), findsOneWidget);
      expect(find.text('ห้องเก่า (1 รายวิชา)'), findsOneWidget);
    });

    testWidgets('an error offers to try again', (tester) async {
      final overview = FakeOverviewRepository()..error = Exception('offline');
      await _pump(tester, const MySubjectsPage(), overview: overview);
      expect(find.text('ลองใหม่'), findsOneWidget);
      overview.error = null;
      await tester.tap(find.text('ลองใหม่'));
      await tester.pumpAndSettle();
      expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    });
  });

  group('ส่งงาน of every classroom', () {
    testWidgets('grouped by subject with the classroom; chips switch', (
      tester,
    ) async {
      await _pump(tester, StudentAssignmentsPage(now: () => _now));

      // Headings in order: courses, then "อื่นๆ", then the old classroom.
      final headings = [
        'ค15101 คณิตศาสตร์ 5',
        'อื่นๆ (ภาษาไทย)',
        'ค14101 คณิตศาสตร์ 4',
      ];
      final ys = [
        for (final h in headings) tester.getTopLeft(find.text(h).last).dy,
      ];
      expect(ys, orderedEquals([...ys]..sort()));
      expect(find.text('ห้องเก่า · ป.4/1 · 2568'), findsWidgets);
      expect(find.text('ห้องเก่า ส่งไม่ได้แล้ว'), findsOneWidget);
      expect(find.text('ปิดรับแล้ว'), findsNothing);

      // Switch to one subject, then back to all.
      await tester.tap(find.byKey(const ValueKey('subject_filter_$keyThai')));
      await tester.pumpAndSettle();
      expect(find.text('อ่านจับใจความ'), findsOneWidget);
      expect(find.text('เศษส่วน ชุดที่ 1'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('subject_filter_all')));
      await tester.pumpAndSettle();
      expect(find.text('เศษส่วน ชุดที่ 1'), findsOneWidget);
      expect(find.text('ทบทวนการคูณ'), findsOneWidget);
    });
  });

  group('ผลการบ้าน of every classroom', () {
    testWidgets('grouped by subject with the classroom; chips switch', (
      tester,
    ) async {
      await _pump(tester, const ResultsPage());
      expect(find.text('บวกเศษส่วน'), findsOneWidget);
      expect(find.text('สูตรคูณแม่ 7'), findsOneWidget);
      expect(find.text('8/10'), findsOneWidget);
      expect(find.text('ป.5/1 · 2569'), findsOneWidget);
      expect(find.text('ห้องเก่า · ป.4/1 · 2568'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('subject_filter_$keyMath4')));
      await tester.pumpAndSettle();
      expect(find.text('บวกเศษส่วน'), findsNothing);
      expect(find.text('สูตรคูณแม่ 7'), findsOneWidget);
    });

    testWidgets('one subject has no filter chips', (tester) async {
      await pumpScreen(
        tester,
        const Scaffold(body: ResultsPage()),
        overrides: studentViewOverrides(
          results: FakeStudentResults([resultsFixture().first]),
        ),
        extraRoutes: [
          GoRoute(
            path: '/student/results/:sid',
            builder: (_, s) => Text('result ${s.pathParameters['sid']}'),
          ),
        ],
      );
      expect(find.byKey(const ValueKey('subject_filter')), findsNothing);
      await tester.tap(find.text('บวกเศษส่วน'));
      await tester.pumpAndSettle();
      expect(find.text('result 70'), findsOneWidget);
    });
  });
}
