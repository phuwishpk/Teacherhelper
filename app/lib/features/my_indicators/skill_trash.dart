import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../assignments/question.dart';

/// An indicator in the teacher's trash with what still uses it (DESIGN
/// §34.1): Thai name of the place => how many.
class TrashedSkill {
  const TrashedSkill(this.skill, this.uses);

  final Skill skill;
  final Map<String, int> uses;

  bool get inUse => uses.isNotEmpty;

  factory TrashedSkill.fromJson(Map<String, dynamic> json) =>
      TrashedSkill(Skill.fromJson(json), {
        for (final e in ((json['uses'] as Map?) ?? const {}).entries)
          e.key as String: (e.value as num).toInt(),
      });
}

/// The teacher's own indicators and their trash (DESIGN §34.1).
abstract class SkillTrashRepository {
  /// `GET /skills?own=1`: the indicators the teacher added, not in the trash.
  Future<List<Skill>> own();

  /// `GET /skills/trash`.
  Future<List<TrashedSkill>> trash();

  /// `DELETE /skills/{id}`: into the trash, with the sub-indicators under it.
  Future<void> moveToTrash(int id);

  /// `POST /skills/{id}/restore` (409 parent_in_trash).
  Future<void> restore(int id);

  /// `DELETE /skills/{id}/permanent` (409 skill_in_use).
  Future<void> deleteForGood(int id);
}

class ApiSkillTrashRepository implements SkillTrashRepository {
  ApiSkillTrashRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<Skill>> own() async {
    final rows = await fetchAllPages(
      _dio,
      '/skills',
      query: {'own': 1, 'level': 'indicator,sub_indicator'},
      maxPages: 5,
    );
    return rows.map(Skill.fromJson).toList();
  }

  @override
  Future<List<TrashedSkill>> trash() async {
    final res = await _dio.get<Object?>('/skills/trash');
    return unwrapList(res.data).map(TrashedSkill.fromJson).toList();
  }

  @override
  Future<void> moveToTrash(int id) => _dio.delete<Object?>('/skills/$id');

  @override
  Future<void> restore(int id) => _dio.post<Object?>('/skills/$id/restore');

  @override
  Future<void> deleteForGood(int id) =>
      _dio.delete<Object?>('/skills/$id/permanent');
}

final skillTrashRepositoryProvider = Provider<SkillTrashRepository>(
  (ref) => ApiSkillTrashRepository(ref.watch(dioProvider)),
);

/// The teacher's own indicators (not in the trash).
final ownIndicatorsProvider = FutureProvider.autoDispose<List<Skill>>((ref) {
  watchSignedInUser(ref);
  return ref.watch(skillTrashRepositoryProvider).own();
});

/// The teacher's trash, newest first.
final skillTrashProvider = FutureProvider.autoDispose<List<TrashedSkill>>((
  ref,
) {
  watchSignedInUser(ref);
  return ref.watch(skillTrashRepositoryProvider).trash();
});
