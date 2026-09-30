import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/charts/charts_repository.dart';
import 'package:eduvision/features/dashboard/analytics_models.dart';
import 'package:eduvision/features/dashboard/analytics_repository.dart';
import 'package:eduvision/features/dashboard/assignment_analytics_screen.dart';
import 'package:eduvision/features/exams/exam_option_analysis.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_charts.dart';
import '../helpers/fake_http_adapter.dart';

Map<String, dynamic> _option(
  int position,
  String label, {
  bool correct = false,
  int count = 0,
  double? pct,
  int? top,
  int? bottom,
  List<String> flags = const [],
}) => {
  'position': position,
  'label': label,
  'correct': correct,
  'count': count,
  'pct': pct,
  'top': top,
  'bottom': bottom,
  'flags': flags,
};

/// `GET /exams/{id}/option-analysis` (ExamOptionAnalysis::of): 22 published
/// students, groups of 6; q11 has an unused and a reversed distractor, q21
/// is a clean true/false question and q31 a numeric one.
Map<String, dynamic> optionAnalysisJson({bool ready = true}) => {
  'exam_id': 40,
  'published_count': ready ? 22 : 12,
  'groups_ready': ready,
  'min_count_for_r': 20,
  'group_size': ready ? 6 : null,
  'questions': [
    {
      'question_id': 21,
      'position': 3,
      'section_id': 2,
      'type': 'true_false',
      'prompt_text': 'ดวงอาทิตย์ขึ้นทางทิศตะวันตก',
      'p': 0.82,
      'r': ready ? 0.3 : null,
      'options': [
        _option(1, 'ถ', count: 4, pct: 18.2, top: ready ? 0 : null),
        _option(2, 'ผ', correct: true, count: 18, pct: 81.8),
      ],
      'blank': {'count': 0, 'pct': 0.0},
      'multiple': {'count': 0, 'pct': 0.0},
      'flag_count': 0,
    },
    {
      'question_id': 11,
      'position': 1,
      'section_id': 1,
      'type': 'mcq',
      'prompt_text': '2 + 2 เท่ากับเท่าใด',
      'p': 0.5,
      'r': ready ? 0.12 : null,
      'options': [
        _option(
          1,
          'ก',
          count: 0,
          pct: 0.0,
          top: ready ? 0 : null,
          bottom: ready ? 0 : null,
          flags: ready ? ['unused_distractor'] : [],
        ),
        _option(
          2,
          'ข',
          count: 9,
          pct: 40.9,
          top: ready ? 4 : null,
          bottom: ready ? 1 : null,
          flags: ready ? ['reversed_distractor', 'future_flag'] : [],
        ),
        _option(
          3,
          'ค',
          correct: true,
          count: 11,
          pct: 50.0,
          top: ready ? 2 : null,
          bottom: ready ? 3 : null,
        ),
        _option(4, 'ง', count: 1, pct: 4.5, top: ready ? 0 : null),
      ],
      'blank': {'count': 1, 'pct': 4.5},
      'multiple': {'count': 0, 'pct': 0.0},
      'flag_count': ready ? 2 : 0,
    },
    {
      'question_id': 31,
      'position': 4,
      'section_id': 3,
      'type': 'numeric',
      'prompt_text': '1 ÷ 2 =',
      'p': 0.7,
      'r': null,
      'options': <Object>[],
      'blank': {'count': 2, 'pct': 9.1},
      'multiple': {'count': 0, 'pct': 0.0},
      'flag_count': 0,
    },
  ],
};

Map<String, dynamic> _itemsJson(int published) => {
  'assignment_id': 40,
  'published_count': published,
  'min_count_for_r': 20,
  'items': [
    {
      'question_id': 11,
      'position': 1,
      'type': 'mcq',
      'max_points': 1,
      'prompt_text': '2 + 2 เท่ากับเท่าใด',
      'n': published,
      'p': 0.5,
      'r': published >= 20 ? 0.12 : null,
    },
    {
      'question_id': 21,
      'position': 3,
      'type': 'true_false',
      'max_points': 1,
      'n': published,
      'p': 0.82,
      'r': published >= 20 ? 0.3 : null,
    },
  ],
  'skill_error_counts': <Object>[],
};

class _Analytics implements AnalyticsRepository {
  _Analytics(this.json);

  final Map<String, dynamic> json;

  @override
  Future<AssignmentAnalytics> assignment(int assignmentId) async =>
      AssignmentAnalytics.fromJson(json);
}

class _ExamAnalysis implements ExamAnalysisRepository {
  _ExamAnalysis(this.json);

  final Map<String, dynamic> json;
  int calls = 0;

  @override
  Future<ExamOptionAnalysis> optionAnalysis(int examId) async {
    calls++;
    return ExamOptionAnalysis.fromJson(json);
  }
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.kind);

  final String kind;

  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 7,
    subjectId: 1,
    title: 'สอบกลางภาค',
    status: 'ready',
    kind: kind,
  );
}

Future<_ExamAnalysis> _pump(
  WidgetTester tester, {
  Map<String, dynamic>? analysis,
  int published = 22,
  String kind = Assignment.kindExam,
}) async {
  tester.view.physicalSize = const Size(390, 4000); // phone width
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final exam = _ExamAnalysis(analysis ?? optionAnalysisJson());
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        analyticsRepositoryProvider.overrideWithValue(
          _Analytics(_itemsJson(published)),
        ),
        examAnalysisRepositoryProvider.overrideWithValue(exam),
        assignmentsRepositoryProvider.overrideWithValue(_Assignments(kind)),
        chartsRepositoryProvider.overrideWithValue(FakeChartsRepository()),
      ],
      child: const MaterialApp(
        home: AssignmentAnalyticsScreen(assignmentId: 40),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return exam;
}

void main() {
  group('ExamOptionAnalysis (DESIGN §22.13)', () {
    test('parses options, flags, blank and multiple in question order', () {
      final a = ExamOptionAnalysis.fromJson(optionAnalysisJson());
      expect(a.examId, 40);
      expect(a.publishedCount, 22);
      expect(a.groupsReady, isTrue);
      expect(a.groupSize, 6);
      expect(a.questions.map((q) => q.position), [1, 3, 4]);
      final q = a.questions.first;
      expect(q.type, 'mcq');
      expect(q.p, 0.5);
      expect(q.options.map((o) => o.label), ['ก', 'ข', 'ค', 'ง']);
      expect(q.options[0].flags, [OptionFlag.unusedDistractor]);
      // Unknown flags are ignored.
      expect(q.options[1].flags, [OptionFlag.reversedDistractor]);
      expect(q.options[1].top, 4);
      expect(q.options[1].bottom, 1);
      expect(q.options[2].correct, isTrue);
      expect(q.flagCount, 2);
      expect(q.blank.count, 1);
      expect(q.blank.pct, 4.5);
      expect(q.multiple.count, 0);
      final numeric = a.questions.last;
      expect(numeric.hasOptions, isFalse);
      expect(numeric.blank.count, 2);
      expect(a.flagged.map((q) => q.questionId), [11]);
    });

    test('below 20 students there are no groups and no flags', () {
      final a = ExamOptionAnalysis.fromJson(optionAnalysisJson(ready: false));
      expect(a.groupsReady, isFalse);
      expect(a.groupSize, isNull);
      expect(a.flagged, isEmpty);
      expect(a.questions.first.options[1].top, isNull);
    });

    test('tolerates missing fields', () {
      final a = ExamOptionAnalysis.fromJson({
        'questions': [
          {
            'question_id': '9',
            'position': '2',
            'options': [
              {'position': 1},
            ],
            'blank': null,
          },
        ],
      });
      expect(a.minCountForR, 20);
      expect(a.questions.single.questionId, 9);
      expect(a.questions.single.options.single.label, '1');
      expect(a.questions.single.blank.count, 0);
      expect(a.questions.single.blank.pct, isNull);
      expect(OptionFlag.fromApi('nope'), isNull);
    });

    test('repository path', () async {
      final adapter = FakeHttpAdapter(
        (o) async => jsonResponse(200, {'data': optionAnalysisJson()}),
      );
      final a = await ApiExamAnalysisRepository(
        fakeDio(adapter),
      ).optionAnalysis(40);
      expect(
        adapter.requests.single.uri.path,
        '/api/v1/exams/40/option-analysis',
      );
      expect(a.questions, hasLength(3));
    });
  });

  group('exam analytics screen', () {
    testWidgets('shows the option analysis with flags in words', (
      tester,
    ) async {
      await _pump(tester);
      expect(tester.takeException(), isNull);
      expect(find.text('วิเคราะห์ผล: สอบกลางภาค'), findsOneWidget);
      // p / r of §14.3 and the score distribution stay; the heatmap of
      // homework error types does not apply to an exam.
      expect(find.text('ค่าความยากง่าย (p) และอำนาจจำแนก (r)'), findsOneWidget);
      expect(find.byKey(const ValueKey('score_histogram')), findsOneWidget);
      expect(find.text('ทักษะ × ประเภทข้อผิดพลาด'), findsNothing);

      expect(find.text('วิเคราะห์ตัวเลือก'), findsOneWidget);
      expect(
        find.text(
          'กลุ่มสูงและกลุ่มต่ำ (27%) กลุ่มละ 6 คน ตามคะแนนรวมที่ใช้จริง',
        ),
        findsOneWidget,
      );
      final q11 = find.byKey(const ValueKey('option_q_11'));
      expect(
        find.descendant(of: q11, matching: find.text('2 ป้าย')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: q11, matching: find.text('ตัวลวงที่ไม่มีใครเลือก')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: q11,
          matching: find.text('ตัวลวงที่กลุ่มสูงเลือกมากกว่ากลุ่มต่ำ'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: q11, matching: find.text('9 คน · 40.9%')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: q11,
          matching: find.text('กลุ่มสูง 4 · กลุ่มต่ำ 1'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: q11,
          matching: find.text('เฉลย · กลุ่มสูง 2 · กลุ่มต่ำ 3'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: q11, matching: find.byIcon(Icons.check_circle)),
        findsOneWidget,
      );
      // The numeric question has no options: only "no readable value".
      final q31 = find.byKey(const ValueKey('option_q_31'));
      expect(
        find.descendant(
          of: q31,
          matching: find.text('ไม่ตอบหรืออ่านค่าไม่ได้'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: q31, matching: find.text('ฝนหลายตัว')),
        findsNothing,
      );
    });

    testWidgets('"เฉพาะข้อที่มีป้ายเตือน" filters the questions', (
      tester,
    ) async {
      await _pump(tester);
      expect(find.byKey(const ValueKey('option_q_21')), findsOneWidget);
      await tester.ensureVisible(
        find.byKey(const ValueKey('option_flagged_only')),
      );
      await tester.tap(find.byKey(const ValueKey('option_flagged_only')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('option_q_21')), findsNothing);
      expect(find.byKey(const ValueKey('option_q_31')), findsNothing);
      expect(find.byKey(const ValueKey('option_q_11')), findsOneWidget);
    });

    testWidgets('below 20 students: no groups, no flags, a note', (
      tester,
    ) async {
      await _pump(
        tester,
        analysis: optionAnalysisJson(ready: false),
        published: 12,
      );
      expect(
        find.text(
          'กลุ่มสูง/ต่ำและป้ายเตือนแสดงเมื่อประกาศผลแล้ว 20 คนขึ้นไป '
          '(ตอนนี้ 12 คน)',
        ),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('option_flagged_only')), findsNothing);
      expect(find.textContaining('กลุ่มสูง 4'), findsNothing);
      expect(find.text('ตัวลวงที่ไม่มีใครเลือก'), findsNothing);
      // The key is still marked in words.
      expect(find.text('เฉลย'), findsWidgets);
    });

    testWidgets('a long exam opens only the flagged questions', (tester) async {
      final base = optionAnalysisJson();
      final first = (base['questions'] as List).first as Map<String, dynamic>;
      await _pump(
        tester,
        analysis: {
          ...base,
          'questions': [
            ...(base['questions'] as List),
            for (var i = 0; i < 10; i++)
              {...first, 'question_id': 100 + i, 'position': 10 + i},
          ],
        },
      );
      final closed = find.byKey(const ValueKey('option_q_100'));
      await tester.ensureVisible(closed);
      await tester.pumpAndSettle();
      // Closed: the summary line only, no option rows.
      expect(
        find.descendant(
          of: closed,
          matching: find.textContaining('ถ 4 · ผ (เฉลย) 18'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: closed, matching: find.text('4 คน · 18.2%')),
        findsNothing,
      );
      await tester.tap(
        find.descendant(of: closed, matching: find.text('ข้อ 10')),
      );
      await tester.pumpAndSettle();
      expect(
        find.descendant(of: closed, matching: find.text('4 คน · 18.2%')),
        findsOneWidget,
      );
    });

    testWidgets('homework keeps the heatmap and asks for no option analysis', (
      tester,
    ) async {
      final exam = await _pump(tester, kind: Assignment.kindHomework);
      expect(find.text('ทักษะ × ประเภทข้อผิดพลาด'), findsOneWidget);
      expect(find.text('วิเคราะห์ตัวเลือก'), findsNothing);
      expect(exam.calls, 0);
    });

    testWidgets('pull to refresh reloads the option analysis', (tester) async {
      final exam = await _pump(tester);
      expect(exam.calls, 1);
      await tester
          .widget<RefreshIndicator>(find.byType(RefreshIndicator))
          .onRefresh();
      await tester.pumpAndSettle();
      expect(exam.calls, 2);
    });
  });
}
