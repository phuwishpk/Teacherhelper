import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'student_result.dart';

/// Student-side results (DESIGN §9.7). Only published submissions come back.
abstract class ResultsRepository {
  Future<List<StudentResult>> list();
}

class ApiResultsRepository implements ResultsRepository {
  ApiResultsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<StudentResult>> list() async {
    final rows = await fetchAllPages(_dio, '/student/results');
    return rows.map(StudentResult.fromJson).toList();
  }
}

final resultsRepositoryProvider = Provider<ResultsRepository>(
  (ref) => ApiResultsRepository(ref.watch(dioProvider)),
);

class StudentResultsNotifier extends AsyncNotifier<List<StudentResult>> {
  @override
  Future<List<StudentResult>> build() =>
      ref.watch(resultsRepositoryProvider).list();

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }
}

final studentResultsProvider =
    AsyncNotifierProvider<StudentResultsNotifier, List<StudentResult>>(
      StudentResultsNotifier.new,
    );
