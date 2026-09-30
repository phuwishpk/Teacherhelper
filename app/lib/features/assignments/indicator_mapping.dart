import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/teacher_guidance.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../exams/exam_providers.dart';
import '../review/review_labels.dart';
import 'assignments_providers.dart';
import 'question.dart';

/// "มี n ข้อยังไม่ผูกตัวชี้วัด …" (DESIGN §20.3): a warning, never a block.
/// Same wording as the server's `unmapped_warning`, so the mapping screen
/// can show it for the teacher's unsaved choices too.
String? unmappedWarningText(int unmapped) => unmapped > 0
    ? 'มี $unmapped ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ'
    : null;

/// The state of the latest "เสนอตัวชี้วัด" request (kept in the server's
/// cache, DESIGN §20.3): nothing asked yet, queued, done or failed.
enum SuggestStatus {
  none,
  queued,
  done,
  failed;

  static SuggestStatus fromApi(Object? value) => switch (value) {
    'queued' => queued,
    'done' => done,
    'failed' => failed,
    _ => none,
  };
}

/// `{status, requested_at, finished_at, error, suggested_question_count,
/// dropped_code_count, guidance}` of `POST …/indicator-suggestions` and of
/// the GET.
class SuggestState {
  const SuggestState({
    this.status = SuggestStatus.none,
    this.requestedAt,
    this.finishedAt,
    this.errorCode,
    this.errorMessage,
    this.suggestedQuestionCount,
    this.droppedCodeCount,
    this.guidance,
  });

  final SuggestStatus status;
  final DateTime? requestedAt;
  final DateTime? finishedAt;

  /// `ai_failed`, `ai_key_invalid`, `ai_key_missing` or `lesson_plan_required`
  /// (also when an exam lost its plan's or course's indicators).
  final String? errorCode;
  final String? errorMessage;

  /// Questions Gemini found at least one indicator of the plan for.
  final int? suggestedQuestionCount;

  /// Codes Gemini answered that are not in the plan (dropped by the server).
  final int? droppedCodeCount;

  /// "คำแนะนำถึง AI" of this round (DESIGN §21.12); while a round is queued
  /// a new request answers the running round's guidance.
  final String? guidance;

  bool get queued => status == SuggestStatus.queued;

  factory SuggestState.fromJson(Map<String, dynamic> json) {
    final error = json['error'];
    return SuggestState(
      status: SuggestStatus.fromApi(json['status']),
      requestedAt: _date(json['requested_at']),
      finishedAt: _date(json['finished_at']),
      errorCode: error is Map ? error['code'] as String? : null,
      errorMessage: error is Map ? error['message'] as String? : null,
      suggestedQuestionCount: (json['suggested_question_count'] as num?)
          ?.toInt(),
      droppedCodeCount: (json['dropped_code_count'] as num?)?.toInt(),
      guidance: normalizeGuidance(json['guidance'] as String?),
    );
  }

  static DateTime? _date(Object? v) =>
      v is String ? DateTime.tryParse(v) : null;
}

/// One indicator Gemini suggests for a question, with its short reason.
class IndicatorSuggestion {
  const IndicatorSuggestion({required this.skill, this.reason});

  final Skill skill;
  final String? reason;

  factory IndicatorSuggestion.fromJson(Map<String, dynamic> json) =>
      IndicatorSuggestion(
        skill: Skill.fromJson((json['skill'] as Map).cast<String, dynamic>()),
        reason: json['reason_th'] as String?,
      );
}

/// A question with its confirmed indicators (`question_skill`) and the
/// suggestions still in the plan.
class QuestionIndicators {
  const QuestionIndicators({
    required this.questionId,
    required this.position,
    required this.promptText,
    this.type,
    this.apiType,
    this.skills = const [],
    this.suggestions = const [],
  });

  final int questionId;
  final int position;

  /// The homework type; null for an exam's `true_false` / `numeric`.
  final QuestionType? type;

  /// The server's `type`, exam types included.
  final String? apiType;
  final String promptText;
  final List<Skill> skills;
  final List<IndicatorSuggestion> suggestions;

  bool get unmapped => skills.isEmpty;

  /// "ปรนัย", "ถูก/ผิด", "เติมตัวเลข", "แสดงวิธีทำ", …; null when unknown.
  String? get typeLabel =>
      type?.label ?? (apiType == null ? null : questionTypeLabel(apiType!));

  factory QuestionIndicators.fromJson(Map<String, dynamic> json) =>
      QuestionIndicators(
        questionId: (json['question_id'] as num).toInt(),
        position: (json['position'] as num?)?.toInt() ?? 0,
        type: QuestionType.values
            .where((t) => t.apiValue == json['type'])
            .firstOrNull,
        apiType: json['type'] as String?,
        promptText: json['prompt_text'] as String? ?? '',
        skills: [
          for (final s in (json['skills'] as List?) ?? const [])
            if (s is Map) Skill.fromJson(s.cast<String, dynamic>()),
        ],
        suggestions: [
          for (final s in (json['suggestions'] as List?) ?? const [])
            if (s is Map && s['skill'] is Map)
              IndicatorSuggestion.fromJson(s.cast<String, dynamic>()),
        ],
      );
}

/// The lesson plan an assignment is linked to (`{id, title, unit_id}`).
class LinkedPlan {
  const LinkedPlan({required this.id, required this.title, this.unitId});

  final int id;
  final String title;
  final int? unitId;
}

/// The course whose indicators an exam without a plan picks from
/// (`{id, code, name}`, DESIGN §22.13).
class LinkedCourse {
  const LinkedCourse({required this.id, this.code = '', this.name = ''});

  final int id;
  final String code;
  final String name;

  String get label => [code, name].where((s) => s.isNotEmpty).join(' ');
}

/// Where [IndicatorSuggestions.planIndicators] come from (§22.13).
enum IndicatorSource {
  /// The linked lesson plan (homework or exam).
  lessonPlan,

  /// The whole course of an exam not linked to a plan.
  course;

  static IndicatorSource? fromApi(Object? value) => switch (value) {
    'lesson_plan' => lessonPlan,
    'course' => course,
    _ => null,
  };
}

/// `GET /assignments/{id}/indicator-suggestions` (DESIGN §20.3, §20.7), also
/// the answer of `PUT …/indicator-mapping` (+ `changed_question_count`).
/// For an exam without a plan the list comes from its course
/// (`indicator_source = course`, §22.13).
class IndicatorSuggestions {
  const IndicatorSuggestions({
    required this.assignmentId,
    this.lessonPlan,
    this.indicatorSource,
    this.course,
    this.planIndicators = const [],
    this.state = const SuggestState(),
    this.questions = const [],
    this.unmappedQuestionCount = 0,
    this.unmappedWarning,
    this.changedQuestionCount,
  });

  final int assignmentId;
  final LinkedPlan? lessonPlan;

  /// Null when there is nothing to pick from (homework without a plan, an
  /// exam without a plan or course).
  final IndicatorSource? indicatorSource;

  /// The exam's course when [indicatorSource] is [IndicatorSource.course].
  final LinkedCourse? course;

  /// The plan's (or the exam's course's) indicators: the only ones Gemini
  /// may suggest.
  final List<Skill> planIndicators;
  final SuggestState state;
  final List<QuestionIndicators> questions;
  final int unmappedQuestionCount;
  final String? unmappedWarning;
  final int? changedQuestionCount;

  /// A plan, or an exam's course, to pick from.
  bool get hasScope => indicatorSource != null || lessonPlan != null;

  bool get fromCourse => indicatorSource == IndicatorSource.course;

  /// "ให้ AI เสนอ" needs a plan (or an exam's course) with indicators (422
  /// `lesson_plan_required` / `lesson_plan_no_indicators`) and at least one
  /// question.
  bool get canSuggest =>
      hasScope && planIndicators.isNotEmpty && questions.isNotEmpty;

  bool get hasSuggestions => questions.any((q) => q.suggestions.isNotEmpty);

  IndicatorSuggestions withState(SuggestState next) => IndicatorSuggestions(
    assignmentId: assignmentId,
    lessonPlan: lessonPlan,
    indicatorSource: indicatorSource,
    course: course,
    planIndicators: planIndicators,
    state: next,
    questions: questions,
    unmappedQuestionCount: unmappedQuestionCount,
    unmappedWarning: unmappedWarning,
  );

  factory IndicatorSuggestions.fromJson(Map<String, dynamic> json) {
    final plan = json['lesson_plan'];
    final course = json['course'];
    final questions = [
      for (final q in (json['questions'] as List?) ?? const [])
        if (q is Map) QuestionIndicators.fromJson(q.cast<String, dynamic>()),
    ]..sort((a, b) => a.position.compareTo(b.position));
    final unmapped =
        (json['unmapped_question_count'] as num?)?.toInt() ??
        questions.where((q) => q.unmapped).length;
    return IndicatorSuggestions(
      assignmentId: (json['assignment_id'] as num).toInt(),
      lessonPlan: plan is Map
          ? LinkedPlan(
              id: (plan['id'] as num).toInt(),
              title: plan['title'] as String? ?? '',
              unitId: (plan['unit_id'] as num?)?.toInt(),
            )
          : null,
      indicatorSource: IndicatorSource.fromApi(json['indicator_source']),
      course: course is Map && course['id'] is num
          ? LinkedCourse(
              id: (course['id'] as num).toInt(),
              code: course['code'] as String? ?? '',
              name: course['name'] as String? ?? '',
            )
          : null,
      planIndicators: [
        for (final s in (json['plan_indicators'] as List?) ?? const [])
          if (s is Map) Skill.fromJson(s.cast<String, dynamic>()),
      ],
      state: SuggestState.fromJson(json),
      questions: questions,
      unmappedQuestionCount: unmapped,
      unmappedWarning:
          json['unmapped_warning'] as String? ?? unmappedWarningText(unmapped),
      changedQuestionCount: (json['changed_question_count'] as num?)?.toInt(),
    );
  }
}

/// Indicator suggestions and the question → indicator mapping of one
/// assignment (DESIGN §20.7).
abstract class IndicatorMappingRepository {
  /// `GET /assignments/{id}/indicator-suggestions`.
  Future<IndicatorSuggestions> suggestions(int assignmentId);

  /// `POST /assignments/{id}/indicator-suggestions {guidance?}` (202):
  /// queues the suggestion with the teacher's [guidance] (DESIGN §21.12);
  /// 422 `lesson_plan_required`, `lesson_plan_no_indicators`,
  /// `no_questions`, `ai_key_missing` or `validation_failed`.
  Future<SuggestState> requestSuggestions(int assignmentId, {String? guidance});

  /// `PUT /assignments/{id}/indicator-mapping`: replaces the indicators of
  /// the questions given only ([skillIds] by question id).
  Future<IndicatorSuggestions> saveMapping(
    int assignmentId,
    Map<int, List<int>> skillIds,
  );
}

class ApiIndicatorMappingRepository implements IndicatorMappingRepository {
  ApiIndicatorMappingRepository(this._dio);

  final Dio _dio;

  @override
  Future<IndicatorSuggestions> suggestions(int assignmentId) async {
    final res = await _dio.get<Object?>(
      '/assignments/$assignmentId/indicator-suggestions',
    );
    return IndicatorSuggestions.fromJson(unwrapJson(res.data));
  }

  @override
  Future<SuggestState> requestSuggestions(
    int assignmentId, {
    String? guidance,
  }) async {
    final g = normalizeGuidance(guidance);
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/indicator-suggestions',
      data: g == null ? null : {'guidance': g},
    );
    return SuggestState.fromJson(unwrapJson(res.data));
  }

  @override
  Future<IndicatorSuggestions> saveMapping(
    int assignmentId,
    Map<int, List<int>> skillIds,
  ) async {
    final res = await _dio.put<Object?>(
      '/assignments/$assignmentId/indicator-mapping',
      data: {
        'questions': [
          for (final e in skillIds.entries)
            {'question_id': e.key, 'skill_ids': e.value},
        ],
      },
    );
    return IndicatorSuggestions.fromJson(unwrapJson(res.data));
  }
}

final indicatorMappingRepositoryProvider = Provider<IndicatorMappingRepository>(
  (ref) => ApiIndicatorMappingRepository(ref.watch(dioProvider)),
);

/// How often a queued suggestion is polled. The queue worker runs once a
/// minute on the host (CLAUDE.md); tests shorten it.
final indicatorSuggestPollIntervalProvider = Provider<Duration>(
  (ref) => const Duration(seconds: 5),
);

/// The suggestions of one assignment, polled while a request is queued.
/// Not kept alive: the poll stops when the screen closes.
class IndicatorSuggestionsNotifier extends AsyncNotifier<IndicatorSuggestions> {
  IndicatorSuggestionsNotifier(this.assignmentId);

  final int assignmentId;
  Timer? _poll;

  IndicatorMappingRepository get _repo =>
      ref.read(indicatorMappingRepositoryProvider);

  @override
  Future<IndicatorSuggestions> build() async {
    watchSignedInUser(ref, keepAlive: false);
    ref.onDispose(() => _poll?.cancel());
    final data = await ref
        .watch(indicatorMappingRepositoryProvider)
        .suggestions(assignmentId);
    _pollIfQueued(data);
    return data;
  }

  Future<IndicatorSuggestions> refresh() {
    ref.invalidateSelf();
    return future;
  }

  /// "ให้ AI เสนอตัวชี้วัด": queues the request with [guidance] and polls
  /// until it is done. Returns the state of the round that runs (an
  /// earlier queued round keeps its own guidance).
  Future<SuggestState> request({String? guidance}) async {
    final next = await _repo.requestSuggestions(
      assignmentId,
      guidance: guidance,
    );
    final current = state.value;
    if (!ref.mounted || current == null) return next;
    final data = current.withState(next);
    state = AsyncData(data);
    _pollIfQueued(data);
    return next;
  }

  /// Saves the indicators of the questions that changed; the assignment's
  /// unmapped count changes with them.
  Future<IndicatorSuggestions> save(Map<int, List<int>> skillIds) async {
    final saved = await _repo.saveMapping(assignmentId, skillIds);
    if (!ref.mounted) return saved;
    state = AsyncData(saved);
    _pollIfQueued(saved);
    ref.invalidate(assignmentDetailProvider(assignmentId));
    // An exam's page counts its unmapped questions from GET /exams/{id}.
    ref.invalidate(examDetailProvider(assignmentId));
    return saved;
  }

  void _pollIfQueued(IndicatorSuggestions data) {
    _poll?.cancel();
    if (!data.state.queued) return;
    _poll = Timer(ref.read(indicatorSuggestPollIntervalProvider), () async {
      try {
        final next = await _repo.suggestions(assignmentId);
        if (!ref.mounted) return;
        state = AsyncData(next);
        _pollIfQueued(next);
      } catch (_) {
        // A network blip: ask again at the next tick.
        if (ref.mounted) _pollIfQueued(data);
      }
    });
  }
}

final indicatorSuggestionsProvider = AsyncNotifierProvider.autoDispose
    .family<IndicatorSuggestionsNotifier, IndicatorSuggestions, int>(
      IndicatorSuggestionsNotifier.new,
    );
