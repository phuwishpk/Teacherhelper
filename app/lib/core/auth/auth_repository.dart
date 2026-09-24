import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import 'session.dart';
import 'user.dart';

/// Thin wrapper over the auth endpoints in DESIGN §9.1.
class AuthRepository {
  AuthRepository(this._dio);

  final Dio _dio;

  Future<void> register({
    required String schoolCode,
    required String name,
    required String email,
    required String password,
  }) async {
    await _dio.post<Object?>(
      '/auth/teacher/register',
      data: {
        'school_code': schoolCode,
        'name': name,
        'email': email,
        'password': password,
      },
    );
  }

  /// Returns the plain-text Sanctum token.
  Future<String> login({
    required String email,
    required String password,
  }) async {
    final res = await _dio.post<Object?>(
      '/auth/teacher/login',
      data: {'email': email, 'password': password},
    );
    return unwrapJson(res.data)['token'] as String;
  }

  Future<User> me() async {
    final res = await _dio.get<Object?>('/me');
    return User.fromJson(unwrapJson(res.data));
  }

  Future<void> logout() async {
    await _dio.post<Object?>('/auth/logout');
  }
}

final dioProvider = Provider<Dio>((ref) {
  return createDio(
    tokenStorage: ref.watch(tokenStorageProvider),
    onUnauthorized: () => ref.read(sessionProvider.notifier).forceSignOut(),
  );
});

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(dioProvider)),
);
