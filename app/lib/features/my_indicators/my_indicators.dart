import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../assignments/question.dart';

/// "ตัวชี้วัดของฉัน" (DESIGN §34): the indicators a teacher teaches. The
/// indicator pickers show these and the teacher's own indicators first.
abstract class MyIndicatorsRepository {
  /// `GET /me/indicators`: the set, by subject, grade and code.
  Future<List<Skill>> list();

  /// `PUT /me/indicators {skill_ids}`: replaces the set (empty clears it).
  Future<List<Skill>> save(List<int> skillIds);
}

class ApiMyIndicatorsRepository implements MyIndicatorsRepository {
  ApiMyIndicatorsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<Skill>> list() async {
    final res = await _dio.get<Object?>('/me/indicators');
    return unwrapList(res.data).map(Skill.fromJson).toList();
  }

  @override
  Future<List<Skill>> save(List<int> skillIds) async {
    final res = await _dio.put<Object?>(
      '/me/indicators',
      data: {'skill_ids': skillIds},
    );
    return unwrapList(res.data).map(Skill.fromJson).toList();
  }
}

final myIndicatorsRepositoryProvider = Provider<MyIndicatorsRepository>(
  (ref) => ApiMyIndicatorsRepository(ref.watch(dioProvider)),
);

class MyIndicatorsNotifier extends AsyncNotifier<List<Skill>> {
  @override
  Future<List<Skill>> build() {
    watchSignedInUser(ref);
    return ref.watch(myIndicatorsRepositoryProvider).list();
  }

  /// Saves [skills] as the whole set. Throws on failure so the screen can
  /// say why; the previous set stays shown then.
  Future<void> replace(Iterable<Skill> skills) async {
    final ids = {for (final s in skills) s.id}.toList();
    final saved = await ref.read(myIndicatorsRepositoryProvider).save(ids);
    state = AsyncData(saved);
  }
}

/// Per teacher: dropped on sign-out (see [watchSignedInUser]).
final myIndicatorsProvider =
    AsyncNotifierProvider.autoDispose<MyIndicatorsNotifier, List<Skill>>(
      MyIndicatorsNotifier.new,
    );

/// The teacher keeps a set: the pickers then default to it. False while
/// loading or when the set cannot be read, so the pickers show everything.
bool hasMyIndicators(AsyncValue<List<Skill>> value) =>
    value.value?.isNotEmpty ?? false;
