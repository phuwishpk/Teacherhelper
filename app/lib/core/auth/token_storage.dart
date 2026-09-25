import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the Sanctum token and the last `/me` payload live. Interface so
/// tests can use an in-memory fake.
///
/// The cached user lets a teacher who opens the app without a network still
/// reach the scan / upload-queue screens (DESIGN §6.3); the token alone is
/// not enough because the router needs the role.
abstract class TokenStorage {
  Future<String?> read();
  Future<void> write(String token);

  /// The JSON of the user that last answered `GET /me`, if any.
  Future<Map<String, dynamic>?> readUser();
  Future<void> writeUser(Map<String, dynamic> user);

  /// Removes both the token and the cached user.
  Future<void> clear();
}

class SecureTokenStorage implements TokenStorage {
  SecureTokenStorage([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'auth_token';
  static const _userKey = 'auth_user';
  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: _key);

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<Map<String, dynamic>?> readUser() async {
    final raw = await _storage.read(key: _userKey);
    if (raw == null) return null;
    try {
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? decoded : null;
    } on FormatException {
      return null;
    }
  }

  @override
  Future<void> writeUser(Map<String, dynamic> user) =>
      _storage.write(key: _userKey, value: jsonEncode(user));

  @override
  Future<void> clear() async {
    await _storage.delete(key: _key);
    await _storage.delete(key: _userKey);
  }
}

class InMemoryTokenStorage implements TokenStorage {
  InMemoryTokenStorage({this.token, this.user});

  String? token;
  Map<String, dynamic>? user;

  @override
  Future<String?> read() async => token;

  @override
  Future<void> write(String token) async => this.token = token;

  @override
  Future<Map<String, dynamic>?> readUser() async => user;

  @override
  Future<void> writeUser(Map<String, dynamic> user) async =>
      this.user = Map<String, dynamic>.of(user);

  @override
  Future<void> clear() async {
    token = null;
    user = null;
  }
}
