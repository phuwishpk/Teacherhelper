import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';

/// A one-time link to the web admin panel (DESIGN §7.4): it works once,
/// within a minute.
class AdminHandoff {
  const AdminHandoff({required this.url, required this.expiresAt});

  final Uri url;
  final DateTime expiresAt;

  factory AdminHandoff.fromJson(Map<String, dynamic> json) => AdminHandoff(
    url: Uri.parse(json['url'] as String),
    expiresAt: DateTime.parse(json['expires_at'] as String),
  );
}

/// The only API an admin token opens besides `/me` and logout.
abstract class AdminRepository {
  /// `POST /auth/admin-handoff`.
  Future<AdminHandoff> handoff();
}

class ApiAdminRepository implements AdminRepository {
  ApiAdminRepository(this._dio);

  final Dio _dio;

  @override
  Future<AdminHandoff> handoff() async {
    final res = await _dio.post<Object?>('/auth/admin-handoff');
    return AdminHandoff.fromJson(unwrapJson(res.data));
  }
}

final adminRepositoryProvider = Provider<AdminRepository>(
  (ref) => ApiAdminRepository(ref.watch(dioProvider)),
);
