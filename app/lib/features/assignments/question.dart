/// Question types of DESIGN §2.2 / §8.3.
enum QuestionType {
  mcq('mcq', 'ปรนัย'),
  short('short', 'เติมคำตอบสั้น'),
  showWork('show_work', 'แสดงวิธีทำ'),
  open('open', 'อัตนัย');

  const QuestionType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static QuestionType fromApi(String value) =>
      values.firstWhere((t) => t.apiValue == value);

  /// show_work and open need a rubric before a layout can be built.
  bool get needsRubric => this == showWork || this == open;

  /// Types whose answer box can be read by the digit CNN.
  bool get canBeNumeric => this == short || this == showWork;
}

enum RubricStatus {
  notNeeded('not_needed', 'ไม่ต้องใช้'),
  draft('draft', 'ร่างแล้ว รออนุมัติ'),
  approved('approved', 'อนุมัติแล้ว');

  const RubricStatus(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static RubricStatus fromApi(String? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => RubricStatus.notNeeded,
  );
}

class Skill {
  const Skill({
    required this.id,
    required this.code,
    required this.name,
    this.subjectId,
    this.gradeLevel,
  });

  final int id;
  final String code;
  final String name;
  final int? subjectId;
  final int? gradeLevel;

  factory Skill.fromJson(Map<String, dynamic> json) => Skill(
    id: (json['id'] as num).toInt(),
    code: json['code'] as String,
    name: json['name'] as String,
    subjectId: (json['subject_id'] as num?)?.toInt(),
    gradeLevel: (json['grade_level'] as num?)?.toInt(),
  );

  @override
  bool operator ==(Object other) => other is Skill && other.id == id;

  @override
  int get hashCode => id;
}

class Subject {
  const Subject({required this.id, required this.code, required this.name});

  final int id;
  final String code;
  final String name;

  factory Subject.fromJson(Map<String, dynamic> json) => Subject(
    id: (json['id'] as num).toInt(),
    code: json['code'] as String? ?? '',
    name: json['name'] as String,
  );
}

class RubricCriterion {
  const RubricCriterion({
    this.id,
    required this.position,
    required this.description,
    required this.points,
    this.isCore = false,
    this.source = 'teacher',
  });

  final int? id;
  final int position;
  final String description;
  final double points;
  final bool isCore;

  /// `ai` or `teacher` (DESIGN §8.3 rubric_criteria.source).
  final String source;

  factory RubricCriterion.fromJson(Map<String, dynamic> json) =>
      RubricCriterion(
        id: (json['id'] as num?)?.toInt(),
        position: (json['position'] as num?)?.toInt() ?? 0,
        description: json['description'] as String,
        points: (json['points'] as num).toDouble(),
        isCore: json['is_core'] == true,
        source: json['source'] as String? ?? 'teacher',
      );

  Map<String, dynamic> toJson() => {
    'position': position,
    'description': description,
    'points': points,
    'is_core': isCore,
  };

  RubricCriterion copyWith({
    String? description,
    double? points,
    bool? isCore,
    int? position,
  }) => RubricCriterion(
    id: id,
    position: position ?? this.position,
    description: description ?? this.description,
    points: points ?? this.points,
    isCore: isCore ?? this.isCore,
    source: source,
  );
}

/// A question as returned inside `GET /assignments/{id}`.
class Question {
  const Question({
    required this.id,
    required this.position,
    required this.type,
    required this.promptText,
    required this.maxPoints,
    this.answerLines,
    this.isNumeric = false,
    this.matchMode = 'flexible',
    this.answerKey,
    this.rubricStatus = RubricStatus.notNeeded,
    this.skills = const [],
    this.rubricCriteria = const [],
  });

  final int id;
  final int position;
  final QuestionType type;
  final String promptText;
  final double maxPoints;
  final int? answerLines;
  final bool isNumeric;

  /// `flexible` or `exact` (short answers only).
  final String matchMode;
  final Map<String, dynamic>? answerKey;
  final RubricStatus rubricStatus;
  final List<Skill> skills;
  final List<RubricCriterion> rubricCriteria;

  /// Reference solution steps stored in the show_work answer key.
  List<String> get referenceSteps =>
      ((answerKey?['reference_steps'] as List?) ?? const [])
          .map((e) => e.toString())
          .toList();

  factory Question.fromJson(Map<String, dynamic> json) => Question(
    id: (json['id'] as num).toInt(),
    position: (json['position'] as num).toInt(),
    type: QuestionType.fromApi(json['type'] as String),
    promptText: json['prompt_text'] as String? ?? '',
    maxPoints: (json['max_points'] as num).toDouble(),
    answerLines: (json['answer_lines'] as num?)?.toInt(),
    isNumeric: json['is_numeric'] == true,
    matchMode: json['match_mode'] as String? ?? 'flexible',
    answerKey: (json['answer_key'] as Map?)?.cast<String, dynamic>(),
    rubricStatus: RubricStatus.fromApi(json['rubric_status'] as String?),
    skills: ((json['skills'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(Skill.fromJson)
        .toList(),
    rubricCriteria: ((json['rubric_criteria'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(RubricCriterion.fromJson)
        .toList(),
  );
}

/// What the teacher fills in the question form; serialised for
/// `POST /assignments/{id}/questions` and `PATCH /questions/{id}`.
class QuestionDraft {
  const QuestionDraft({
    required this.type,
    required this.promptText,
    required this.maxPoints,
    this.answerLines,
    this.isNumeric = false,
    this.matchMode = 'flexible',
    this.answerKey,
    this.skillIds = const [],
    this.position,
  });

  final QuestionType type;
  final String promptText;
  final double maxPoints;
  final int? answerLines;
  final bool isNumeric;
  final String matchMode;
  final Map<String, dynamic>? answerKey;
  final List<int> skillIds;
  final int? position;

  Map<String, dynamic> toJson() => {
    if (position != null) 'position': position,
    'type': type.apiValue,
    'prompt_text': promptText,
    'max_points': maxPoints,
    'answer_lines': answerLines,
    'is_numeric': isNumeric,
    'match_mode': matchMode,
    'answer_key': answerKey,
    'skill_ids': skillIds,
  };
}
