import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/charts/charts_repository.dart';
import 'package:eduvision/features/dashboard/analytics_models.dart';
import 'package:eduvision/features/dashboard/analytics_repository.dart';
import 'package:eduvision/features/dashboard/assignment_analytics_screen.dart';
import 'package:eduvision/features/review/review_labels.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_charts.dart';
import '../helpers/fake_http_adapter.dart';

Map<String, dynamic> analyticsJson({int published = 23}) => {
  'assignment_id': 5,
  'published_count': published,
  'min_count_for_r': 20,
  'items': [
    {
      'question_id': 501,
      'position': 1,
      'type': 'short',
      'max_points': 2,
      'prompt_text': '100 + 25 = ?',
      'n': published,
      'p': 0.91,
      'r': 0.12,
    },
    {
      'question_id': 502,
      'position': 2,
      'type': 'show_work',
      'max_points': 3,
      'prompt_text': '3x + 5 = 20',
      'n': published,
      'p': 0.15,
      'r': 0.41,
    },
    {
      'question_id': 503,
      'position': 3,
      'type': 'mcq',
      'max_points': 1,
      'n': published,
      'p': 0.52,
      'r': published >= 20 ? 0.33 : null,
    },
  ],
  'skill_error_counts': [
    {
      'skill': {'id': 7, 'code': 'ค 1.1', 'name': 'สมการ'},
      'error_type': 'calculation',
      'count': 6,
    },
    {
      'skill': {'id': 7, 'code': 'ค 1.1', 'name': 'สมการ'},
      'error_type': 'concept',
      'count': 2,
    },
    {
      'skill': {'id': 8, 'code': 'ค 1.2', 'name': 'บวกเลข'},
      'error_type': 'careless',
      'count': 1,
    },
    {
      'skill': {'id': 8, 'code': 'ค 1.2', 'name': 'บวกเลข'},
      'error_type': 'made_up_type',
      'count': 4,
    },
  ],
};

class _FakeAnalytics implements AnalyticsRepository {
  _FakeAnalytics(this.json);

  final Map<String, dynamic> json;

  @override
  Future<AssignmentAnalytics> assignment(int assignmentId) async =>
      AssignmentAnalytics.fromJson(json);
}

class _FakeAssignments extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async => const Assignment(
    id: 5,
    classroomId: 1,
    subjectId: 1,
    title: 'สมการ',
    status: 'ready',
  );
}

Future<void> _pump(WidgetTester tester, Map<String, dynamic> json) async {
  tester.view.physicalSize = const Size(390, 2400); // phone width
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        analyticsRepositoryProvider.overrideWithValue(_FakeAnalytics(json)),
        assignmentsRepositoryProvider.overrideWithValue(_FakeAssignments()),
        chartsRepositoryProvider.overrideWithValue(FakeChartsRepository()),
      ],
      child: const MaterialApp(
        home: AssignmentAnalyticsScreen(assignmentId: 5),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  group('model (DESIGN §14.3)', () {
    test('most missed = lowest p first; notes for p and r', () {
      final a = AssignmentAnalytics.fromJson(analyticsJson());
      expect(a.mostMissed().map((i) => i.position), [2, 3, 1]);
      final byPos = a.byPosition;
      expect(byPos[0].notes, ['ง่ายเกินไป', 'จำแนกได้น้อย']);
      expect(byPos[1].notes, ['ยากเกินไป']);
      expect(byPos[2].notes, isEmpty);
      expect(a.hasDiscrimination, isTrue);
    });

    test('heatmap pivot ignores unknown error types and empty rows', () {
      final a = AssignmentAnalytics.fromJson(analyticsJson());
      expect(a.heatmapSkills.map((s) => s.code), ['ค 1.1', 'ค 1.2']);
      expect(a.heatmapErrorTypes, [
        ErrorType.concept,
        ErrorType.calculation,
        ErrorType.careless,
      ]);
      expect(a.count(7, ErrorType.calculation), 6);
      expect(a.count(8, ErrorType.concept), 0);
    });

    test('r is withheld below 20 published students', () {
      final a = AssignmentAnalytics.fromJson(analyticsJson(published: 12));
      expect(a.hasDiscrimination, isFalse);
    });
  });

  testWidgets('analytics on a phone: most missed, p/r table and heatmap', (
    tester,
  ) async {
    await _pump(tester, analyticsJson());
    expect(tester.takeException(), isNull);
    expect(find.text('วิเคราะห์ผล: สมการ'), findsOneWidget);
    expect(find.text('23 คน'), findsOneWidget);
    expect(find.text('ข้อที่ทั้งห้องผิดมากที่สุด'), findsOneWidget);
    expect(find.text('p 0.15'), findsOneWidget);
    // Table: p and r written out, quality notes in words.
    expect(find.text('0.41'), findsOneWidget);
    expect(find.text('ยากเกินไป'), findsOneWidget);
    expect(find.text('ง่ายเกินไป · จำแนกได้น้อย'), findsOneWidget);
    // Heatmap with counts in the cells and short column labels.
    final heatmap = find.byKey(const ValueKey('error_heatmap'));
    expect(heatmap, findsOneWidget);
    expect(
      find.descendant(of: heatmap, matching: find.text('คำนวณ')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: heatmap, matching: find.text('6')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: heatmap, matching: find.text('–')),
      findsWidgets,
    );
    expect(find.text('มาก (สูงสุด 6)'), findsOneWidget);
    // The score distribution (DESIGN §20.4 chart 4) with mean and median.
    expect(find.byKey(const ValueKey('score_histogram')), findsOneWidget);
    expect(find.text('6.5/10 (65%)'), findsOneWidget);
    expect(find.text('7.5/10 (75%)'), findsOneWidget);
    expect(find.text('70\nมัธยฐาน'), findsOneWidget);
    expect(find.text('มีคะแนน 5 จาก 6 คนที่เผยแพร่แล้ว'), findsOneWidget);
  });

  testWidgets('fewer than 20 students: r shows "น้อย"', (tester) async {
    await _pump(tester, analyticsJson(published: 12));
    expect(find.text('ข้อมูลน้อย'), findsNWidgets(3));
    expect(tester.takeException(), isNull);
    expect(
      find.textContaining('r แสดงเมื่อเผยแพร่แล้ว 20 คนขึ้นไป'),
      findsOneWidget,
    );
  });

  testWidgets('nothing published yet: an empty state', (tester) async {
    await _pump(tester, {'published_count': 0, 'items': <Object>[]});
    expect(find.text('ยังไม่มีผลที่เผยแพร่'), findsOneWidget);
  });

  test('repository path', () async {
    final adapter = FakeHttpAdapter(
      (o) async => jsonResponse(200, {'data': analyticsJson()}),
    );
    final a = await ApiAnalyticsRepository(fakeDio(adapter)).assignment(5);
    expect(adapter.requests.single.uri.path, '/api/v1/assignments/5/analytics');
    expect(a.items, hasLength(3));
  });
}
