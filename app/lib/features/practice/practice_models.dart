import '../assignments/question.dart';
import '../mastery/mastery_models.dart';

int? _int(Object? v) => switch (v) {
  num n => n.toInt(),
  String s => int.tryParse(s),
  _ => null,
};

double? _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

/// `practice_items.answer_type` (DESIGN §8.5).
enum PracticeAnswerType {
  numeric('numeric', 'ตัวเลข'),
  short('short', 'ตอบสั้น'),
  mcq('mcq', 'ปรนัย');

  const PracticeAnswerType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static PracticeAnswerType fromApi(Object? v) =>
      values.firstWhere((t) => t.apiValue == v, orElse: () => short);
}

/// `practice_items.status`: a teacher approves every AI draft (§14.1).
enum PracticeItemStatus {
  draft('draft', 'ร่าง รออนุมัติ'),
  approved('approved', 'อนุมัติแล้ว'),
  retired('retired', 'เลิกใช้');

  const PracticeItemStatus(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static PracticeItemStatus fromApi(Object? v) =>
      values.firstWhere((s) => s.apiValue == v, orElse: () => draft);
}

/// One choice of an mcq practice item. The student's answer is its [key].
class PracticeOption {
  const PracticeOption({required this.key, required this.text});

  final String key;
  final String text;

  Map<String, dynamic> toJson() => {'key': key, 'text': text};

  static const _letters = 'ABCDEFGHIJ';

  /// Accepts `[{key, text}]`, a plain `["...", "..."]` (keyed A, B, C…) or a
  /// `{"A": "..."}` map.
  static List<PracticeOption> listFromJson(Object? json) {
    if (json is List) {
      return [
        for (var i = 0; i < json.length; i++)
          if (json[i] case final Map m)
            PracticeOption(
              key: '${m['key'] ?? m['label'] ?? _letter(i)}',
              text: '${m['text'] ?? m['value'] ?? ''}',
            )
          else if (json[i] != null)
            PracticeOption(key: _letter(i), text: '${json[i]}'),
      ];
    }
    if (json is Map) {
      return [
        for (final e in json.entries)
          PracticeOption(key: '${e.key}', text: '${e.value}'),
      ];
    }
    return const [];
  }

  static String _letter(int i) =>
      i < _letters.length ? _letters[i] : '${i + 1}';

  static String letterFor(int i) => _letter(i);
}

/// A row of the school's practice bank (`practice_items`, DESIGN §8.5).
/// Students never receive [answerKey].
class PracticeItem {
  const PracticeItem({
    required this.id,
    required this.skill,
    required this.answerType,
    required this.promptText,
    this.options = const [],
    this.answerKey,
    this.explanation = '',
    this.status = PracticeItemStatus.approved,
    this.source = 'ai',
    this.approvedAt,
  });

  final int id;
  final Skill skill;
  final PracticeAnswerType answerType;
  final String promptText;
  final List<PracticeOption> options;

  /// §8.3 shapes: `{accepted, numeric?}` or `{correct}` for mcq.
  final Map<String, dynamic>? answerKey;
  final String explanation;
  final PracticeItemStatus status;

  /// `ai` or `teacher`.
  final String source;
  final DateTime? approvedAt;

  /// Accepted answers of a numeric/short key, or the correct option of mcq.
  List<String> get acceptedAnswers => [
    for (final a in (answerKey?['accepted'] as List? ?? const [])) '$a',
  ];

  String? get correctOption => answerKey?['correct']?.toString();

  double? get numericValue =>
      _double((answerKey?['numeric'] as Map?)?['value']);

  double? get numericTolerance =>
      _double((answerKey?['numeric'] as Map?)?['abs_tol']);

  factory PracticeItem.fromJson(Map<String, dynamic> json) {
    final approved = json['approved_at'];
    return PracticeItem(
      id: _int(json['id'])!,
      skill: json['skill'] is Map || json['skill_id'] != null
          ? skillFromJson({
              if (json['skill'] is Map) 'skill': json['skill'],
              'skill_id': json['skill_id'],
            })
          : const Skill(id: 0, code: '', name: ''),
      answerType: PracticeAnswerType.fromApi(json['answer_type']),
      promptText: (json['prompt_text'] ?? json['prompt_th'] ?? '') as String,
      options: PracticeOption.listFromJson(json['options']),
      answerKey: (json['answer_key'] as Map?)?.cast<String, dynamic>(),
      explanation:
          (json['explanation'] ?? json['explanation_th'] ?? '') as String,
      status: PracticeItemStatus.fromApi(json['status']),
      source: json['source'] as String? ?? 'ai',
      approvedAt: approved is String ? DateTime.tryParse(approved) : null,
    );
  }
}

/// A review link of a skill (`learning_resources`, DESIGN §8.5, §14.1).
class LearningResource {
  const LearningResource({
    required this.id,
    required this.title,
    required this.url,
    this.skillId,
  });

  final int id;
  final int? skillId;
  final String title;
  final String url;

  factory LearningResource.fromJson(Map<String, dynamic> json) =>
      LearningResource(
        id: _int(json['id']) ?? 0,
        skillId: _int(json['skill_id']),
        title: json['title'] as String? ?? '',
        url: json['url'] as String? ?? '',
      );
}

/// One weak skill with its practice items and review links, a group of
/// `GET /student/practice` (DESIGN §9.7, §14.1: mastery < 0.75, weakest
/// first, at most 3 approved items not tried in the last 7 days).
class PracticeRecommendation {
  const PracticeRecommendation({
    required this.skill,
    required this.items,
    this.mastery,
    this.resources = const [],
  });

  final Skill skill;
  final SkillMastery? mastery;
  final List<PracticeItem> items;
  final List<LearningResource> resources;

  factory PracticeRecommendation.fromJson(Map<String, dynamic> json) {
    final skill = skillFromJson(json);
    final mastery = json['mastery'];
    return PracticeRecommendation(
      skill: skill,
      mastery: mastery is Map
          ? SkillMastery.fromJson({
              'skill': {'id': skill.id, 'code': skill.code, 'name': skill.name},
              ...mastery.cast<String, dynamic>(),
            })
          : null,
      items: [
        for (final i in (json['items'] as List? ?? const []))
          if (i is Map)
            PracticeItem.fromJson({
              'skill': {'id': skill.id, 'code': skill.code, 'name': skill.name},
              ...i.cast<String, dynamic>(),
            }),
      ],
      resources: [
        for (final r in (json['resources'] as List? ?? const []))
          if (r is Map) LearningResource.fromJson(r.cast<String, dynamic>()),
      ],
    );
  }

  /// Accepts the grouped shape, or a flat list of items (each with its
  /// `skill`), grouped here in the server's order.
  static List<PracticeRecommendation> listFromJson(
    List<Map<String, dynamic>> rows,
  ) {
    if (rows.isEmpty) return const [];
    final grouped = rows.first.containsKey('items');
    if (grouped) return rows.map(PracticeRecommendation.fromJson).toList();
    final bySkill = <int, List<PracticeItem>>{};
    final skills = <int, Skill>{};
    for (final row in rows) {
      final item = PracticeItem.fromJson(row);
      skills[item.skill.id] = item.skill;
      bySkill.putIfAbsent(item.skill.id, () => []).add(item);
    }
    return [
      for (final e in bySkill.entries)
        PracticeRecommendation(skill: skills[e.key]!, items: e.value),
    ];
  }
}

/// Answer of `POST /student/practice/{item_id}/attempts` (§9.7): checked
/// at once with the deterministic AnswerMatcher, no Gemini.
class PracticeAttemptResult {
  const PracticeAttemptResult({
    required this.scoreRatio,
    this.explanation,
    this.mastery,
  });

  final double scoreRatio;
  final String? explanation;

  /// The skill's mastery after this attempt, when the server sends it.
  final SkillMastery? mastery;

  bool get correct => scoreRatio >= 0.999;
  bool get partlyCorrect => !correct && scoreRatio > 0;

  factory PracticeAttemptResult.fromJson(Map<String, dynamic> json) {
    final mastery = json['mastery'];
    return PracticeAttemptResult(
      scoreRatio: (_double(json['score_ratio']) ?? 0).clamp(0.0, 1.0),
      explanation: json['explanation'] as String?,
      mastery:
          mastery is Map &&
              (mastery['skill'] is Map || mastery['skill_id'] != null)
          ? SkillMastery.fromJson(mastery.cast<String, dynamic>())
          : null,
    );
  }
}
