import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import 'session.dart';
import 'user.dart';

/// A school of the teacher sign-up form (GET /auth/schools, DESIGN §9.1).
/// Names only: the join codes never leave the admin panel.
class SchoolOption {
  const SchoolOption({required this.id, required this.name});

  factory SchoolOption.fromJson(Map<String, dynamic> json) => SchoolOption(
    id: (json['id'] as num).toInt(),
    name: json['name'] as String,
  );

  final int id;
  final String name;
}

/// The auth endpoints in DESIGN §9.1. Interface so tests can inject a fake.
abstract class AuthRepository {
  /// The schools a teacher can sign up to (public, no token).
  Future<List<SchoolOption>> schools();

  /// [schoolId] is the school picked on the form; null lets the server use
  /// its only school (422 `school_required` when it has several). No school
  /// code since 2 Oct 2569: an admin's approval is the gate. [googleLinkTicket]
  /// links the Google account of a 404 `google_not_linked` as the account is
  /// created (DESIGN §24.9.5).
  Future<void> register({
    int? schoolId,
    required String name,
    required String email,
    required String password,
    String? googleLinkTicket,
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
  Future<List<SchoolOption>> schools() async {
    final res = await _dio.get<Object?>('/auth/schools');
    return unwrapList(res.data).map(SchoolOption.fromJson).toList();
  }

  @override
  Future<void> register({
    int? schoolId,
    required String name,
    required String email,
    required String password,
    String? googleLinkTicket,
  }) async {
    await _dio.post<Object?>(
      '/auth/teacher/register',
      data: {
        'school_id': ?schoolId,
        'name': name,
        'email': email,
        'password': password,
        'google_link_ticket': ?googleLinkTicket,
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
