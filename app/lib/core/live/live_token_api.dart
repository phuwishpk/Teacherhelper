import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_repository.dart';

/// `POST /auth/firebase-token` (DESIGN §38.4): the custom token the app
/// signs in to Firebase with.
class FirebaseToken {
  const FirebaseToken({
    required this.token,
    required this.uid,
    required this.expiresAt,
  });

  final String token;
  final String uid;
  final DateTime expiresAt;

  factory FirebaseToken.fromJson(Map<String, dynamic> json) => FirebaseToken(
    token: json['token'] as String,
    uid: json['uid'] as String,
    expiresAt: DateTime.parse(json['expires_at'] as String),
  );
}

typedef FetchFirebaseToken = Future<FirebaseToken> Function();

class LiveTokenApi {
  LiveTokenApi(this._dio);

  final Dio _dio;

  Future<FirebaseToken> fetch() async {
    final response = await _dio.post<Map<String, dynamic>>(
      '/auth/firebase-token',
    );
    return FirebaseToken.fromJson(
      response.data!['data'] as Map<String, dynamic>,
    );
  }
}

final liveTokenApiProvider = Provider<LiveTokenApi>(
  (ref) => LiveTokenApi(ref.watch(dioProvider)),
);
