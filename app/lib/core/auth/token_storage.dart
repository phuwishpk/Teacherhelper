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

  /// Id of the user whose data is in the local database (offline cache and
  /// upload queue). Survives [clear] on purpose: after a 401 the same user
  /// can sign in again and still upload their queued scans, while a
  /// different user signing in gets a wiped device first.
  Future<int?> readDataOwner();
  Future<void> writeDataOwner(int? userId);

  /// The tab of the login page used last (`teacher` or `student`), so a
  /// shared class phone opens on the student form. Not personal data;
  /// survives [clear] like the data owner.
  Future<String?> readLoginTab();
  Future<void> writeLoginTab(String tab);

  /// When the web preview left the register page for Google's account
  /// chooser ("สมัครด้วย Google", DESIGN §24.9.5), so the return on
  /// `/login/google` opens the register page instead of asking. Null clears
  /// it. The return reads and clears it; survives [clear].
  Future<DateTime?> readGoogleSignUpStartedAt();
  Future<void> writeGoogleSignUpStartedAt(DateTime? at);

  /// Removes both the token and the cached user (not the data owner).
  Future<void> clear();
}

class SecureTokenStorage implements TokenStorage {
  SecureTokenStorage([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'auth_token';
  static const _userKey = 'auth_user';
  static const _ownerKey = 'local_data_owner';
  static const _loginTabKey = 'login_tab';
  static const _googleSignUpKey = 'google_signup_started_at';
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
  Future<int?> readDataOwner() async =>
      int.tryParse(await _storage.read(key: _ownerKey) ?? '');

  @override
  Future<void> writeDataOwner(int? userId) => userId == null
      ? _storage.delete(key: _ownerKey)
      : _storage.write(key: _ownerKey, value: '$userId');

  @override
  Future<String?> readLoginTab() => _storage.read(key: _loginTabKey);

  @override
  Future<void> writeLoginTab(String tab) =>
      _storage.write(key: _loginTabKey, value: tab);

  @override
  Future<DateTime?> readGoogleSignUpStartedAt() async =>
      DateTime.tryParse(await _storage.read(key: _googleSignUpKey) ?? '');

  @override
  Future<void> writeGoogleSignUpStartedAt(DateTime? at) => at == null
      ? _storage.delete(key: _googleSignUpKey)
      : _storage.write(
          key: _googleSignUpKey,
          value: at.toUtc().toIso8601String(),
        );

  @override
  Future<void> clear() async {
    await _storage.delete(key: _key);
    await _storage.delete(key: _userKey);
  }
}

class InMemoryTokenStorage implements TokenStorage {
  InMemoryTokenStorage({
    this.token,
    this.user,
    this.dataOwner,
    this.loginTab,
    this.googleSignUpStartedAt,
  });

  String? token;
  Map<String, dynamic>? user;
  int? dataOwner;
  String? loginTab;
  DateTime? googleSignUpStartedAt;

  @override
  Future<DateTime?> readGoogleSignUpStartedAt() async => googleSignUpStartedAt;

  @override
  Future<void> writeGoogleSignUpStartedAt(DateTime? at) async =>
      googleSignUpStartedAt = at;

  @override
  Future<String?> readLoginTab() async => loginTab;

  @override
  Future<void> writeLoginTab(String tab) async => loginTab = tab;

  @override
  Future<int?> readDataOwner() async => dataOwner;

  @override
  Future<void> writeDataOwner(int? userId) async => dataOwner = userId;

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
