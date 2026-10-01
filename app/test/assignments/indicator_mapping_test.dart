import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_detail_screen.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/indicator_mapping.dart';
import 'package:eduvision/features/assignments/indicator_mapping_screen.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/fake_api_server.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';

/// A question of `GET …/indicator-suggestions`.
Map<String, dynamic> _question(
  int id,
  int position, {
  List<Skill> skills = const [],
  List<(Skill, String)> suggestions = const [],
  String type = 'short',
}) => {
  'question_id': id,
  'position': position,
  'type': type,
  'prompt_text': 'โจทย์ข้อ $position',
  'skill_ids': [for (final s in skills) s.id],
  'skills': [for (final s in skills) skillJson(s)],
  'suggestions': [
    for (final (s, reason) in suggestions)
      {'skill': skillJson(s), 'reason_th': reason},
  ],
};

Map<String, dynamic> _payload({
  bool plan = true,
  List<Skill> planIndicators = const [fraction, decimal],
  String? status,
  Map<String, dynamic>? error,
  int? suggested,
  int? dropped,
  List<Map<String, dynamic>>? questions,
  int? changed,
  String? guidance,
  String? source,
  Map<String, dynamic>? course,
}) {
  final qs =
      questions ??
      [
        _question(501, 1, suggestions: [(fraction, 'โจทย์ให้บวกเศษส่วน')]),
        _question(502, 2, skills: const [decimal]),
        _question(503, 3),
      ];
  final unmapped = qs.where((q) => (q['skill_ids'] as List).isEmpty).length;
  return {
    'assignment_id': 12,
    'lesson_plan': plan
        ? {'id': 30, 'title': 'การบวกเศษส่วน', 'unit_id': 20}
        : null,
    'indicator_source': source ?? (plan ? 'lesson_plan' : null),
    'course': course,
    'plan_indicators': [for (final s in planIndicators) skillJson(s)],
    'status': status,
    'requested_at': status == null ? null : '2026-09-30T01:00:00+00:00',
    'finished_at': null,
    'error': error,
    'suggested_question_count': suggested,
    'dropped_code_count': dropped,
    'guidance': guidance,
    'questions': qs,
    'unmapped_question_count': unmapped,
    'unmapped_warning': unmappedWarningText(unmapped),
    'changed_question_count': ?changed,
  };
}

class _Mapping implements IndicatorMappingRepository {
  _Mapping(this.gets);

  /// Answers of `suggestions()`, in order (the last one repeats).
  final List<Map<String, dynamic>> gets;
  final saves = <Map<int, List<int>>>[];
  int requests = 0;
  final requestGuidance = <String?>[];
  Object? requestError;

  /// `guidance` of the round the POST answers (null = the sent one).
  String? runningGuidance;
  Map<String, dynamic>? saveAnswer;

  @override
  Future<IndicatorSuggestions> suggestions(int assignmentId) async =>
      IndicatorSuggestions.fromJson(
        gets.length > 1 ? gets.removeAt(0) : gets.single,
      );

  @override
  Future<SuggestState> requestSuggestions(
    int assignmentId, {
    String? guidance,
  }) async {
    requests++;
    requestGuidance.add(guidance);
    if (requestError case final e?) throw e;
    return SuggestState.fromJson({
      'status': 'queued',
      'requested_at': '2026-09-30T01:00:00+00:00',
      'guidance': runningGuidance ?? guidance,
    });
  }

  @override
  Future<IndicatorSuggestions> saveMapping(
    int assignmentId,
    Map<int, List<int>> skillIds,
  ) async {
    saves.add(skillIds);
    return IndicatorSuggestions.fromJson(saveAnswer ?? gets.last);
  }
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.assignment);

  final Assignment assignment;

  @override
  Future<Assignment> get(int id) async => assignment;

  @override
  Future<List<Skill>> searchSkills({
    int? subjectId,
    int? grade,
    String? q,
    String? level,
  }) async => const [fraction, decimal, schoolSkill];
}

const _assignment = Assignment(
  id: 12,
  classroomId: 7,
  subjectId: 1,
  title: 'เศษส่วน',
  courseId: 4,
  lessonPlanId: 30,
  lessonPlanTitle: 'การบวกเศษส่วน',
  unmappedQuestionCount: 2,
  questions: [
    Question(
      id: 501,
      position: 1,
      type: QuestionType.short,
      promptText: '1/2 + 1/4',
      maxPoints: 1,
    ),
    Question(
      id: 502,
      position: 2,
      type: QuestionType.short,
      promptText: '0.5 + 0.25',
      maxPoints: 1,
      skills: [decimal],
    ),
  ],
);

/// An exam of course ค15101 not linked to a lesson plan (§22.13).
const _exam = Assignment(
  id: 12,
  classroomId: 7,
  subjectId: 1,
  title: 'สอบกลางภาค',
  courseId: 4,
  kind: Assignment.kindExam,
);

const _courseJson = {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'};

void main() {
  group('IndicatorMappingRepository', () {
    late List<(String, String, Map<String, dynamic>)> sent;

    ApiIndicatorMappingRepository repo(Object? Function(String) answer) {
      sent = [];
      final adapter = FakeHttpAdapter((o) async {
        final path = FakeApiServer.apiPath(o.uri).replaceFirst('/api/v1', '');
        sent.add((o.method, path, FakeApiServer.bodyOf(o)));
        return jsonResponse(o.method == 'POST' ? 202 : 200, answer(o.method));
      });
      return ApiIndicatorMappingRepository(fakeDio(adapter));
    }

    test('reads suggestions, the plan and the unmapped warning', () async {
      final r = repo(
        (_) => {'data': _payload(status: 'done', suggested: 1, dropped: 2)},
      );
      final data = await r.suggestions(12);
      expect(sent.single.$1, 'GET');
      expect(sent.single.$2, '/assignments/12/indicator-suggestions');
      expect(data.lessonPlan?.title, 'การบวกเศษส่วน');
      expect(data.lessonPlan?.unitId, 20);
      expect(data.planIndicators, [fraction, decimal]);
      expect(data.state.status, SuggestStatus.done);
      expect(data.state.suggestedQuestionCount, 1);
      expect(data.state.droppedCodeCount, 2);
      expect(data.state.requestedAt, DateTime.utc(2026, 9, 30, 1));
      expect(data.questions.map((q) => q.questionId), [501, 502, 503]);
      final first = data.questions.first;
      expect(first.type, QuestionType.short);
      expect(first.unmapped, isTrue);
      expect(first.suggestions.single.skill, fraction);
      expect(first.suggestions.single.reason, 'โจทย์ให้บวกเศษส่วน');
      expect(data.questions[1].skills, [decimal]);
      expect(data.unmappedQuestionCount, 2);
      expect(
        data.unmappedWarning,
        'มี 2 ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ',
      );
      expect(data.canSuggest, isTrue);
      expect(data.hasSuggestions, isTrue);
    });

    test('requests a suggestion and reads the failed state', () async {
      final r = repo(
        (_) => {
          'data': {
            'status': 'failed',
            'error': {'code': 'ai_failed', 'message': 'ลองอีกครั้ง'},
          },
        },
      );
      final state = await r.requestSuggestions(12);
      expect(sent.single.$1, 'POST');
      expect(sent.single.$2, '/assignments/12/indicator-suggestions');
      expect(state.status, SuggestStatus.failed);
      expect(state.errorCode, 'ai_failed');
      expect(state.errorMessage, 'ลองอีกครั้ง');
    });

    test('saves only the questions given', () async {
      final r = repo((_) => {'data': _payload(changed: 1)});
      final saved = await r.saveMapping(12, {
        501: [1, 2],
        503: [],
      });
      expect(sent.single.$1, 'PUT');
      expect(sent.single.$2, '/assignments/12/indicator-mapping');
      expect(sent.single.$3, {
        'questions': [
          {
            'question_id': 501,
            'skill_ids': [1, 2],
          },
          {'question_id': 503, 'skill_ids': <int>[]},
        ],
      });
      expect(saved.changedQuestionCount, 1);
    });

    test('an assignment without a plan cannot ask AI', () {
      final data = IndicatorSuggestions.fromJson({
        ..._payload(plan: false, planIndicators: const []),
        'unmapped_warning': null,
        'unmapped_question_count': null,
      });
      expect(data.lessonPlan, isNull);
      expect(data.canSuggest, isFalse);
      expect(data.state.status, SuggestStatus.none);
      // Falls back to counting the questions itself.
      expect(data.unmappedQuestionCount, 2);
      expect(data.unmappedWarning, startsWith('มี 2 ข้อ'));
      expect(unmappedWarningText(0), isNull);
    });
  });

  group('Assignment.unmappedQuestionCount', () {
    test('comes from the server, else from the questions', () {
      final base = {
        'id': 12,
        'classroom_id': 7,
        'subject_id': 1,
        'title': 'เศษส่วน',
        'questions': [
          {
            'id': 1,
            'position': 1,
            'type': 'short',
            'max_points': 1,
            'skills': const [],
          },
        ],
      };
      expect(Assignment.fromJson(base).unmappedQuestionCount, 1);
      expect(
        Assignment.fromJson({
          ...base,
          'unmapped_question_count': 3,
        }).unmappedQuestionCount,
        3,
      );
      expect(
        Assignment.fromJson(base).withGoogleLink(null).unmappedQuestionCount,
        1,
      );
    });
  });

  group('exam indicators from the course (§22.13)', () {
    test('an exam without a plan picks from its course and can ask AI', () {
      final data = IndicatorSuggestions.fromJson(
        _payload(
          plan: false,
          source: 'course',
          course: _courseJson,
          questions: [
            _question(501, 1, type: 'mcq'),
            _question(502, 2, type: 'true_false'),
            _question(503, 3, type: 'numeric'),
          ],
        ),
      );
      expect(data.lessonPlan, isNull);
      expect(data.indicatorSource, IndicatorSource.course);
      expect(data.fromCourse, isTrue);
      expect(data.course?.label, 'ค15101 คณิตศาสตร์ 5');
      expect(data.canSuggest, isTrue);
      expect(data.questions.map((q) => q.typeLabel), [
        'ปรนัย',
        'ถูก/ผิด',
        'เติมตัวเลข',
      ]);
      expect(data.questions[1].type, isNull);
      // The state update of a request keeps the scope.
      final next = data.withState(
        SuggestState.fromJson(const {'status': 'queued'}),
      );
      expect(next.fromCourse, isTrue);
      expect(next.course?.id, 4);
    });

    test('an older payload without indicator_source still reads the plan', () {
      final json = _payload()..remove('indicator_source');
      final data = IndicatorSuggestions.fromJson(json);
      expect(data.indicatorSource, isNull);
      expect(data.hasScope, isTrue);
      expect(data.canSuggest, isTrue);
      expect(
        IndicatorSource.fromApi('lesson_plan'),
        IndicatorSource.lessonPlan,
      );
      expect(const LinkedCourse(id: 1).label, '');
    });
  });

  group('IndicatorMappingScreen', () {
    Future<void> pump(
      WidgetTester tester,
      _Mapping mapping, {
      Assignment assignment = _assignment,
    }) async {
      tall(tester);
      await pumpScreen(
        tester,
        const IndicatorMappingScreen(assignmentId: 12),
        overrides: [
          indicatorMappingRepositoryProvider.overrideWithValue(mapping),
          indicatorSuggestPollIntervalProvider.overrideWithValue(
            const Duration(milliseconds: 10),
          ),
          assignmentsRepositoryProvider.overrideWithValue(
            _Assignments(assignment),
          ),
          classroomsRepositoryProvider.overrideWithValue(FakeClassrooms()),
        ],
      );
    }

    testWidgets('an exam without a plan asks AI from its course', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(
          plan: false,
          source: 'course',
          course: _courseJson,
          dropped: 1,
          status: 'done',
          suggested: 1,
          questions: [
            _question(
              501,
              1,
              type: 'true_false',
              suggestions: [(fraction, 'เศษส่วน')],
            ),
          ],
        ),
      ]);
      await pump(tester, mapping, assignment: _exam);
      expect(find.text('รายวิชา: ค15101 คณิตศาสตร์ 5'), findsOneWidget);
      expect(
        find.textContaining('AI เลือกได้จากตัวชี้วัดทั้งรายวิชา'),
        findsOneWidget,
      );
      expect(
        find.textContaining('ตัดรหัสที่ไม่อยู่ในรายวิชาออก 1 รหัส'),
        findsOneWidget,
      );
      expect(find.text('ถูก/ผิด'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('mapping_suggest')));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('AI เลือกได้เฉพาะตัวชี้วัดของรายวิชานี้'),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pump();
      expect(mapping.requests, 1);
    });

    testWidgets('an exam without a plan or course picks alone; an empty '
        'course explains why AI cannot suggest', (tester) async {
      await pump(
        tester,
        _Mapping([
          _payload(plan: false, planIndicators: const [], questions: const []),
        ]),
        assignment: _exam,
      );
      expect(find.text('ยังไม่ผูกรายวิชาหรือแผนการสอน'), findsOneWidget);
      expect(find.textContaining('หน้าตั้งค่าข้อสอบ'), findsOneWidget);
      expect(find.text('ข้อสอบนี้ยังไม่มีข้อ'), findsOneWidget);
      expect(find.byKey(const ValueKey('mapping_suggest')), findsNothing);
      await unmountScreen(tester);

      await pump(
        tester,
        _Mapping([
          _payload(
            plan: false,
            source: 'course',
            course: _courseJson,
            planIndicators: const [],
            questions: [_question(501, 1, type: 'mcq')],
          ),
        ]),
        assignment: _exam,
      );
      expect(
        find.textContaining('รายวิชานี้ยังไม่มีตัวชี้วัด'),
        findsOneWidget,
      );
      expect(
        tester
            .widget<FilledButton>(find.byKey(const ValueKey('mapping_suggest')))
            .onPressed,
        isNull,
      );
    });

    testWidgets('accepts a suggestion and saves only the changed question', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(status: 'done', suggested: 1, dropped: 1),
      ]);
      mapping.saveAnswer = _payload(
        changed: 1,
        questions: [
          _question(501, 1, skills: const [fraction]),
          _question(502, 2, skills: const [decimal]),
          _question(503, 3),
        ],
      );
      await pump(tester, mapping);

      expect(find.text('แผน: การบวกเศษส่วน'), findsOneWidget);
      expect(
        find.text(
          'AI เสนอตัวชี้วัดให้ 1 ข้อ · ตัดรหัสที่ไม่อยู่ในแผนออก 1 รหัส',
        ),
        findsOneWidget,
      );
      expect(find.text('ให้ AI เสนอใหม่'), findsOneWidget);
      expect(
        find.text('มี 2 ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ'),
        findsOneWidget,
      );
      expect(find.text('ยังไม่ผูกตัวชี้วัด'), findsNWidgets(2));
      expect(find.text('โจทย์ให้บวกเศษส่วน'), findsOneWidget);
      final save = find.byKey(const ValueKey('mapping_save'));
      expect(tester.widget<TextButton>(save).onPressed, isNull);

      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('suggestion_501_1')),
          matching: find.text('ใช้'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่บันทึก'), findsOneWidget);
      expect(find.textContaining('มี 1 ข้อยังไม่ผูก'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsOneWidget);

      await tester.tap(save);
      await tester.pumpAndSettle();
      expect(mapping.saves.single, {
        501: [1],
      });
      expect(find.text('บันทึกตัวชี้วัดแล้ว 1 ข้อ'), findsOneWidget);
      expect(find.text('ยังไม่บันทึก'), findsNothing);
      expect(tester.widget<TextButton>(save).onPressed, isNull);
    });

    testWidgets('"ใช้ข้อเสนอทั้งหมด" and removing a chip change the draft', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(
          status: 'done',
          suggested: 2,
          questions: [
            _question(501, 1, suggestions: [(fraction, 'บวกเศษส่วน')]),
            _question(
              502,
              2,
              skills: const [decimal],
              suggestions: [(decimal, 'ทศนิยม'), (fraction, 'เศษส่วน')],
            ),
          ],
        ),
      ]);
      await pump(tester, mapping);

      await tester.tap(find.byKey(const ValueKey('mapping_accept_all')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('mapping_unmapped')), findsNothing);
      expect(find.text('ยังไม่บันทึก'), findsNWidgets(2));

      // Question 2 back to what the server has: no longer changed.
      final q2 = find.byKey(const ValueKey('mapping_q_502'));
      await tester.tap(
        find.descendant(of: q2, matching: find.byTooltip('เอาออก')).last,
      );
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่บันทึก'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('mapping_save')));
      await tester.pumpAndSettle();
      expect(mapping.saves.single, {
        501: [1],
      });
    });

    testWidgets('asks AI, polls while queued, then shows the suggestions', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(questions: [_question(501, 1)]),
        _payload(status: 'queued', questions: [_question(501, 1)]),
        _payload(
          status: 'done',
          suggested: 1,
          questions: [
            _question(501, 1, suggestions: [(fraction, 'บวกเศษส่วน')]),
          ],
        ),
      ]);
      await pump(tester, mapping);
      expect(find.text('ให้ AI เสนอตัวชี้วัด'), findsOneWidget);
      expect(find.byKey(const ValueKey('mapping_accept_all')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('mapping_suggest')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pump();
      await tester.pump();
      expect(mapping.requests, 1);
      expect(mapping.requestGuidance, [null]);
      expect(find.byKey(const ValueKey('mapping_queued')), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const ValueKey('mapping_suggest')))
            .onPressed,
        isNull,
      );

      await tester.pump(const Duration(milliseconds: 20));
      await tester.pump(const Duration(milliseconds: 20));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('mapping_done')), findsOneWidget);
      expect(find.text('บวกเศษส่วน'), findsOneWidget);
      expect(find.byKey(const ValueKey('mapping_accept_all')), findsOneWidget);
    });

    testWidgets('the guidance is asked, sent, shown and prefilled', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(status: 'done', suggested: 1, guidance: 'ข้อ 1 เรื่องบวก'),
        _payload(status: 'queued', guidance: 'เน้นเหตุผล'),
        _payload(status: 'done', suggested: 1, guidance: 'เน้นเหตุผล'),
      ]);
      await pump(tester, mapping);
      expect(find.text('คำแนะนำที่ใช้: ข้อ 1 เรื่องบวก'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('mapping_suggest')));
      await tester.pumpAndSettle();
      expect(find.text('อย่าใส่ชื่อหรือข้อมูลของนักเรียน'), findsOneWidget);
      final field = find.descendant(
        of: find.byType(AlertDialog),
        matching: find.byKey(const ValueKey('ai_guidance')),
      );
      expect(
        tester.widget<TextField>(field).controller!.text,
        'ข้อ 1 เรื่องบวก',
      );
      await tester.enterText(field, 'เน้นเหตุผล');
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pumpAndSettle();
      expect(mapping.requestGuidance, ['เน้นเหตุผล']);
      expect(find.text('คำแนะนำที่ใช้: เน้นเหตุผล'), findsOneWidget);

      // Cancelling the dialog sends nothing.
      await tester.tap(find.byKey(const ValueKey('mapping_suggest')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(mapping.requests, 1);
    });

    testWidgets('a round still queued keeps its own guidance', (tester) async {
      final mapping = _Mapping([_payload(status: 'done', suggested: 1)])
        ..runningGuidance = 'ของรอบก่อน';
      await pump(tester, mapping);
      await tester.tap(find.byKey(const ValueKey('mapping_suggest')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('ai_guidance')),
        'ของใหม่',
      );
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pump();
      await tester.pump();
      expect(find.textContaining('ใช้คำแนะนำของรอบนั้น'), findsOneWidget);
      expect(find.text('คำแนะนำที่ใช้: ของรอบก่อน'), findsOneWidget);
      await tester.pumpAndSettle();
    });

    testWidgets('a failed request shows its message; a 422 shows the error', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(
          status: 'failed',
          error: {
            'code': 'ai_key_invalid',
            'message': 'Google ไม่รับ Gemini API key นี้',
          },
        ),
      ]);
      mapping.requestError = DioException(
        requestOptions: RequestOptions(path: '/x'),
        response: Response(
          requestOptions: RequestOptions(path: '/x'),
          statusCode: 422,
          data: {
            'message': 'ยังไม่มี Gemini API key ให้ใช้',
            'errors': {},
            'code': 'ai_key_missing',
          },
        ),
        type: DioExceptionType.badResponse,
      );
      await pump(tester, mapping);
      expect(find.text('Google ไม่รับ Gemini API key นี้'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('mapping_suggest')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('ยังไม่ได้ใส่ Gemini API key'),
        findsOneWidget,
      );
      expect(find.text('ไปใส่ key'), findsOneWidget);
    });

    testWidgets('without a plan the teacher picks indicators alone', (
      tester,
    ) async {
      final mapping = _Mapping([
        _payload(
          plan: false,
          planIndicators: const [],
          questions: [_question(501, 1)],
        ),
      ]);
      await pump(tester, mapping);
      expect(find.text('ยังไม่ผูกแผนการสอน'), findsOneWidget);
      expect(find.byKey(const ValueKey('mapping_suggest')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('mapping_pick_501')));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(CheckboxListTile, 'บวกลบเศษส่วน'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'ใช้ 1 รายการ'));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่บันทึก'), findsOneWidget);
      expect(find.byKey(const ValueKey('mapping_unmapped')), findsNothing);
    });

    testWidgets('a plan without indicators explains why AI cannot suggest', (
      tester,
    ) async {
      await pump(
        tester,
        _Mapping([
          _payload(planIndicators: const [], questions: [_question(501, 1)]),
        ]),
      );
      expect(find.textContaining('แผนนี้ยังไม่มีตัวชี้วัด'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const ValueKey('mapping_suggest')))
            .onPressed,
        isNull,
      );
    });

    testWidgets('leaving with unsaved choices asks first', (tester) async {
      await pump(tester, _Mapping([_payload(status: 'done', suggested: 1)]));
      await tester.tap(find.byKey(const ValueKey('mapping_accept_all')));
      await tester.pumpAndSettle();

      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('ทิ้งการแก้ไข?'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(find.text('จับคู่ตัวชี้วัด'), findsOneWidget);

      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.text('ทิ้ง'));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsOneWidget);
    });
  });

  testWidgets('the assignment warns about unmapped questions and opens the '
      'mapping', (tester) async {
    tall(tester);
    await pumpScreen(
      tester,
      const AssignmentDetailScreen(assignmentId: 12),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(
          _Assignments(_assignment),
        ),
        classroomsRepositoryProvider.overrideWithValue(FakeClassrooms()),
        googleClassroomEnabledProvider.overrideWithValue(false),
      ],
      extraRoutes: [stubRoute('/assignments/:id/indicators', 'mapping')],
    );
    expect(
      find.text('มี 2 ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ'),
      findsOneWidget,
    );
    expect(find.text('ยังไม่ผูกตัวชี้วัด'), findsOneWidget);
    expect(find.textContaining('ให้ AI เสนอตัวชี้วัดจากแผน'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assignment_unmapped')));
    await tester.pumpAndSettle();
    expect(find.text('mapping /assignments/12/indicators'), findsOneWidget);
  });
}
