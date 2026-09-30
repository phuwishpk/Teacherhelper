import '../assignments/question.dart';

double? _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

int? _int(Object? v) => switch (v) {
  num n => n.toInt(),
  String s => int.tryParse(s),
  _ => null,
};

Skill? _skill(Object? v) =>
    v is Map ? Skill.fromJson(v.cast<String, dynamic>()) : null;

List<Map<String, dynamic>> _rows(Object? v) => [
  for (final r in (v as List?) ?? const [])
    if (r is Map) r.cast<String, dynamic>(),
];

/// At most this many lines on the progress chart (DESIGN §20.4 chart 1).
const kMaxProgressSkills = 5;

/// One observation of chart (1): the mastery right after it.
class ProgressPoint {
  const ProgressPoint({
    required this.observedAt,
    required this.date,
    required this.value,
    required this.scoreRatio,
    required this.source,
  });

  final DateTime observedAt;

  /// The day in Asia/Bangkok (`YYYY-MM-DD`), the x of the chart.
  final DateTime date;

  /// Mastery (0–1) after this observation.
  final double value;
  final double scoreRatio;

  /// `homework`, `exam` (a published exam answer, DESIGN §22.13) or
  /// `practice`.
  final String source;

  bool get fromExam => source == 'exam';

  factory ProgressPoint.fromJson(Map<String, dynamic> json) {
    final at = DateTime.tryParse(json['observed_at'] as String? ?? '');
    final day = DateTime.tryParse(json['date'] as String? ?? '');
    return ProgressPoint(
      observedAt: at ?? DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
      date: day == null
          ? DateTime.utc(1970)
          : DateTime.utc(day.year, day.month, day.day),
      value: (_double(json['value']) ?? 0).clamp(0.0, 1.0),
      scoreRatio: _double(json['score_ratio']) ?? 0,
      source: json['source'] as String? ?? 'homework',
    );
  }
}

/// One indicator's line of chart (1).
class ProgressSeries {
  const ProgressSeries({required this.skill, this.points = const []});

  final Skill skill;
  final List<ProgressPoint> points;

  /// One point per day: the mastery after that day's last observation, so
  /// several answers on one day do not stack up on one x.
  List<ProgressPoint> get daily {
    final byDay = <DateTime, ProgressPoint>{};
    for (final p in points) {
      byDay[p.date] = p;
    }
    return byDay.values.toList()..sort((a, b) => a.date.compareTo(b.date));
  }
}

/// A skill the student has mastery for (the picker of chart 1).
class ProgressSkillOption {
  const ProgressSkillOption({
    required this.skill,
    required this.value,
    required this.nObs,
  });

  final Skill skill;
  final double value;
  final int nObs;
}

/// `GET /students/{id}/indicator-progress` (teacher) or
/// `GET /student/indicator-progress` (own), DESIGN §20.4 chart (1).
class IndicatorProgress {
  const IndicatorProgress({
    required this.studentId,
    this.skillIds = const [],
    this.skills = const [],
    this.series = const [],
  });

  final int studentId;

  /// The lines drawn (the server's pick when none was asked for).
  final List<int> skillIds;
  final List<ProgressSkillOption> skills;
  final List<ProgressSeries> series;

  factory IndicatorProgress.fromJson(Map<String, dynamic> json) =>
      IndicatorProgress(
        studentId: _int(json['student_id']) ?? 0,
        skillIds: [
          for (final id in (json['skill_ids'] as List?) ?? const []) ?_int(id),
        ],
        skills: [
          for (final r in _rows(json['skills']))
            if (_skill(r['skill']) case final s?)
              ProgressSkillOption(
                skill: s,
                value: _double(r['value']) ?? 0,
                nObs: _int(r['n_obs']) ?? 0,
              ),
        ],
        series: [
          for (final r in _rows(json['series']))
            if (_skill(r['skill']) case final s?)
              ProgressSeries(
                skill: s,
                points: [
                  for (final p in _rows(r['points'])) ProgressPoint.fromJson(p),
                ],
              ),
        ],
      );
}

/// One bar of chart (2).
class PassRateRow {
  const PassRateRow({
    required this.skill,
    this.assessedStudents = 0,
    this.passedStudents = 0,
    this.passRate,
  });

  final Skill skill;
  final int assessedStudents;
  final int passedStudents;

  /// passed / assessed (0–1); null when nobody is assessed yet.
  final double? passRate;
}

/// `GET /classrooms/{id}/indicator-pass-rate` (DESIGN §20.4 chart 2).
class IndicatorPassRate {
  const IndicatorPassRate({
    required this.classroomId,
    this.courseId,
    this.passThreshold = 0.5,
    this.studentCount = 0,
    this.indicators = const [],
  });

  final int classroomId;
  final int? courseId;
  final double passThreshold;
  final int studentCount;
  final List<PassRateRow> indicators;

  int get assessedCount =>
      indicators.where((r) => r.assessedStudents > 0).length;

  factory IndicatorPassRate.fromJson(Map<String, dynamic> json) =>
      IndicatorPassRate(
        classroomId: _int(json['classroom_id']) ?? 0,
        courseId: _int(json['course_id']),
        passThreshold: _double(json['pass_threshold']) ?? 0.5,
        studentCount: _int(json['student_count']) ?? 0,
        indicators: [
          for (final r in _rows(json['indicators']))
            if (_skill(r['skill']) case final s?)
              PassRateRow(
                skill: s,
                assessedStudents: _int(r['assessed_students']) ?? 0,
                passedStudents: _int(r['passed_students']) ?? 0,
                passRate: _double(r['pass_rate']),
              ),
        ],
      );
}

/// One 10 % bin of chart (4).
class ScoreBin {
  const ScoreBin({
    required this.fromRatio,
    required this.toRatio,
    required this.fromPoints,
    required this.toPoints,
    required this.count,
  });

  final double fromRatio;
  final double toRatio;
  final double fromPoints;
  final double toPoints;
  final int count;
}

/// `GET /assignments/{id}/score-distribution` (DESIGN §20.4 chart 4).
class ScoreDistribution {
  const ScoreDistribution({
    required this.assignmentId,
    this.maxPoints = 0,
    this.publishedCount = 0,
    this.scoredCount = 0,
    this.mean,
    this.median,
    this.meanRatio,
    this.medianRatio,
    this.bins = const [],
  });

  final int assignmentId;
  final double maxPoints;
  final int publishedCount;
  final int scoredCount;
  final double? mean;
  final double? median;
  final double? meanRatio;
  final double? medianRatio;
  final List<ScoreBin> bins;

  int get maxCount => bins.fold(0, (m, b) => b.count > m ? b.count : m);

  factory ScoreDistribution.fromJson(Map<String, dynamic> json) =>
      ScoreDistribution(
        assignmentId: _int(json['assignment_id']) ?? 0,
        maxPoints: _double(json['max_points']) ?? 0,
        publishedCount: _int(json['published_count']) ?? 0,
        scoredCount: _int(json['scored_count']) ?? 0,
        mean: _double(json['mean']),
        median: _double(json['median']),
        meanRatio: _double(json['mean_ratio']),
        medianRatio: _double(json['median_ratio']),
        bins: [
          for (final b in _rows(json['bins']))
            ScoreBin(
              fromRatio: _double(b['from_ratio']) ?? 0,
              toRatio: _double(b['to_ratio']) ?? 0,
              fromPoints: _double(b['from_points']) ?? 0,
              toPoints: _double(b['to_points']) ?? 0,
              count: _int(b['count']) ?? 0,
            ),
        ],
      );
}

/// One stacked bar of chart (5): a unit, or `other` (in no unit), or the
/// course summary.
class PlanProgressRow {
  const PlanProgressRow({
    required this.type,
    required this.title,
    this.id,
    this.position,
    this.planned = 0,
    this.taught = 0,
    this.assessed = 0,
    this.taughtNotAssessed = 0,
    this.notTaught = 0,
    this.plansTotal = 0,
    this.plansTaught = 0,
  });

  final String type;
  final int? id;
  final String title;
  final int? position;
  final int planned;
  final int taught;
  final int assessed;

  /// The three disjoint parts of the stack: [assessed] +
  /// [taughtNotAssessed] + [notTaught] = [planned].
  final int taughtNotAssessed;
  final int notTaught;
  final int plansTotal;
  final int plansTaught;

  /// "หน่วย 1", or "ไม่อยู่ในหน่วย" for `other`.
  String get shortLabel =>
      type == 'unit' && position != null ? 'หน่วย $position' : title;

  factory PlanProgressRow.fromJson(
    Map<String, dynamic> json, {
    String type = 'course',
  }) => PlanProgressRow(
    type: json['type'] as String? ?? type,
    id: _int(json['id']),
    title: json['title'] as String? ?? '',
    position: _int(json['position']),
    planned: _int(json['planned']) ?? 0,
    taught: _int(json['taught']) ?? 0,
    assessed: _int(json['assessed']) ?? 0,
    taughtNotAssessed: _int(json['taught_not_assessed']) ?? 0,
    notTaught: _int(json['not_taught']) ?? 0,
    plansTotal: _int(json['plans_total']) ?? 0,
    plansTaught: _int(json['plans_taught']) ?? 0,
  );
}

/// `GET /courses/{id}/plan-progress` (DESIGN §20.4 chart 5).
class PlanProgress {
  const PlanProgress({
    required this.courseId,
    required this.summary,
    this.courseTitle = '',
    this.classroomId,
    this.units = const [],
  });

  final int courseId;
  final String courseTitle;
  final int? classroomId;
  final PlanProgressRow summary;
  final List<PlanProgressRow> units;

  factory PlanProgress.fromJson(Map<String, dynamic> json) {
    final course = (json['course'] as Map? ?? const {}).cast<String, dynamic>();
    final summary = json['summary'];
    return PlanProgress(
      courseId: _int(course['id']) ?? 0,
      courseTitle: [
        course['code'] as String? ?? '',
        course['name'] as String? ?? '',
      ].where((s) => s.isNotEmpty).join(' '),
      classroomId: _int(json['classroom_id']),
      summary: summary is Map
          ? PlanProgressRow.fromJson(summary.cast<String, dynamic>())
          : const PlanProgressRow(type: 'course', title: ''),
      units: [
        for (final r in _rows(json['units'])) PlanProgressRow.fromJson(r),
      ],
    );
  }
}
