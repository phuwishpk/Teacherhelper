import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_sign_in/google_sign_in.dart';

/// `initialize` fails [failures] times, then works. `authenticate` is not
/// supported, so a call stops right after initialize with a known error.
class _FakeSignIn extends Fake implements GoogleSignIn {
  _FakeSignIn(this.failures);

  final List<Object> failures;
  final initializedWith = <String?>[];

  @override
  Future<void> initialize({
    String? clientId,
    String? serverClientId,
    String? nonce,
    String? hostedDomain,
  }) async {
    initializedWith.add(serverClientId);
    if (failures.isNotEmpty) throw failures.removeAt(0);
  }

  @override
  bool supportsAuthenticate() => false;

  /// No account signed in on this device yet.
  @override
  Future<GoogleSignInAccount?>? attemptLightweightAuthentication({
    bool reportAllExceptions = false,
  }) => null;
}

const _noAuthenticate = 'อุปกรณ์นี้เข้าสู่ระบบ Google จากแอปไม่ได้';

void main() {
  test('a failed initialize is retried on the next call', () async {
    final signIn = _FakeSignIn([
      const GoogleSignInException(
        code: GoogleSignInExceptionCode.unknownError,
        description: 'Play services busy',
      ),
    ]);
    final auth = PluginGoogleAuth(serverClientId: 'web.apps', signIn: signIn);

    await expectLater(
      auth.requestServerAuthCode(),
      throwsA(
        isA<GoogleAuthFailed>().having(
          (e) => e.message,
          'message',
          'เข้าสู่ระบบ Google ไม่สำเร็จ ลองอีกครั้ง',
        ),
      ),
    );
    // Not cached: the second tap initializes again and gets further.
    await expectLater(
      auth.requestServerAuthCode(),
      throwsA(
        isA<GoogleAuthFailed>().having(
          (e) => e.message,
          'message',
          _noAuthenticate,
        ),
      ),
    );
    expect(signIn.initializedWith, ['web.apps', 'web.apps']);

    // Once it worked, it is not called again.
    await expectLater(
      auth.driveAccessToken(),
      throwsA(
        isA<GoogleAuthFailed>().having(
          (e) => e.message,
          'message',
          _noAuthenticate,
        ),
      ),
    );
    expect(signIn.initializedWith, hasLength(2));
  });

  test('errors other than GoogleSignInException become Thai too', () async {
    final signIn = _FakeSignIn([
      PlatformException(code: 'channel-error', message: 'no plugin'),
    ]);
    final auth = PluginGoogleAuth(serverClientId: 'web.apps', signIn: signIn);

    await expectLater(
      auth.driveAccessToken(expectedEmail: 'kru@school.ac.th'),
      throwsA(
        isA<GoogleAuthFailed>()
            .having(
              (e) => e.message,
              'message',
              'เข้าสู่ระบบ Google ไม่สำเร็จ ลองอีกครั้ง',
            )
            .having((e) => e.detail, 'detail', contains('channel-error')),
      ),
    );
    await expectLater(
      auth.requestServerAuthCode(),
      throwsA(
        isA<GoogleAuthFailed>().having(
          (e) => e.message,
          'message',
          _noAuthenticate,
        ),
      ),
    );
  });

  test('a configuration error explains what to check', () async {
    final auth = PluginGoogleAuth(
      serverClientId: 'web.apps',
      signIn: _FakeSignIn([
        const GoogleSignInException(
          code: GoogleSignInExceptionCode.clientConfigurationError,
        ),
      ]),
    );
    await expectLater(
      auth.requestServerAuthCode(),
      throwsA(
        isA<GoogleAuthFailed>().having(
          (e) => e.message,
          'message',
          contains('GOOGLE_SERVER_CLIENT_ID'),
        ),
      ),
    );
  });
}
