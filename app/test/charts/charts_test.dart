import 'package:eduvision/features/charts/chart_models.dart';
import 'package:eduvision/features/charts/chart_style.dart';
import 'package:eduvision/features/charts/charts_repository.dart';
import 'package:eduvision/features/charts/course_charts_screen.dart';
import 'package:eduvision/features/charts/progress_chart.dart';
import 'package:eduvision/features/charts/rollup_chart.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../helpers/fake_api_server.dart';
import '../helpers/fake_charts.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import 'chart_fixtures.dart';

void _tallPhone(WidgetTester tester) {
  tester.view.physicalSize = const Size(390, 3200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  group('models (DESIGN §20.4)', () {
    test('progress: one point per Bangkok day, the last of the day', () {
      final p = IndicatorProgress.fromJson(progressJson());
      expect(p.studentId, 55);
      expect(p.skillIds, [1, 3]);
      expect(p.skills.map((s) => s.skill.code), [
        'ค 1.1 ป.5/1',
        'ค 1.1 ป.5/2',
        'ค 1.2 ป.5/1',
      ]);
      final line = p.series.first;
      expect(line.points, hasLength(3));
      expect(line.daily.map((d) => d.value), [1.0, 0.723]);
      expect(line.daily.last.date, DateTime.utc(2026, 9, 3));
      expect(line.points.last.source, 'practice');
      expect(line.points.last.fromExam, isFalse);
      // A published exam answer is an observation like homework (§22.13).
      expect(p.series.last.daily.single.source, 'exam');
      expect(p.series.last.daily.single.fromExam, isTrue);
      expect(
        line.points[1].observedAt,
        DateTime.utc(2026, 9, 2, 18),
        reason: 'UTC instant; the day is Bangkok',
      );
    });

    test('pass rate, score distribution and plan progress parse', () {
      final pass = IndicatorPassRate.fromJson(passRateJson());
      expect(pass.courseId, 4);
      expect(pass.studentCount, 3);
      expect(pass.assessedCount, 2);
      expect(pass.indicators.last.passRate, isNull);
      expect(pass.indicators[1].passRate, 1.0);

      final dist = ScoreDistribution.fromJson(scoreDistributionJson());
      expect(dist.maxPoints, 10);
      expect(dist.bins, hasLength(10));
      expect(dist.maxCount, 2);
      expect(dist.median, 7.5);

      final plan = PlanProgress.fromJson(planProgressJson());
      expect(plan.courseTitle, 'ค15101 คณิตศาสตร์ 5');
      expect(plan.summary.planned, 4);
      expect(plan.summary.plansTaught, 1);
      expect(plan.units.map((u) => u.shortLabel), [
        'หน่วย 1',
        'หน่วย 2',
        'ไม่อยู่ในหน่วย',
      ]);
      final u1 = plan.units.first;
      expect(
        u1.assessed + u1.taughtNotAssessed + u1.notTaught,
        u1.planned,
        reason: 'the stack parts are disjoint',
      );
    });

    test('a line keeps its color slot while others come and go', () {
      final s = ProgressSelection();
      expect(s.key, isNull, reason: 'the server picks first');
      s.adopt([1, 3]);
      expect(s.key, isNull, reason: 'still the server pick: no reload');
      expect([s.slotOf(1), s.slotOf(3)], [0, 1]);
      expect(s.add(2), isTrue);
      expect(s.key, '1,2,3');
      expect(s.slotOf(2), 2);
      s.remove(3);
      expect(s.add(4), isTrue);
      expect(s.slotOf(4), 1, reason: 'the lowest free slot');
      expect(s.slotOf(1), 0);
      expect(s.slotOf(2), 2);
      s.add(5);
      s.add(6);
      expect(s.isFull, isTrue);
      expect(s.add(7), isFalse, reason: 'at most 5 lines');
      s.replace([9, 1]);
      expect(s.ids, [9, 1]);
      expect(s.slotOf(1), 0);
    });

    test('two-line codes for narrow axis labels', () {
      expect(twoLineCode('ค 1.1 ป.5/1'), 'ค 1.1\nป.5/1');
      expect(twoLineCode('ABC'), 'ABC');
      expect(
        nodeShortLabel(
          const RollupNode(type: 'unit', title: 'เศษส่วน', position: 2),
        ),
        'หน่วย 2',
      );
      expect(
        nodeShortLabel(
          const RollupNode(type: 'standard', title: 'x', code: 'ค 1.1'),
        ),
        'ค 1.1',
      );
    });
  });

  group('ApiChartsRepository', () {
    late List<(String, Map<String, String>)> sent;

    ApiChartsRepository repo() {
      sent = [];
      final adapter = FakeHttpAdapter((o) async {
        final path = FakeApiServer.apiPath(o.uri);
        sent.add((path, o.uri.queryParameters));
        final body = switch (path) {
          _ when path.endsWith('indicator-progress') => progressJson(),
          _ when path.endsWith('indicator-pass-rate') => passRateJson(),
          _ when path.endsWith('score-distribution') => scoreDistributionJson(),
          _ => planProgressJson(),
        };
        return jsonResponse(200, {'data': body});
      });
      return ApiChartsRepository(fakeDio(adapter));
    }

    test('paths and queries of every chart', () async {
      final r = repo();
      await r.progress(studentId: 55, skillIds: [3, 1]);
      expect(sent.last.$1, endsWith('/students/55/indicator-progress'));
      expect(sent.last.$2, {'skill_ids': '3,1'});
      await r.progress();
      expect(sent.last.$1, endsWith('/student/indicator-progress'));
      expect(sent.last.$2, isEmpty);
      await r.passRate(7, courseId: 4);
      expect(sent.last.$1, endsWith('/classrooms/7/indicator-pass-rate'));
      expect(sent.last.$2, {'course_id': '4'});
      final d = await r.scoreDistribution(5);
      expect(sent.last.$1, endsWith('/assignments/5/score-distribution'));
      expect(d.scoredCount, 5);
      await r.planProgress(4, classroomId: 7);
      expect(sent.last.$1, endsWith('/courses/4/plan-progress'));
      expect(sent.last.$2, {'classroom_id': '7'});
      await r.planProgress(4);
      expect(sent.last.$2, isEmpty);
    });
  });

  group('spider chart (DESIGN §20.4)', () {
    Future<void> pumpChart(WidgetTester tester, Map<String, dynamic> json) =>
        tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: SingleChildScrollView(
                child: RollupChart(
                  summary: CourseMasterySummary.fromJson(json),
                  onNode: (_) {},
                ),
              ),
            ),
          ),
        );

    testWidgets('3 assessed axes: radar', (tester) async {
      await pumpChart(tester, summaryJson());
      expect(find.byKey(const ValueKey('rollup_radar')), findsOneWidget);
      expect(find.byKey(const ValueKey('rollup_bars')), findsNothing);
    });

    int radarAxes(WidgetTester tester, Finder radar) => tester
        .widget<RadarChart>(
          find.descendant(of: radar, matching: find.byType(RadarChart)),
        )
        .data
        .dataSets
        .last
        .dataEntries
        .length;

    testWidgets('2 assessed nodes, 3 indicators: a radar of the indicators', (
      tester,
    ) async {
      await pumpChart(tester, summaryJson(axis: MasteryAxis.unit));
      await tester.pumpAndSettle();
      final radar = find.byKey(const ValueKey('rollup_radar'));
      expect(radar, findsOneWidget);
      expect(radarAxes(tester, radar), 3);
      expect(find.byKey(const ValueKey('rollup_bars')), findsNothing);
      expect(find.text(kIndicatorAxesNote), findsOneWidget);
    });

    testWidgets('2 standards with 4 assessed indicators: 4 indicator axes', (
      tester,
    ) async {
      await pumpChart(tester, summaryWithNodes([2, 2], unassessedPerNode: 1));
      final radar = find.byKey(const ValueKey('rollup_radar'));
      expect(radar, findsOneWidget);
      expect(radarAxes(tester, radar), 4);
      expect(find.text(kIndicatorAxesNote), findsOneWidget);
      expect(find.byKey(const ValueKey('rollup_bars')), findsNothing);
    });

    testWidgets('1 node with 2 indicators: bars of every planned node', (
      tester,
    ) async {
      await pumpChart(tester, summaryWithNodes([2]));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('rollup_radar')), findsNothing);
      expect(find.byKey(const ValueKey('rollup_bars')), findsOneWidget);
      expect(find.text(kIndicatorAxesNote), findsNothing);
    });

    testWidgets('13 assessed indicators: bars, with the unassessed faint', (
      tester,
    ) async {
      await pumpChart(tester, summaryWithNodes([7, 6, 0]));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('rollup_radar')), findsNothing);
      expect(find.byKey(const ValueKey('rollup_bars')), findsOneWidget);
      expect(find.text('ค 1.3\nยังไม่ประเมิน'), findsOneWidget);
    });

    testWidgets('the card says why there is no radar', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: RollupCard(
                title: 'ภาพรวมของห้อง',
                summary: AsyncValue.data(
                  CourseMasterySummary.fromJson(summaryWithNodes([2])),
                ),
                axis: MasteryAxis.standard,
                onAxis: (_) {},
                onNode: (_) {},
                onRetry: () {},
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('rollup_bars')), findsOneWidget);
      expect(
        find.text(
          'ประเมินแล้ว 1 กลุ่ม 2 ตัวชี้วัด (เรดาร์ใช้ 3–12 แกน) จึงแสดงเป็นกราฟแท่ง',
        ),
        findsOneWidget,
      );
    });

    testWidgets('3 assessed nodes keep the node radar, no note', (
      tester,
    ) async {
      await pumpChart(tester, summaryWithNodes([1, 1, 3]));
      final radar = find.byKey(const ValueKey('rollup_radar'));
      expect(radarAxes(tester, radar), 3);
      expect(find.text(kIndicatorAxesNote), findsNothing);
    });

    test('indicator axes: in node order, each once, with their node', () {
      final s = CourseMasterySummary.fromJson(
        summaryWithNodes([2, 1], unassessedPerNode: 1),
      );
      expect(s.chartMode, RollupChartMode.indicators);
      expect(s.useRadar, isTrue);
      expect(
        s.assessedIndicators.map(
          (a) => '${a.node.code}:${a.indicator.skill.code}',
        ),
        ['ค 1.1:ค 1.1 ป.5/1', 'ค 1.1:ค 1.1 ป.5/2', 'ค 1.2:ค 1.2 ป.5/1'],
      );

      // The same indicator planned in two units counts once.
      final unit = summaryJson(axis: MasteryAxis.unit);
      final nodes = unit['nodes'] as List;
      (nodes[1] as Map)['indicators'] = (nodes[0] as Map)['indicators'];
      (nodes[1] as Map)['assessed'] = 2;
      final dup = CourseMasterySummary.fromJson(unit);
      expect(dup.assessedNodes, hasLength(3));
      expect(dup.chartMode, RollupChartMode.nodes);
      expect(dup.assessedIndicators, hasLength(3));

      final bars = CourseMasterySummary.fromJson(summaryWithNodes([13]));
      expect(bars.chartMode, RollupChartMode.bars);
      expect(bars.useRadar, isFalse);
    });

    Future<void> openSheet(WidgetTester tester, RollupNode node) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Builder(
              builder: (context) => TextButton(
                onPressed: () => showNodeIndicators(context, node),
                child: const Text('open'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
    }

    testWidgets('drill-down: a radar of 3–12 assessed indicators', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(390, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final node = CourseMasterySummary.fromJson(
        summaryWithNodes([3], unassessedPerNode: 1),
      ).nodes.single;
      await openSheet(tester, node);
      final radar = find.byKey(const ValueKey('node_radar'));
      expect(radar, findsOneWidget);
      expect(radarAxes(tester, radar), 3);
      // The list stays under the radar, the unassessed one included.
      expect(find.text('ค 1.1 ป.5/4 ตัวชี้วัด ค 1.1 ป.5/4'), findsOneWidget);
    });

    testWidgets('drill-down: no radar under 3 assessed indicators', (
      tester,
    ) async {
      final node = CourseMasterySummary.fromJson(
        summaryWithNodes([2], unassessedPerNode: 2),
      ).nodes.single;
      await openSheet(tester, node);
      expect(find.byKey(const ValueKey('node_indicators')), findsOneWidget);
      expect(find.byKey(const ValueKey('node_radar')), findsNothing);
    });

    testWidgets('nothing planned: an empty message', (tester) async {
      await pumpChart(tester, {...summaryJson(), 'nodes': const <Object>[]});
      expect(
        find.text('รายวิชานี้ยังไม่มีตัวชี้วัดที่วางแผนไว้'),
        findsOneWidget,
      );
    });
  });

  group('teacher course charts', () {
    late FakeCourseMastery mastery;
    late FakeChartsRepository charts;

    Future<ProviderContainer> pump(WidgetTester tester) {
      _tallPhone(tester);
      mastery = FakeCourseMastery();
      charts = FakeChartsRepository();
      return pumpScreen(
        tester,
        const CourseChartsScreen(courseId: 4),
        overrides: [
          courseMasteryRepositoryProvider.overrideWithValue(mastery),
          chartsRepositoryProvider.overrideWithValue(charts),
          coursesRepositoryProvider.overrideWithValue(
            FakeCoursesRepository([
              course(
                classrooms: const [
                  {'id': 7, 'name': 'ป.5/1'},
                  {'id': 8, 'name': 'ป.5/2'},
                ],
              ),
            ]),
          ),
          classroomsRepositoryProvider.overrideWithValue(FakeClassrooms()),
        ],
        extraRoutes: [
          GoRoute(
            path: '/courses/:id/students/:sid/charts',
            builder: (_, s) => Text(
              'student-${s.pathParameters['sid']}-${s.uri.queryParameters['classroom']}',
            ),
          ),
          GoRoute(
            path: '/classrooms/:id/mastery',
            builder: (_, s) => Text(
              'heatmap-${s.pathParameters['id']}-${s.uri.queryParameters['course']}',
            ),
          ),
        ],
      );
    }

    testWidgets('plan progress, classroom spider, pass rate and students', (
      tester,
    ) async {
      await pump(tester);
      expect(tester.takeException(), isNull);
      expect(find.text('กราฟ ค15101 คณิตศาสตร์ 5'), findsOneWidget);
      // The first classroom of the course, for every chart.
      expect(mastery.calls.single.classroomId, 7);
      expect(mastery.calls.single.studentId, isNull);
      expect(charts.passRateCalls.single, (classroomId: 7, courseId: 4));
      expect(charts.planCalls.single, (courseId: 4, classroomId: 7));

      expect(find.byKey(const ValueKey('plan_progress_chart')), findsOneWidget);
      expect(find.text('หน่วย 1\n2 ตัวชี้วัด'), findsOneWidget);
      expect(
        find.text('ประเมินแล้ว 2/4 ตัวชี้วัด · สอนแล้ว 1 ตัวชี้วัด (1/2 แผน)'),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('rollup_radar')), findsOneWidget);
      expect(
        find.text('ทั้งรายวิชา 63% · ประเมินแล้ว 4/5 ตัวชี้วัด'),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('pass_rate_chart')), findsOneWidget);
      expect(find.text('ค 1.1\nป.5/1\nn=2'), findsOneWidget);
      expect(find.text('ประเมินแล้ว 2/3 ตัวชี้วัด'), findsOneWidget);
      expect(find.text('1. เด็กชายเอ'), findsOneWidget);

      // The tables behind the charts.
      await tester.tap(find.byKey(const ValueKey('pass_rate_table')));
      await tester.pumpAndSettle();
      expect(find.text('ผ่าน 1/2 คน · 50%'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('plan_progress_table')));
      await tester.pumpAndSettle();
      expect(find.text('แผนสอนแล้ว 1/1 แผน'), findsOneWidget);
    });

    testWidgets('axis toggle and drill-down of a classroom node', (
      tester,
    ) async {
      await pump(tester);
      await tester.tap(find.text('ตามหน่วย'));
      await tester.pumpAndSettle();
      expect(mastery.calls.last.axis, MasteryAxis.unit);
      // 2 assessed units but 3 assessed indicators: the indicator radar.
      expect(find.byKey(const ValueKey('rollup_radar')), findsOneWidget);
      expect(find.text(kIndicatorAxesNote), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('rollup_node_0')));
      await tester.pumpAndSettle();
      final sheet = find.byKey(const ValueKey('node_indicators'));
      expect(sheet, findsOneWidget);
      expect(
        find.descendant(of: sheet, matching: find.text('ผ่าน 2/2 คน')),
        findsNWidgets(2),
      );
      expect(
        find.descendant(of: sheet, matching: find.byTooltip('ดูพัฒนาการ')),
        findsNothing,
        reason: 'a classroom has no progress line',
      );
    });

    testWidgets('another classroom, a student and the heatmap', (tester) async {
      await pump(tester);
      await tester.tap(find.byKey(const ValueKey('charts_classroom')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ป.5/2').last);
      await tester.pumpAndSettle();
      expect(charts.planCalls.last.classroomId, 8);
      expect(charts.passRateCalls.last.classroomId, 8);

      await tester.tap(find.byKey(const ValueKey('charts_heatmap')));
      await tester.pumpAndSettle();
      expect(find.text('heatmap-8-4'), findsOneWidget);
    });

    testWidgets('tap a student to their charts', (tester) async {
      await pump(tester);
      await tester.tap(find.byKey(const ValueKey('charts_student_55')));
      await tester.pumpAndSettle();
      expect(find.text('student-55-7'), findsOneWidget);
    });

    testWidgets('a course without classrooms says so', (tester) async {
      _tallPhone(tester);
      await pumpScreen(
        tester,
        const CourseChartsScreen(courseId: 4),
        overrides: [
          courseMasteryRepositoryProvider.overrideWithValue(
            FakeCourseMastery(),
          ),
          chartsRepositoryProvider.overrideWithValue(FakeChartsRepository()),
          coursesRepositoryProvider.overrideWithValue(
            FakeCoursesRepository([course(classrooms: const [])]),
          ),
        ],
      );
      expect(find.text('รายวิชานี้ยังไม่ได้ผูกกับห้องเรียน'), findsOneWidget);
    });
  });

  group('one student', () {
    testWidgets('teacher: the student spider and progress lines', (
      tester,
    ) async {
      _tallPhone(tester);
      final mastery = FakeCourseMastery();
      final charts = FakeChartsRepository();
      await pumpScreen(
        tester,
        const StudentCourseChartsScreen(
          courseId: 4,
          studentId: 55,
          classroomId: 7,
        ),
        overrides: [
          courseMasteryRepositoryProvider.overrideWithValue(mastery),
          chartsRepositoryProvider.overrideWithValue(charts),
        ],
      );
      expect(tester.takeException(), isNull);
      expect(find.text('เด็กชายเอ'), findsOneWidget);
      expect(
        mastery.calls.where((c) => c.studentId == 55).single.classroomId,
        7,
      );
      expect(charts.progressCalls.single, (studentId: 55, skillIds: null));
      expect(find.byKey(const ValueKey('progress_chart')), findsOneWidget);
      expect(find.text('72% · 3 ครั้ง'), findsOneWidget);
      expect(find.text('30% · 1 ครั้ง'), findsOneWidget);

      // The tooltip of a day whose last answer came from an exam says so.
      final chart = tester.widget<LineChart>(find.byType(LineChart));
      final items = chart.data.lineTouchData.touchTooltipData.getTooltipItems;
      final bars = chart.data.lineBarsData;
      final homework = items([LineBarSpot(bars[0], 0, bars[0].spots.first)]);
      final exam = items([LineBarSpot(bars[1], 1, bars[1].spots.first)]);
      expect(homework.single!.text, isNot(contains('ข้อสอบ')));
      expect(exam.single!.text, endsWith(' · ข้อสอบ'));
    });

    testWidgets(
      'student: own values only; drill-down adds a line; the picker replaces them',
      (tester) async {
        _tallPhone(tester);
        final mastery = FakeCourseMastery();
        final charts = FakeChartsRepository();
        await pumpScreen(
          tester,
          const MyCourseChartsScreen(courseId: 4),
          overrides: [
            courseMasteryRepositoryProvider.overrideWithValue(mastery),
            chartsRepositoryProvider.overrideWithValue(charts),
          ],
        );
        expect(tester.takeException(), isNull);
        expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
        expect(mastery.calls.every((c) => c.own), isTrue);
        expect(charts.progressCalls.single.studentId, isNull);
        // No classmates, no class average (§20.9).
        expect(find.text('รายคน'), findsNothing);
        expect(find.textContaining('ผ่าน'), findsNothing);
        expect(find.byKey(const ValueKey('rollup_radar')), findsOneWidget);

        // Drill into ค 1.1 and add ค 1.1 ป.5/2 to the progress chart.
        await tester.tap(find.byKey(const ValueKey('rollup_node_0')));
        await tester.pumpAndSettle();
        expect(find.text('เข้าใจดี'), findsWidgets);
        await tester.tap(find.byTooltip('ดูพัฒนาการ').at(1));
        await tester.pumpAndSettle();
        expect(charts.progressCalls.last.skillIds, [1, 2, 3]);
        expect(find.text('ค 1.1 ป.5/2 ตัวชี้วัด ค 1.1 ป.5/2'), findsOneWidget);

        // Remove a line, then pick another set in the dialog.
        await tester.tap(find.byTooltip('เอาเส้นนี้ออก').first);
        await tester.pumpAndSettle();
        expect(charts.progressCalls.last.skillIds, [2, 3]);
        await tester.tap(find.byKey(const ValueKey('progress_pick')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('progress_option_3')));
        await tester.tap(find.byKey(const ValueKey('progress_option_1')));
        await tester.pumpAndSettle();
        await tester.tap(find.text('แสดง'));
        await tester.pumpAndSettle();
        expect(charts.progressCalls.last.skillIds, [1, 2]);

        // Only the own endpoints were called.
        expect(charts.progressCalls.every((c) => c.studentId == null), isTrue);
      },
    );

    testWidgets('the ทักษะ tab lists the courses', (tester) async {
      await pumpScreen(
        tester,
        const Scaffold(body: MyCoursesSection()),
        overrides: [
          courseMasteryRepositoryProvider.overrideWithValue(
            FakeCourseMastery(),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: '/student/courses/:id',
            builder: (_, s) => Text('my-course-${s.pathParameters['id']}'),
          ),
        ],
      );
      expect(find.text('กราฟตามรายวิชา'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('my_course_4')));
      await tester.pumpAndSettle();
      expect(find.text('my-course-4'), findsOneWidget);
    });
  });
}
