import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';

import 'google_config.dart';

/// What `POST /google/connect` needs (DESIGN §18.5).
class GoogleServerAuth {
  const GoogleServerAuth({required this.serverAuthCode, required this.email});

  /// One-time code the server exchanges for the refresh token.
  final String serverAuthCode;
  final String email;
}

/// A short-lived Drive token held in memory only (§18.5: never stored,
/// never sent to our server).
class DriveAccessToken {
  const DriveAccessToken({required this.value, required this.email});

  final String value;
  final String email;
}

/// Why getting something from Google Sign-In failed. [message] is Thai.
sealed class GoogleAuthException implements Exception {
  const GoogleAuthException();

  String get message;
}

/// The teacher closed the account picker or the consent screen.
final class GoogleAuthCanceled extends GoogleAuthException {
  const GoogleAuthCanceled();

  @override
  String get message => 'ยกเลิกการเข้าสู่ระบบ Google แล้ว';
}

final class GoogleAuthFailed extends GoogleAuthException {
  const GoogleAuthFailed(this.message, [this.detail]);

  @override
  final String message;
  final String? detail;

  @override
  String toString() => 'GoogleAuthFailed($message, $detail)';
}

/// The Google account on the phone is not the one connected on the server.
final class GoogleAccountMismatch extends GoogleAuthException {
  const GoogleAccountMismatch({required this.expected, required this.actual});

  final String expected;
  final String actual;

  @override
  String get message =>
      'บัญชี Google ที่เลือก ($actual) ไม่ใช่บัญชีที่เชื่อมไว้ ($expected) '
      'เลือกบัญชี $expected อีกครั้ง';
}

/// Google Sign-In behind an interface so screens and tests do not depend on
/// the plugin.
abstract class GoogleAuthGateway {
  /// Account picker + consent for [googleServerScopes], then the server
  /// auth code (offline access) for `POST /google/connect`.
  Future<GoogleServerAuth> requestServerAuthCode();

  /// An access token for `drive.readonly` of [expectedEmail] (the account
  /// connected on the server). May show the account picker or consent.
  Future<DriveAccessToken> driveAccessToken({String? expectedEmail});

  /// Drops a token Google rejected (401) so the next call gets a new one.
  Future<void> invalidate(DriveAccessToken token);

  /// Forgets the Google account on this device (sign-out of our app,
  /// "ยกเลิกการเชื่อม"). Best effort.
  Future<void> signOut();
}

/// `google_sign_in` 7.x: `initialize(serverClientId:)` once, `authenticate`
/// for the account, then the account's authorization client for the server
/// auth code and for Drive access tokens.
class PluginGoogleAuth implements GoogleAuthGateway {
  PluginGoogleAuth({required this.serverClientId, GoogleSignIn? signIn})
    : _signIn = signIn ?? GoogleSignIn.instance;

  final String serverClientId;
  final GoogleSignIn _signIn;
  Future<void>? _initialized;
  GoogleSignInAccount? _account;

  /// `initialize` once. A failed attempt (Play services busy, no network)
  /// is not kept, so the next tap tries again instead of failing until the
  /// app restarts.
  Future<void> _ready() async {
    final attempt = _initialized ??= Future.sync(
      () => _signIn.initialize(serverClientId: serverClientId),
    );
    try {
      await attempt;
    } catch (_) {
      if (identical(_initialized, attempt)) _initialized = null;
      rethrow;
    }
  }

  @override
  Future<GoogleServerAuth> requestServerAuthCode() => _guard(() async {
    await _ready();
    if (!_signIn.supportsAuthenticate()) {
      throw const GoogleAuthFailed('อุปกรณ์นี้เข้าสู่ระบบ Google จากแอปไม่ได้');
    }
    // Always the picker: the teacher chooses which account to connect.
    final account = await _signIn.authenticate(scopeHint: googleServerScopes);
    _account = account;
    final server = await account.authorizationClient.authorizeServer(
      googleServerScopes,
    );
    if (server == null || server.serverAuthCode.isEmpty) {
      throw const GoogleAuthFailed(
        'Google ไม่ส่งรหัสยืนยันกลับมา ลองกดเชื่อมอีกครั้ง',
      );
    }
    return GoogleServerAuth(
      serverAuthCode: server.serverAuthCode,
      email: account.email,
    );
  });

  @override
  Future<DriveAccessToken> driveAccessToken({String? expectedEmail}) =>
      _guard(() async {
        await _ready();
        var account = _account;
        if (account == null || !_sameEmail(account.email, expectedEmail)) {
          final attempt = _signIn.attemptLightweightAuthentication();
          account = attempt == null ? null : await attempt;
        }
        if (account == null || !_sameEmail(account.email, expectedEmail)) {
          if (!_signIn.supportsAuthenticate()) {
            throw const GoogleAuthFailed(
              'อุปกรณ์นี้เข้าสู่ระบบ Google จากแอปไม่ได้',
            );
          }
          account = await _signIn.authenticate(
            scopeHint: const [driveReadonlyScope],
          );
        }
        if (!_sameEmail(account.email, expectedEmail)) {
          throw GoogleAccountMismatch(
            expected: expectedEmail!,
            actual: account.email,
          );
        }
        _account = account;
        final client = account.authorizationClient;
        final authz =
            await client.authorizationForScopes(const [driveReadonlyScope]) ??
            await client.authorizeScopes(const [driveReadonlyScope]);
        return DriveAccessToken(value: authz.accessToken, email: account.email);
      });

  @override
  Future<void> invalidate(DriveAccessToken token) async {
    try {
      await _ready();
      await _signIn.authorizationClient.clearAuthorizationToken(
        accessToken: token.value,
      );
    } catch (e) {
      debugPrint('clearAuthorizationToken failed: $e');
    }
  }

  @override
  Future<void> signOut() async {
    _account = null;
    try {
      await _ready();
      await _signIn.signOut();
    } catch (e) {
      debugPrint('Google sign-out failed: $e');
    }
  }

  static bool _sameEmail(String actual, String? expected) =>
      expected == null || actual.toLowerCase() == expected.toLowerCase();

  /// Every failure leaves as a [GoogleAuthException] with a Thai message.
  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on GoogleAuthException {
      rethrow;
    } on GoogleSignInException catch (e) {
      throw switch (e.code) {
        GoogleSignInExceptionCode.canceled ||
        GoogleSignInExceptionCode.interrupted => const GoogleAuthCanceled(),
        GoogleSignInExceptionCode.clientConfigurationError ||
        GoogleSignInExceptionCode
            .providerConfigurationError => GoogleAuthFailed(
          'ตั้งค่า Google Sign-In ของแอปไม่ถูกต้อง '
          '(ตรวจ GOOGLE_SERVER_CLIENT_ID และ SHA-1 ของ Android client ตาม KICKOFF ส่วนที่ 6)',
          e.description,
        ),
        _ => GoogleAuthFailed(
          'เข้าสู่ระบบ Google ไม่สำเร็จ ลองอีกครั้ง',
          e.description,
        ),
      };
    } catch (e) {
      // PlatformException from the plugin, a missing Play services, ...
      debugPrint('Google Sign-In failed: $e');
      throw GoogleAuthFailed('เข้าสู่ระบบ Google ไม่สำเร็จ ลองอีกครั้ง', '$e');
    }
  }
}

/// Used when the build has no client id: every call fails politely (the
/// UI is hidden anyway).
class DisabledGoogleAuth implements GoogleAuthGateway {
  const DisabledGoogleAuth();

  static const _off = GoogleAuthFailed(
    'แอปรุ่นนี้ไม่ได้ตั้งค่า Google Classroom (GOOGLE_SERVER_CLIENT_ID)',
  );

  @override
  Future<GoogleServerAuth> requestServerAuthCode() => Future.error(_off);

  @override
  Future<DriveAccessToken> driveAccessToken({String? expectedEmail}) =>
      Future.error(_off);

  @override
  Future<void> invalidate(DriveAccessToken token) async {}

  @override
  Future<void> signOut() async {}
}

final googleAuthProvider = Provider<GoogleAuthGateway>(
  (ref) => googleServerClientId.isEmpty
      ? const DisabledGoogleAuth()
      : PluginGoogleAuth(serverClientId: googleServerClientId),
);
