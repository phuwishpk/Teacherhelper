import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import 'chart_models.dart';

/// Chart data of DESIGN §20.4 / §20.7 beyond the course roll-up
/// (`course_mastery.dart`) and the heatmap (`mastery_repository.dart`).
abstract class ChartsRepository {
  /// Chart (1). With [studentId]: the teacher's `GET
  /// /students/{id}/indicator-progress`; without: the student's own `GET
  /// /student/indicator-progress`. No [skillIds]: the server picks the
  /// skills observed most recently.
  Future<IndicatorProgress> progress({int? studentId, List<int>? skillIds});

  /// Chart (2): `GET /classrooms/{id}/indicator-pass-rate`.
  Future<IndicatorPassRate> passRate(int classroomId, {int? courseId});

  /// Chart (4): `GET /assignments/{id}/score-distribution`.
  Future<ScoreDistribution> scoreDistribution(int assignmentId);

  /// Chart (5): `GET /courses/{id}/plan-progress`.
  Future<PlanProgress> planProgress(int courseId, {int? classroomId});
}

class ApiChartsRepository implements ChartsRepository {
  ApiChartsRepository(this._dio);

  final Dio _dio;

  @override
  Future<IndicatorProgress> progress({
    int? studentId,
    List<int>? skillIds,
  }) async {
    final res = await _dio.get<Object?>(
      studentId == null
          ? '/student/indicator-progress'
          : '/students/$studentId/indicator-progress',
      queryParameters: {
        if (skillIds != null && skillIds.isNotEmpty)
          'skill_ids': skillIds.join(','),
      },
    );
    return IndicatorProgress.fromJson(unwrapJson(res.data));
  }

  @override
  Future<IndicatorPassRate> passRate(int classroomId, {int? courseId}) async {
    final res = await _dio.get<Object?>(
      '/classrooms/$classroomId/indicator-pass-rate',
      queryParameters: {'course_id': ?courseId},
    );
    return IndicatorPassRate.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ScoreDistribution> scoreDistribution(int assignmentId) async {
    final res = await _dio.get<Object?>(
      '/assignments/$assignmentId/score-distribution',
    );
    return ScoreDistribution.fromJson(unwrapJson(res.data));
  }

  @override
  Future<PlanProgress> planProgress(int courseId, {int? classroomId}) async {
    final res = await _dio.get<Object?>(
      '/courses/$courseId/plan-progress',
      queryParameters: {'classroom_id': ?classroomId},
    );
    return PlanProgress.fromJson(unwrapJson(res.data));
  }
}

final chartsRepositoryProvider = Provider<ChartsRepository>(
  (ref) => ApiChartsRepository(ref.watch(dioProvider)),
);

/// Which progress lines: [studentId] null = the signed-in student's own;
/// [skillIds] a comma list, or null for the server's pick.
typedef ProgressQuery = ({int? studentId, String? skillIds});

final indicatorProgressProvider = FutureProvider.autoDispose
    .family<IndicatorProgress, ProgressQuery>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(chartsRepositoryProvider)
          .progress(
            studentId: q.studentId,
            skillIds: q.skillIds == null
                ? null
                : [for (final s in q.skillIds!.split(',')) ?int.tryParse(s)],
          );
    }, retry: apiRetry);

final indicatorPassRateProvider = FutureProvider.autoDispose
    .family<IndicatorPassRate, ({int classroomId, int? courseId})>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(chartsRepositoryProvider)
          .passRate(q.classroomId, courseId: q.courseId);
    }, retry: apiRetry);

final scoreDistributionProvider = FutureProvider.autoDispose
    .family<ScoreDistribution, int>((ref, assignmentId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(chartsRepositoryProvider)
          .scoreDistribution(assignmentId);
    }, retry: apiRetry);

final planProgressProvider = FutureProvider.autoDispose
    .family<PlanProgress, ({int courseId, int? classroomId})>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(chartsRepositoryProvider)
          .planProgress(q.courseId, classroomId: q.classroomId);
    }, retry: apiRetry);
