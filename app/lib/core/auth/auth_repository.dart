import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import 'session.dart';
import 'user.dart';

/// The auth endpoints in DESIGN §9.1. Interface so tests can inject a fake.
abstract class AuthRepository {
  Future<void> register({
    required String schoolCode,
    required String name,
    required String email,
    required String password,
  });

  /// Returns the plain-text Sanctum token.
  Future<String> login({required String email, required String password});

  /// Student login with the token from a login card (`EVL1.{token}`).
  Future<String> loginStudentQr(String qrToken);

  /// Student fallback login; the server rate-limits and locks after 5 misses.
  Future<String> loginStudentPin({
    required String classCode,
    required int studentNumber,
    required String pin,
  });

  Future<User> me();

  Future<void> logout();
}

class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._dio);

  final Dio _dio;

  @override
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

  @override
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

  @override
  Future<String> loginStudentQr(String qrToken) async {
    final res = await _dio.post<Object?>(
      '/auth/student/qr',
      data: {'qr_token': qrToken},
    );
    return unwrapJson(res.data)['token'] as String;
  }

  @override
  Future<String> loginStudentPin({
    required String classCode,
    required int studentNumber,
    required String pin,
  }) async {
    final res = await _dio.post<Object?>(
      '/auth/student/pin',
      data: {
        'class_code': classCode,
        'student_number': studentNumber,
        'pin': pin,
      },
    );
    return unwrapJson(res.data)['token'] as String;
  }

  @override
  Future<User> me() async {
    final res = await _dio.get<Object?>('/me');
    return User.fromJson(unwrapJson(res.data));
  }

  @override
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
  (ref) => ApiAuthRepository(ref.watch(dioProvider)),
);
