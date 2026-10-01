import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../google_classroom/google_auth.dart'
    show GoogleAuthCanceled, GoogleAuthException, GoogleAuthFailed;
import 'google_signin_config.dart';

/// Gets a Google ID token on the device for `POST /auth/google` and
/// `POST /me/google-identity` (DESIGN §24.9.4). Behind an interface so
/// screens and tests never touch the plugin.
abstract class GoogleSignInGateway {
  /// The account picker, then the ID token of the chosen account. Throws a
  /// [GoogleAuthException] with a Thai message ([GoogleAuthCanceled] when
  /// the user closed the picker).
  Future<String> idToken();
}

/// `google_sign_in` 7: `initialize(serverClientId:)` once, `authenticate()`
/// without extra scopes, `account.authentication.idToken`, then `signOut()`
/// so the next person on a shared classroom phone picks their own account.
class PluginGoogleSignInGateway implements GoogleSignInGateway {
  PluginGoogleSignInGateway({
    required this.serverClientId,
    GoogleSignIn? signIn,
  }) : _signIn = signIn ?? GoogleSignIn.instance;

  final String serverClientId;
  final GoogleSignIn _signIn;
  Future<void>? _initialized;

  /// A failed initialize is not kept, so the next tap tries again.
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
  Future<String> idToken() async {
    try {
      await _ready();
      if (!_signIn.supportsAuthenticate()) {
        throw const GoogleAuthFailed(
          'อุปกรณ์นี้เข้าสู่ระบบด้วย Google จากแอปไม่ได้',
        );
      }
      final account = await _signIn.authenticate();
      final token = account.authentication.idToken;
      await _forget();
      if (token == null || token.isEmpty) {
        throw const GoogleAuthFailed(
          'Google ไม่ส่งข้อมูลยืนยันกลับมา ลองอีกครั้ง',
        );
      }
      return token;
    } on GoogleAuthException {
      rethrow;
    } on GoogleSignInException catch (e) {
      throw switch (e.code) {
        GoogleSignInExceptionCode.canceled ||
        GoogleSignInExceptionCode.interrupted => const GoogleAuthCanceled(),
        GoogleSignInExceptionCode.clientConfigurationError ||
        GoogleSignInExceptionCode
            .providerConfigurationError => GoogleAuthFailed(
          'ตั้งค่าการเข้าสู่ระบบด้วย Google ของแอปไม่ถูกต้อง '
          '(ตรวจ GOOGLE_SIGNIN_CLIENT_ID และ SHA-1 ของ Android client ตาม DESIGN §24.9.1)',
          e.description,
        ),
        _ => GoogleAuthFailed(
          'เข้าสู่ระบบด้วย Google ไม่สำเร็จ ลองอีกครั้ง',
          e.description,
        ),
      };
    } catch (e) {
      debugPrint('Google sign-in failed: $e');
      throw GoogleAuthFailed(
        'เข้าสู่ระบบด้วย Google ไม่สำเร็จ ลองอีกครั้ง',
        '$e',
      );
    }
  }

  /// Best effort: the token is already in hand.
  Future<void> _forget() async {
    try {
      await _signIn.signOut();
    } catch (e) {
      debugPrint('Google sign-out failed: $e');
    }
  }
}

/// A build without GOOGLE_SIGNIN_CLIENT_ID, and the web (which uses the
/// server's redirect flow instead).
class DisabledGoogleSignInGateway implements GoogleSignInGateway {
  const DisabledGoogleSignInGateway();

  @override
  Future<String> idToken() => Future.error(
    const GoogleAuthFailed(
      'แอปรุ่นนี้ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google (GOOGLE_SIGNIN_CLIENT_ID)',
    ),
  );
}

final googleSignInGatewayProvider = Provider<GoogleSignInGateway>(
  (ref) => googleSignInNativeBuild
      ? PluginGoogleSignInGateway(serverClientId: googleSigninClientId)
      : const DisabledGoogleSignInGateway(),
);
