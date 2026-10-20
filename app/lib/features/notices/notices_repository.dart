import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/auth_repository.dart';

/// `POST /me/notices/read {ids}` (DESIGN §38 step 2): the server flips
/// `read` on those notices in Firestore from its queue.
abstract class NoticesRepository {
  Future<void> markRead(List<String> ids);
}

class ApiNoticesRepository implements NoticesRepository {
  ApiNoticesRepository(this._dio);

  final Dio _dio;

  static const maxIds = 100;

  @override
  Future<void> markRead(List<String> ids) async {
    if (ids.isEmpty) return;
    for (var i = 0; i < ids.length; i += maxIds) {
      final chunk = ids.sublist(i, (i + maxIds).clamp(0, ids.length));
      await _dio.post<Object?>('/me/notices/read', data: {'ids': chunk});
    }
  }
}

final noticesRepositoryProvider = Provider<NoticesRepository>(
  (ref) => ApiNoticesRepository(ref.watch(dioProvider)),
);
