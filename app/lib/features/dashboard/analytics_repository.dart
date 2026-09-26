import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import 'analytics_models.dart';

/// Teacher analytics (DESIGN §9.6, §14.3).
abstract class AnalyticsRepository {
  /// `GET /assignments/{id}/analytics`.
  Future<AssignmentAnalytics> assignment(int assignmentId);
}

class ApiAnalyticsRepository implements AnalyticsRepository {
  ApiAnalyticsRepository(this._dio);

  final Dio _dio;

  @override
  Future<AssignmentAnalytics> assignment(int assignmentId) async {
    final res = await _dio.get<Object?>('/assignments/$assignmentId/analytics');
    return AssignmentAnalytics.fromJson(unwrapJson(res.data));
  }
}

final analyticsRepositoryProvider = Provider<AnalyticsRepository>(
  (ref) => ApiAnalyticsRepository(ref.watch(dioProvider)),
);

final assignmentAnalyticsProvider = FutureProvider.autoDispose
    .family<AssignmentAnalytics, int>((ref, assignmentId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(analyticsRepositoryProvider).assignment(assignmentId);
    }, retry: apiRetry);
