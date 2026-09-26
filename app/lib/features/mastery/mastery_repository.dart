import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import 'mastery_models.dart';

/// Mastery values (DESIGN §9.6, §9.7, §14.2).
abstract class MasteryRepository {
  /// Student: `GET /student/mastery`.
  Future<MasteryList> mine();

  /// Teacher: `GET /classrooms/{id}/mastery` (student x skill heatmap).
  Future<ClassroomMastery> classroom(int classroomId);

  /// Teacher: `GET /students/{id}/mastery`.
  Future<List<SkillMastery>> student(int studentId);
}

class ApiMasteryRepository implements MasteryRepository {
  ApiMasteryRepository(this._dio);

  final Dio _dio;

  @override
  Future<MasteryList> mine() async {
    final res = await _dio.get<Object?>('/student/mastery');
    final body = res.data;
    final meta = body is Map ? body['meta'] : null;
    return MasteryList(
      rows: unwrapList(body).map(SkillMastery.fromJson).toList(),
      available: !(meta is Map && meta['available'] == false),
    );
  }

  @override
  Future<ClassroomMastery> classroom(int classroomId) async {
    final res = await _dio.get<Object?>('/classrooms/$classroomId/mastery');
    return ClassroomMastery.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<SkillMastery>> student(int studentId) async {
    final res = await _dio.get<Object?>('/students/$studentId/mastery');
    return unwrapList(res.data).map(SkillMastery.fromJson).toList();
  }
}

final masteryRepositoryProvider = Provider<MasteryRepository>(
  (ref) => ApiMasteryRepository(ref.watch(dioProvider)),
);

/// The signed-in student's own mastery.
final myMasteryProvider = FutureProvider.autoDispose<MasteryList>((ref) {
  watchSignedInUser(ref);
  return ref.watch(masteryRepositoryProvider).mine();
}, retry: apiRetry);

final classroomMasteryProvider = FutureProvider.autoDispose
    .family<ClassroomMastery, int>((ref, classroomId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(masteryRepositoryProvider).classroom(classroomId);
    }, retry: apiRetry);

final studentMasteryProvider = FutureProvider.autoDispose
    .family<List<SkillMastery>, int>((ref, studentId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(masteryRepositoryProvider).student(studentId);
    }, retry: apiRetry);
