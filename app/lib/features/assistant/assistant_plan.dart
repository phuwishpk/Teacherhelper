/// The plan card of an assistant answer: questions to create (DESIGN
/// §37.6), a rubric draft for one question, or indicators (§37.7). It is
/// applied when the teacher presses the card's button. The rules of
/// [PlanQuestion.unsupportedFor] and the key check mirror the server's
/// AssistantPlans, so the ticks follow a changed target.
class AssistantPlan {
  const AssistantPlan({
    this.kind = questionsCard,
    required this.target,
    this.questions = const [],
    this.question,
    this.criteria = const [],
    this.referenceSteps = const [],
    this.problem,
    this.items = const [],
    this.result,
  });

  static const questionsCard = 'questions';
  static const rubricCard = 'rubric';
  static const indicatorsCard = 'indicators';

  final String kind;
  final PlanTarget target;
  final List<PlanQuestion> questions;

  /// A rubric card: the question, and its criteria (open) or steps
  /// (show_work); [problem] says why it cannot be saved.
  final RubricQuestion? question;
  final List<RubricCriterion> criteria;
  final List<String> referenceSteps;
  final String? problem;

  /// An indicator card's items.
  final List<IndicatorItem> items;
  final PlanResult? result;

  /// What the ticks index: the questions or the indicator items.
  int get tickable => kind == indicatorsCard ? items.length : questions.length;

  factory AssistantPlan.fromJson(Map<String, dynamic> json) {
    final target = json['target'];
    final result = json['result'];
    final question = json['question'];
    return AssistantPlan(
      kind: json['kind'] as String? ?? questionsCard,
      target: PlanTarget.fromJson(
        target is Map ? target.cast<String, dynamic>() : const {},
      ),
      questions: [
        for (final q in json['questions'] as List? ?? const [])
          if (q is Map) PlanQuestion.fromJson(q.cast<String, dynamic>()),
      ],
      question: question is Map
          ? RubricQuestion.fromJson(question.cast<String, dynamic>())
          : null,
      criteria: [
        for (final c in json['criteria'] as List? ?? const [])
          if (c is Map)
            (
              description: c['description'] as String? ?? '',
              points: (c['points'] as num?)?.toDouble() ?? 0,
              isCore: c['is_core'] == true,
            ),
      ],
      referenceSteps: [
        for (final s in json['reference_steps'] as List? ?? const []) '$s',
      ],
      problem: json['problem'] as String?,
      items: [
        for (final i in json['items'] as List? ?? const [])
          if (i is Map) IndicatorItem.fromJson(i.cast<String, dynamic>()),
      ],
      result: result is Map
          ? PlanResult.fromJson(result.cast<String, dynamic>())
          : null,
    );
  }
}

typedef RubricCriterion = ({String description, double points, bool isCore});

/// The question of a rubric card, as it was when the card was made.
class RubricQuestion {
  const RubricQuestion({
    required this.id,
    required this.position,
    required this.type,
    required this.prompt,
    required this.maxPoints,
    required this.rubricStatus,
  });

  final int id;
  final int position;
  final String type;
  final String prompt;
  final double maxPoints;
  final String rubricStatus;

  factory RubricQuestion.fromJson(Map<String, dynamic> json) => RubricQuestion(
    id: (json['id'] as num).toInt(),
    position: (json['position'] as num?)?.toInt() ?? 0,
    type: json['type'] as String? ?? 'open',
    prompt: json['prompt'] as String? ?? '',
    maxPoints: (json['max_points'] as num?)?.toDouble() ?? 0,
    rubricStatus: json['rubric_status'] as String? ?? 'draft',
  );
}

/// One item of an indicator card: a new indicator of the teacher's own, or
/// an existing one to add to «ตัวชี้วัดของฉัน».
class IndicatorItem {
  const IndicatorItem({
    required this.action,
    required this.code,
    required this.name,
    this.gradeLevel,
    this.skillId,
    this.parentCode,
    this.parentName,
    this.reason = '',
    this.questionPositions = const [],
    this.flags = const [],
  });

  static const flagNotFound = 'not_found';
  static const flagCodeTaken = 'code_taken';
  static const flagAlreadyMine = 'already_mine';
  static const flagNotInScope = 'not_in_scope';

  final String action;
  final String code;
  final String name;
  final int? gradeLevel;
  final int? skillId;
  final String? parentCode;
  final String? parentName;
  final String reason;
  final List<int> questionPositions;
  final List<String> flags;

  bool get isNew => action == 'new';
  bool get blocked =>
      flags.contains(flagNotFound) || flags.contains(flagCodeTaken);

  factory IndicatorItem.fromJson(Map<String, dynamic> json) {
    final parent = json['parent'];
    return IndicatorItem(
      action: json['action'] as String? ?? 'existing',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      gradeLevel: (json['grade_level'] as num?)?.toInt(),
      skillId: (json['skill_id'] as num?)?.toInt(),
      parentCode: parent is Map ? parent['code'] as String? : null,
      parentName: parent is Map ? parent['name'] as String? : null,
      reason: json['reason'] as String? ?? '',
      questionPositions: [
        for (final p in json['question_positions'] as List? ?? const [])
          if (p is num) p.toInt(),
      ],
      flags: [for (final f in json['flags'] as List? ?? const []) '$f'],
    );
  }
}

/// The kinds of assignment a card can fill.
abstract final class PlanKind {
  static const worksheet = 'worksheet';
  static const freeform = 'freeform';
  static const exam = 'exam';

  static String label(String? kind) => switch (kind) {
    worksheet => 'ใบงานของแอป',
    freeform => 'การบ้านไม่ใช้ใบงาน',
    exam => 'ข้อสอบกระดาษ',
    _ => 'ยังไม่ได้เลือก',
  };
}

/// Where the questions go: an assignment, or a new one ([isNew]).
class PlanTarget {
  const PlanTarget({
    this.assignmentId,
    this.newClassroomId,
    this.newCourseId,
    this.title,
    this.kind,
    this.classroom,
    this.course,
    this.status,
    this.error,
  });

  final int? assignmentId;
  final int? newClassroomId;
  final int? newCourseId;
  final String? title;
  final String? kind;
  final String? classroom;
  final String? course;
  final String? status;

  /// Why the card cannot be applied to this target (the teacher picks one).
  final String? error;

  bool get isNew => assignmentId == null && newClassroomId != null;
  bool get isReady => status == 'ready';

  factory PlanTarget.fromJson(Map<String, dynamic> json) {
    final created = json['new'];
    final fresh = created is Map ? created.cast<String, dynamic>() : null;
    return PlanTarget(
      assignmentId: (json['assignment_id'] as num?)?.toInt(),
      newClassroomId: (fresh?['classroom_id'] as num?)?.toInt(),
      newCourseId: (fresh?['course_id'] as num?)?.toInt(),
      title: json['title'] as String? ?? fresh?['title'] as String?,
      kind: json['kind'] as String? ?? fresh?['kind'] as String?,
      classroom: json['classroom'] as String?,
      course: json['course'] as String?,
      status: json['status'] as String?,
      error: json['error'] as String?,
    );
  }

  /// The body's `target` of POST /assistant/messages/{id}/apply.
  Map<String, dynamic> toApply() => assignmentId != null
      ? {'assignment_id': assignmentId}
      : {
          'new': {
            'classroom_id': newClassroomId,
            'course_id': ?newCourseId,
            'title': title,
            'kind': kind,
          },
        };
}

class PlanQuestion {
  const PlanQuestion({
    required this.type,
    required this.promptText,
    this.options = const [],
    this.correctOptions = const [],
    this.acceptedAnswers = const [],
    this.numericValue,
    this.referenceSteps = const [],
    this.modelAnswer,
    this.points = 1,
    this.explanation = '',
    this.indicatorCodes = const [],
    this.needsFigure = false,
    this.flags = const [],
  });

  static const flagFigure = 'needs_figure';
  static const flagIncomplete = 'incomplete_key';
  static const flagUnsupported = 'unsupported';

  static const homeworkTypes = ['mcq', 'short', 'show_work', 'open'];
  static const examTypes = ['mcq', 'true_false', 'numeric'];

  final String type;
  final String promptText;
  final List<String> options;

  /// 1-based; for true_false 1 = ถูก, 2 = ผิด.
  final List<int> correctOptions;
  final List<String> acceptedAnswers;
  final String? numericValue;
  final List<String> referenceSteps;
  final String? modelAnswer;
  final double points;
  final String explanation;
  final List<String> indicatorCodes;
  final bool needsFigure;
  final List<String> flags;

  bool get incompleteKey => flags.contains(flagIncomplete);

  String get typeLabel => switch (type) {
    'mcq' => 'ปรนัย',
    'short' => 'ตอบสั้น',
    'show_work' => 'แสดงวิธีทำ',
    'open' => 'อัตนัย',
    'true_false' => 'ถูก/ผิด',
    'numeric' => 'เติมตัวเลข',
    _ => type,
  };

  /// Why the question cannot go into an assignment of [kind], or null.
  String? unsupportedFor(String? kind) {
    if (kind == null) return null;
    if (kind == PlanKind.exam) {
      return examTypes.contains(type)
          ? null
          : 'ข้อสอบกระดาษรับเฉพาะปรนัย ถูก/ผิด และเติมตัวเลข';
    }
    if (!homeworkTypes.contains(type)) {
      return 'การบ้านไม่มีข้อถูก/ผิดหรือเติมตัวเลขแบบข้อสอบ';
    }
    if (type == 'mcq' && options.length > 4) {
      return 'ข้อปรนัยของการบ้านมีได้ไม่เกิน 4 ตัวเลือก (A–D)';
    }
    return null;
  }

  /// Can be ticked for an assignment of [kind].
  bool usableFor(String? kind) =>
      !incompleteKey && unsupportedFor(kind) == null;

  factory PlanQuestion.fromJson(Map<String, dynamic> json) {
    List<String> strings(Object? v) => [
      for (final s in v is List ? v : const []) '$s',
    ];
    return PlanQuestion(
      type: json['type'] as String? ?? 'short',
      promptText: json['prompt_text'] as String? ?? '',
      options: strings(json['options']),
      correctOptions: [
        for (final c in json['correct_options'] as List? ?? const [])
          if (c is num) c.toInt(),
      ],
      acceptedAnswers: strings(json['accepted_answers']),
      numericValue: json['numeric_value'] as String?,
      referenceSteps: strings(json['reference_steps']),
      modelAnswer: json['model_answer'] as String?,
      points: (json['points'] as num?)?.toDouble() ?? 1,
      explanation: json['explanation'] as String? ?? '',
      indicatorCodes: strings(json['indicator_codes']),
      needsFigure: json['needs_figure'] == true,
      flags: strings(json['flags']),
    );
  }
}

/// What applying a card did: questions created in an assignment, a rubric
/// draft saved for [questionId], or indicators created ([created]), added
/// to «ตัวชี้วัดของฉัน» ([addedMine]) and suggested for questions
/// ([mapped]).
class PlanResult {
  const PlanResult({
    this.assignmentId,
    this.title = '',
    this.kind = PlanKind.worksheet,
    this.created = 0,
    this.questionId,
    this.position,
    this.addedMine = 0,
    this.mapped = 0,
    this.skipped = const [],
  });

  final int? assignmentId;
  final String title;
  final String kind;
  final int created;
  final int? questionId;
  final int? position;
  final int addedMine;
  final int mapped;
  final List<({int index, String reason})> skipped;

  factory PlanResult.fromJson(Map<String, dynamic> json) => PlanResult(
    assignmentId: (json['assignment_id'] as num?)?.toInt(),
    title: json['title'] as String? ?? '',
    kind: json['kind'] as String? ?? PlanKind.worksheet,
    created: (json['created'] as num?)?.toInt() ?? 0,
    questionId: (json['question_id'] as num?)?.toInt(),
    position: (json['position'] as num?)?.toInt(),
    addedMine: (json['added_mine'] as num?)?.toInt() ?? 0,
    mapped: (json['mapped'] as num?)?.toInt() ?? 0,
    skipped: [
      for (final s in json['skipped'] as List? ?? const [])
        if (s is Map)
          (
            index: (s['index'] as num).toInt(),
            reason: s['reason'] as String? ?? '',
          ),
    ],
  );
}

/// "3 คะแนน", "0.5 คะแนน".
String pointsLabel(double points) =>
    '${points == points.roundToDouble() ? points.toInt() : points} คะแนน';
