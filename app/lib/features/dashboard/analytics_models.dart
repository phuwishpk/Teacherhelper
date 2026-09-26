import '../assignments/question.dart';
import '../mastery/mastery_models.dart';
import '../review/review_labels.dart';

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

/// Classical item statistics of one question (DESIGN §14.3).
class ItemStat {
  const ItemStat({
    required this.questionId,
    required this.position,
    required this.type,
    required this.maxPoints,
    this.promptText = '',
    this.n = 0,
    this.p,
    this.r,
  });

  final int questionId;
  final int position;
  final String type;
  final double maxPoints;
  final String promptText;

  /// Published answers the numbers come from.
  final int n;

  /// Difficulty: mean(final_score / max_points), 0–1. Null = no data.
  final double? p;

  /// Discrimination: mean ratio of the top 27% minus the bottom 27% by
  /// total score. Null below [AssignmentAnalytics.minCountForR] students.
  final double? r;

  static const pLow = 0.20;
  static const pHigh = 0.80;
  static const rGood = 0.20;

  bool get tooHard => p != null && p! < pLow;
  bool get tooEasy => p != null && p! > pHigh;
  bool get weakDiscrimination => r != null && r! < rGood;

  /// Thai notes for the table; empty when the item is fine.
  List<String> get notes => [
    if (tooHard) 'ยากเกินไป',
    if (tooEasy) 'ง่ายเกินไป',
    if (weakDiscrimination) 'จำแนกได้น้อย',
  ];

  factory ItemStat.fromJson(Map<String, dynamic> json) => ItemStat(
    questionId: _int(json['question_id'] ?? json['id'])!,
    position: _int(json['position']) ?? 0,
    type: json['type'] as String? ?? 'short',
    maxPoints: _double(json['max_points']) ?? 0,
    promptText: json['prompt_text'] as String? ?? '',
    n: _int(json['n']) ?? 0,
    p: _double(json['p']),
    r: _double(json['r']),
  );
}

/// One cell count of the skill x error type heatmap.
class SkillErrorCount {
  const SkillErrorCount({
    required this.skill,
    required this.errorType,
    required this.count,
  });

  final Skill skill;
  final ErrorType errorType;
  final int count;
}

/// `GET /assignments/{id}/analytics` (DESIGN §9.6, §14.3):
/// `{published_count, min_count_for_r, items: [...],
///   skill_error_counts: [{skill: {id, code, name}, error_type, count}]}`.
class AssignmentAnalytics {
  const AssignmentAnalytics({
    required this.publishedCount,
    required this.items,
    this.skillErrorCounts = const [],
    this.minCountForR = defaultMinCountForR,
  });

  /// §14.3: r is shown from 20 published students up.
  static const defaultMinCountForR = 20;

  final int publishedCount;
  final int minCountForR;
  final List<ItemStat> items;
  final List<SkillErrorCount> skillErrorCounts;

  bool get hasDiscrimination => publishedCount >= minCountForR;

  /// Items in question order.
  List<ItemStat> get byPosition =>
      [...items]..sort((a, b) => a.position.compareTo(b.position));

  /// "ข้อที่ทั้งห้องผิดมากที่สุด": lowest p first.
  List<ItemStat> mostMissed({int limit = 5}) {
    final withP = items.where((i) => i.p != null).toList()
      ..sort((a, b) {
        final byP = a.p!.compareTo(b.p!);
        return byP != 0 ? byP : a.position.compareTo(b.position);
      });
    return withP.take(limit).toList();
  }

  /// Skills that have at least one counted error, in code order.
  List<Skill> get heatmapSkills {
    final byId = <int, Skill>{
      for (final c in skillErrorCounts)
        if (c.count > 0) c.skill.id: c.skill,
    };
    return byId.values.toList()..sort((a, b) => a.code.compareTo(b.code));
  }

  /// Error types that occur at all, in the schema order of [ErrorType].
  List<ErrorType> get heatmapErrorTypes {
    final used = {
      for (final c in skillErrorCounts)
        if (c.count > 0) c.errorType,
    };
    return ErrorType.values.where(used.contains).toList();
  }

  int count(int skillId, ErrorType type) => skillErrorCounts
      .where((c) => c.skill.id == skillId && c.errorType == type)
      .fold(0, (s, c) => s + c.count);

  factory AssignmentAnalytics.fromJson(Map<String, dynamic> json) {
    final counts = <SkillErrorCount>[];
    for (final row in (json['skill_error_counts'] as List? ?? const [])) {
      if (row is! Map) continue;
      final m = row.cast<String, dynamic>();
      final type = ErrorType.fromApi(m['error_type']);
      if (type == null) continue;
      counts.add(
        SkillErrorCount(
          skill: skillFromJson(m),
          errorType: type,
          count: _int(m['count']) ?? 0,
        ),
      );
    }
    return AssignmentAnalytics(
      publishedCount: _int(json['published_count']) ?? 0,
      minCountForR: _int(json['min_count_for_r']) ?? defaultMinCountForR,
      items: [
        for (final i in (json['items'] as List? ?? const []))
          if (i is Map) ItemStat.fromJson(i.cast<String, dynamic>()),
      ],
      skillErrorCounts: counts,
    );
  }
}

/// "0.43" for a statistic, "–" when missing.
String stat2(double? v) => v == null ? '–' : v.toStringAsFixed(2);
