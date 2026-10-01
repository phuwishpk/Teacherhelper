import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'google_signin_models.dart';

/// The Google sign-in endpoints (DESIGN §24.12 C). Every call that signs
/// someone in returns the plain-text Sanctum token of `{token, user}`; the
/// session then reads `/me` like after a password login. Errors are the
/// DioException with the `{message, errors, code}` body (see
/// [GoogleNotLinked.of] and `googleSignInErrorMessage`).
abstract class GoogleSignInRepository {
  /// `GET /auth/google/config` (public, never 503).
  Future<GoogleSignInServerConfig> config();

  /// `POST /auth/google {id_token, intent}`.
  Future<String> signIn({
    required String idToken,
    required GoogleIntent intent,
  });

  /// `POST /auth/google/web-url`: Google's account chooser for the web
  /// preview. `link` sends the user's token (the Dio interceptor adds it).
  Future<Uri> webUrl({
    required bool link,
    GoogleIntent? intent,
    bool acceptNotice = false,
  });

  /// `POST /auth/google/ticket {ticket}`: the web flow's one-time ticket.
  Future<String> redeemTicket(String ticket);

  /// `POST /auth/google/link-with-pin`: a student's first confirmation.
  Future<String> linkWithPin({
    required String linkTicket,
    required String classCode,
    required int studentNumber,
    required String pin,
  });

  /// `POST /auth/google/link-with-qr`: the same with the login card.
  Future<String> linkWithQr({
    required String linkTicket,
    required String qrToken,
  });

  /// `GET /me/google-identity`.
  Future<GoogleIdentity> identity();

  /// `POST /me/google-identity {id_token, accept_notice}`.
  Future<GoogleIdentity> link({
    required String idToken,
    required bool acceptNotice,
  });

  /// `DELETE /me/google-identity` (204 also when nothing was linked).
  Future<void> unlink();

  /// `DELETE /students/{id}/google-identity` by the student's homeroom
  /// teacher.
  Future<void> unlinkStudent(int studentId);
}

class ApiGoogleSignInRepository implements GoogleSignInRepository {
  ApiGoogleSignInRepository(this._dio);

  final Dio _dio;

  /// Every token request of the student's first confirmation states the
  /// notice was accepted: the screen sends nothing before the checkbox.
  static const _accepted = {'accept_notice': true};

  Future<String> _token(String path, Map<String, dynamic> data) async {
    final res = await _dio.post<Object?>(path, data: data);
    return unwrapJson(res.data)['token'] as String;
  }

  @override
  Future<GoogleSignInServerConfig> config() async {
    final res = await _dio.get<Object?>('/auth/google/config');
    return GoogleSignInServerConfig.fromJson(unwrapJson(res.data));
  }

  @override
  Future<String> signIn({
    required String idToken,
    required GoogleIntent intent,
  }) =>
      _token('/auth/google', {'id_token': idToken, 'intent': intent.apiValue});

  @override
  Future<Uri> webUrl({
    required bool link,
    GoogleIntent? intent,
    bool acceptNotice = false,
  }) async {
    final res = await _dio.post<Object?>(
      '/auth/google/web-url',
      data: {
        'purpose': link ? 'link' : 'login',
        'intent': ?intent?.apiValue,
        if (acceptNotice) 'accept_notice': true,
      },
    );
    return Uri.parse(unwrapJson(res.data)['url'] as String);
  }

  @override
  Future<String> redeemTicket(String ticket) =>
      _token('/auth/google/ticket', {'ticket': ticket});

  @override
  Future<String> linkWithPin({
    required String linkTicket,
    required String classCode,
    required int studentNumber,
    required String pin,
  }) => _token('/auth/google/link-with-pin', {
    'link_ticket': linkTicket,
    'class_code': classCode,
    'student_number': studentNumber,
    'pin': pin,
    ..._accepted,
  });

  @override
  Future<String> linkWithQr({
    required String linkTicket,
    required String qrToken,
  }) => _token('/auth/google/link-with-qr', {
    'link_ticket': linkTicket,
    'qr_token': qrToken,
    ..._accepted,
  });

  @override
  Future<GoogleIdentity> identity() async {
    final res = await _dio.get<Object?>('/me/google-identity');
    return GoogleIdentity.fromJson(unwrapJson(res.data));
  }

  @override
  Future<GoogleIdentity> link({
    required String idToken,
    required bool acceptNotice,
  }) async {
    final res = await _dio.post<Object?>(
      '/me/google-identity',
      data: {'id_token': idToken, 'accept_notice': acceptNotice},
    );
    return GoogleIdentity.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> unlink() async {
    await _dio.delete<Object?>('/me/google-identity');
  }

  @override
  Future<void> unlinkStudent(int studentId) async {
    await _dio.delete<Object?>('/students/$studentId/google-identity');
  }
}

final googleSignInRepositoryProvider = Provider<GoogleSignInRepository>(
  (ref) => ApiGoogleSignInRepository(ref.watch(dioProvider)),
);
