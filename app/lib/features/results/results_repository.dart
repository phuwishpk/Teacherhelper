import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../review/review_models.dart';
import 'student_result.dart';

/// Student-side results (DESIGN §9.7). Only published submissions come back.
abstract class ResultsRepository {
  Future<List<StudentResult>> list();
  Future<StudentResultDetail> detail(int submissionId);

  /// `POST /student/responses/{id}/appeal {reason?}`: once per question.
  Future<Appeal> appeal(int responseId, {String? reason});
}

class ApiResultsRepository implements ResultsRepository {
  ApiResultsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<StudentResult>> list() async {
    final rows = await fetchAllPages(_dio, '/student/results');
    return rows.map(StudentResult.fromJson).toList();
  }

  @override
  Future<StudentResultDetail> detail(int submissionId) async {
    final res = await _dio.get<Object?>('/student/results/$submissionId');
    return StudentResultDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Appeal> appeal(int responseId, {String? reason}) async {
    final res = await _dio.post<Object?>(
      '/student/responses/$responseId/appeal',
      data: {'reason': ?reason},
    );
    return Appeal.fromJson(unwrapJson(res.data));
  }
}

final resultsRepositoryProvider = Provider<ResultsRepository>(
  (ref) => ApiResultsRepository(ref.watch(dioProvider)),
);

class StudentResultsNotifier extends AsyncNotifier<List<StudentResult>> {
  @override
  Future<List<StudentResult>> build() {
    watchSignedInUser(ref);
    return ref.watch(resultsRepositoryProvider).list();
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }
}

final studentResultsProvider =
    AsyncNotifierProvider.autoDispose<
      StudentResultsNotifier,
      List<StudentResult>
    >(StudentResultsNotifier.new);

final studentResultDetailProvider = FutureProvider.autoDispose
    .family<StudentResultDetail, int>((ref, submissionId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(resultsRepositoryProvider).detail(submissionId);
    });
