import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import 'analysis_models.dart';

/// The per-student AI analysis (DESIGN §20.5, §20.7).
abstract class AnalysisRepository {
  /// `GET /classrooms/{id}/analyses`.
  Future<ClassroomAnalyses> classroom(int classroomId);

  /// `GET /students/{id}/analysis?classroom_id=`; null = nothing assessed
  /// in that classroom yet.
  Future<StudentAnalysis?> student(int studentId, int classroomId);

  /// `POST /students/{id}/analysis/run {classroom_id}`: "วิเคราะห์ตอนนี้",
  /// synchronous. 422 `analysis_no_data` / `ai_key_missing` /
  /// `ai_key_invalid`, 502 `ai_unavailable`.
  Future<StudentAnalysis> runNow(int studentId, int classroomId);

  /// `PATCH /analyses/{id} {teacher_text?, student_text?}`: edits only;
  /// sharing is [approve].
  Future<StudentAnalysis> edit(
    int analysisId, {
    String? teacherText,
    String? studentText,
  });

  /// `POST /analyses/{id}/approve`: the student sees the current draft.
  Future<StudentAnalysis> approve(int analysisId);

  /// `PATCH /classrooms/{id} {auto_share_analysis}`.
  Future<void> setAutoShare(int classroomId, bool value);

  /// Student: `GET /student/analysis`, their own shared texts only.
  Future<List<MyAnalysis>> mine();
}

class ApiAnalysisRepository implements AnalysisRepository {
  ApiAnalysisRepository(this._dio);

  final Dio _dio;

  @override
  Future<ClassroomAnalyses> classroom(int classroomId) async {
    final res = await _dio.get<Object?>('/classrooms/$classroomId/analyses');
    return ClassroomAnalyses.fromJson(unwrapJson(res.data));
  }

  @override
  Future<StudentAnalysis?> student(int studentId, int classroomId) async {
    final res = await _dio.get<Object?>(
      '/students/$studentId/analysis',
      queryParameters: {'classroom_id': classroomId},
    );
    final body = res.data;
    if (body is Map && body.containsKey('data') && body['data'] == null) {
      return null;
    }
    return StudentAnalysis.fromJson(unwrapJson(body));
  }

  @override
  Future<StudentAnalysis> runNow(int studentId, int classroomId) async {
    final res = await _dio.post<Object?>(
      '/students/$studentId/analysis/run',
      data: {'classroom_id': classroomId},
      // Gemini answers inside the request (DESIGN §20.5: up to 30 s).
      options: Options(receiveTimeout: const Duration(seconds: 75)),
    );
    return StudentAnalysis.fromJson(unwrapJson(res.data));
  }

  @override
  Future<StudentAnalysis> edit(
    int analysisId, {
    String? teacherText,
    String? studentText,
  }) async {
    final res = await _dio.patch<Object?>(
      '/analyses/$analysisId',
      data: {'teacher_text': ?teacherText, 'student_text': ?studentText},
    );
    return StudentAnalysis.fromJson(unwrapJson(res.data));
  }

  @override
  Future<StudentAnalysis> approve(int analysisId) async {
    final res = await _dio.post<Object?>('/analyses/$analysisId/approve');
    return StudentAnalysis.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> setAutoShare(int classroomId, bool value) async {
    await _dio.patch<Object?>(
      '/classrooms/$classroomId',
      data: {'auto_share_analysis': value},
    );
  }

  @override
  Future<List<MyAnalysis>> mine() async {
    final res = await _dio.get<Object?>('/student/analysis');
    return unwrapList(res.data).map(MyAnalysis.fromJson).toList();
  }
}

final analysisRepositoryProvider = Provider<AnalysisRepository>(
  (ref) => ApiAnalysisRepository(ref.watch(dioProvider)),
);

/// The analyses of one classroom and its auto-share switch.
class ClassroomAnalysesNotifier extends AsyncNotifier<ClassroomAnalyses> {
  ClassroomAnalysesNotifier(this.classroomId);

  final int classroomId;

  @override
  Future<ClassroomAnalyses> build() {
    watchSignedInUser(ref, keepAlive: false);
    return ref.watch(analysisRepositoryProvider).classroom(classroomId);
  }

  /// Flips `auto_share_analysis`; the list keeps the old value on failure.
  Future<void> setAutoShare(bool value) async {
    await ref.read(analysisRepositoryProvider).setAutoShare(classroomId, value);
    final current = state.value;
    if (current != null) state = AsyncData(current.withAutoShare(value));
  }
}

final classroomAnalysesProvider = AsyncNotifierProvider.autoDispose
    .family<ClassroomAnalysesNotifier, ClassroomAnalyses, int>(
      ClassroomAnalysesNotifier.new,
      retry: apiRetry,
    );

typedef StudentAnalysisKey = ({int studentId, int classroomId});

/// One student's analysis in one classroom, with the teacher's actions.
/// Every write answers with the new payload, which replaces the state.
class StudentAnalysisNotifier extends AsyncNotifier<StudentAnalysis?> {
  StudentAnalysisNotifier(this.key);

  final StudentAnalysisKey key;

  AnalysisRepository get _repo => ref.read(analysisRepositoryProvider);

  @override
  Future<StudentAnalysis?> build() {
    watchSignedInUser(ref, keepAlive: false);
    return ref
        .watch(analysisRepositoryProvider)
        .student(key.studentId, key.classroomId);
  }

  Future<StudentAnalysis> runNow() =>
      _write(() => _repo.runNow(key.studentId, key.classroomId));

  /// Saves the changed texts, then shares the draft when [share].
  Future<StudentAnalysis> edit({
    String? teacherText,
    String? studentText,
    bool share = false,
  }) => _write(() async {
    final id = state.value!.id;
    var result = state.value!;
    if (teacherText != null || studentText != null) {
      result = await _repo.edit(
        id,
        teacherText: teacherText,
        studentText: studentText,
      );
      state = AsyncData(result);
    }
    return share ? _repo.approve(id) : result;
  });

  Future<StudentAnalysis> approve() =>
      _write(() => _repo.approve(state.value!.id));

  Future<StudentAnalysis> _write(Future<StudentAnalysis> Function() op) async {
    final result = await op();
    state = AsyncData(result);
    // The classroom list shows the new status next time it is read.
    ref.invalidate(classroomAnalysesProvider(key.classroomId));
    return result;
  }
}

final studentAnalysisProvider = AsyncNotifierProvider.autoDispose
    .family<StudentAnalysisNotifier, StudentAnalysis?, StudentAnalysisKey>(
      StudentAnalysisNotifier.new,
      retry: apiRetry,
    );

/// Student: the texts their teachers shared, per classroom.
final myAnalysesProvider = FutureProvider.autoDispose<List<MyAnalysis>>((ref) {
  watchSignedInUser(ref);
  return ref.watch(analysisRepositoryProvider).mine();
}, retry: apiRetry);
