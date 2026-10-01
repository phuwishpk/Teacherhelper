import 'package:eduvision/features/charts/chart_models.dart';
import 'package:eduvision/features/charts/charts_repository.dart';

Map<String, dynamic> chartSkill(int id, String code, {String? name}) => {
  'id': id,
  'code': code,
  'name': name ?? 'ตัวชี้วัด $code',
  'subject_id': 1,
  'grade_level': 5,
  'level': 'indicator',
  'source_label': null,
};

/// `GET /assignments/{id}/score-distribution`: totals 0, 3, 7.5, 10, 12 of 10.
Map<String, dynamic> scoreDistributionJson({int scored = 5}) => {
  'assignment_id': 5,
  'max_points': 10,
  'published_count': scored + 1,
  'scored_count': scored,
  'mean': scored == 0 ? null : 6.5,
  'median': scored == 0 ? null : 7.5,
  'mean_ratio': scored == 0 ? null : 0.65,
  'median_ratio': scored == 0 ? null : 0.75,
  'bins': [
    for (var i = 0; i < 10; i++)
      {
        'from_ratio': i / 10,
        'to_ratio': (i + 1) / 10,
        'from_points': i.toDouble(),
        'to_points': i + 1.0,
        'count': scored == 0 ? 0 : const [1, 0, 0, 1, 0, 0, 0, 1, 0, 2][i],
      },
  ],
};

Map<String, dynamic> passRateJson() => {
  'classroom_id': 7,
  'course_id': 4,
  'pass_threshold': 0.5,
  'student_count': 3,
  'indicators': [
    {
      'skill': chartSkill(1, 'ค 1.1 ป.5/1'),
      'assessed_students': 2,
      'passed_students': 1,
      'pass_rate': 0.5,
    },
    {
      'skill': chartSkill(2, 'ค 1.1 ป.5/2'),
      'assessed_students': 1,
      'passed_students': 1,
      'pass_rate': 1,
    },
    {
      'skill': chartSkill(5, 'ค 9 ครู'),
      'assessed_students': 0,
      'passed_students': 0,
      'pass_rate': null,
    },
  ],
};

Map<String, dynamic> planProgressJson() => {
  'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
  'classroom_id': 7,
  'summary': {
    'planned': 4,
    'taught': 1,
    'assessed': 2,
    'taught_not_assessed': 1,
    'not_taught': 1,
    'plans_total': 2,
    'plans_taught': 1,
  },
  'units': [
    {
      'type': 'unit',
      'id': 20,
      'title': 'เศษส่วน',
      'position': 1,
      'planned': 2,
      'taught': 1,
      'assessed': 1,
      'taught_not_assessed': 1,
      'not_taught': 0,
      'plans_total': 1,
      'plans_taught': 1,
    },
    {
      'type': 'unit',
      'id': 21,
      'title': 'ยังไม่วางแผน',
      'position': 2,
      'planned': 0,
      'taught': 0,
      'assessed': 0,
      'taught_not_assessed': 0,
      'not_taught': 0,
      'plans_total': 0,
      'plans_taught': 0,
    },
    {
      'type': 'other',
      'id': null,
      'title': 'ไม่อยู่ในหน่วย',
      'position': null,
      'planned': 2,
      'taught': 0,
      'assessed': 1,
      'taught_not_assessed': 0,
      'not_taught': 1,
      'plans_total': 1,
      'plans_taught': 0,
    },
  ],
};

/// Progress of skills 1 (three observations over two Bangkok days) and 3.
Map<String, dynamic> progressJson({
  int studentId = 55,
  List<int> ids = const [1, 3],
}) => {
  'student_id': studentId,
  'skill_ids': ids,
  'skills': [
    {'skill': chartSkill(1, 'ค 1.1 ป.5/1'), 'value': 0.723, 'n_obs': 3},
    {'skill': chartSkill(2, 'ค 1.1 ป.5/2'), 'value': 1.0, 'n_obs': 2},
    {'skill': chartSkill(3, 'ค 1.2 ป.5/1'), 'value': 0.3, 'n_obs': 1},
  ],
  'series': [
    for (final id in ids)
      {
        'skill': chartSkill(id, switch (id) {
          1 => 'ค 1.1 ป.5/1',
          2 => 'ค 1.1 ป.5/2',
          _ => 'ค 1.2 ป.5/1',
        }),
        'points': switch (id) {
          1 => [
            {
              'observed_at': '2026-09-01T03:00:00Z',
              'date': '2026-09-01',
              'value': 1,
              'score_ratio': 1,
              'source': 'homework',
            },
            {
              'observed_at': '2026-09-02T18:00:00Z',
              'date': '2026-09-03',
              'value': 0.85,
              'score_ratio': 0.5,
              'source': 'homework',
            },
            {
              'observed_at': '2026-09-03T03:00:00Z',
              'date': '2026-09-03',
              'value': 0.723,
              'score_ratio': 0,
              'source': 'practice',
            },
          ],
          2 => [
            {
              'observed_at': '2026-09-04T03:00:00Z',
              'date': '2026-09-04',
              'value': 1,
              'score_ratio': 1,
              'source': 'homework',
            },
          ],
          _ => [
            {
              'observed_at': '2026-09-05T03:00:00Z',
              'date': '2026-09-05',
              'value': 0.3,
              'score_ratio': 0.3,
              'source': 'exam',
            },
          ],
        },
      },
  ],
};

/// In-memory [ChartsRepository] that records what the screens asked for.
class FakeChartsRepository implements ChartsRepository {
  final progressCalls =
      <({int? studentId, List<int>? skillIds, int? courseId})>[];
  final passRateCalls = <({int classroomId, int? courseId})>[];
  final planCalls = <({int courseId, int? classroomId})>[];
  int scoredCount = 5;

  @override
  Future<IndicatorProgress> progress({
    int? studentId,
    List<int>? skillIds,
    int? courseId,
  }) async {
    progressCalls.add((
      studentId: studentId,
      skillIds: skillIds,
      courseId: courseId,
    ));
    return IndicatorProgress.fromJson(
      progressJson(studentId: studentId ?? 55, ids: skillIds ?? const [1, 3]),
    );
  }

  @override
  Future<IndicatorPassRate> passRate(int classroomId, {int? courseId}) async {
    passRateCalls.add((classroomId: classroomId, courseId: courseId));
    return IndicatorPassRate.fromJson(passRateJson());
  }

  @override
  Future<PlanProgress> planProgress(int courseId, {int? classroomId}) async {
    planCalls.add((courseId: courseId, classroomId: classroomId));
    return PlanProgress.fromJson(planProgressJson());
  }

  @override
  Future<ScoreDistribution> scoreDistribution(int assignmentId) async =>
      ScoreDistribution.fromJson(scoreDistributionJson(scored: scoredCount));
}
