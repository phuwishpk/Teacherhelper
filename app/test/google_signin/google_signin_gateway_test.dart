import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_signin/google_signin_gateway.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_sign_in/google_sign_in.dart';

class _Account extends Fake implements GoogleSignInAccount {
  _Account(this.idToken);

  final String? idToken;

  @override
  GoogleSignInAuthentication get authentication =>
      GoogleSignInAuthentication(idToken: idToken);
}

/// `google_sign_in` without the platform: [result] is what `authenticate`
/// gives or throws.
class _FakeSignIn extends Fake implements GoogleSignIn {
  _FakeSignIn({this.result, this.supported = true, this.initError});

  Object? result;
  final bool supported;
  Object? initError;
  final initializedWith = <String?>[];
  final scopeHints = <List<String>>[];
  int signOuts = 0;

  @override
  Future<void> initialize({
    String? clientId,
    String? serverClientId,
    String? nonce,
    String? hostedDomain,
  }) async {
    initializedWith.add(serverClientId);
    final e = initError;
    initError = null;
    if (e != null) throw e;
  }

  @override
  bool supportsAuthenticate() => supported;

  @override
  Future<GoogleSignInAccount> authenticate({
    List<String> scopeHint = const <String>[],
  }) async {
    scopeHints.add(scopeHint);
    final r = result;
    if (r is GoogleSignInAccount) return r;
    throw r!;
  }

  @override
  Future<void> signOut() async => signOuts++;
}

Matcher _failed(String text) => throwsA(
  isA<GoogleAuthFailed>().having((e) => e.message, 'message', contains(text)),
);

void main() {
  test('returns the ID token, asks no scope and forgets the account', () async {
    final plugin = _FakeSignIn(result: _Account('id.token'));
    final gateway = PluginGoogleSignInGateway(
      serverClientId: 'signin.apps',
      signIn: plugin,
    );

    expect(await gateway.idToken(), 'id.token');
    expect(await gateway.idToken(), 'id.token');
    expect(plugin.initializedWith, ['signin.apps'], reason: 'once');
    expect(plugin.scopeHints, [isEmpty, isEmpty]);
    expect(plugin.signOuts, 2, reason: 'a shared phone picks again');
  });

  test('a cancelled picker is GoogleAuthCanceled', () async {
    final gateway = PluginGoogleSignInGateway(
      serverClientId: 'x',
      signIn: _FakeSignIn(
        result: const GoogleSignInException(
          code: GoogleSignInExceptionCode.canceled,
        ),
      ),
    );
    await expectLater(gateway.idToken(), throwsA(isA<GoogleAuthCanceled>()));
  });

  test('a configuration error names GOOGLE_SIGNIN_CLIENT_ID', () async {
    final gateway = PluginGoogleSignInGateway(
      serverClientId: 'x',
      signIn: _FakeSignIn(
        result: const GoogleSignInException(
          code: GoogleSignInExceptionCode.clientConfigurationError,
        ),
      ),
    );
    await expectLater(gateway.idToken(), _failed('GOOGLE_SIGNIN_CLIENT_ID'));
  });

  test('no ID token, no authenticate, other errors', () async {
    await expectLater(
      PluginGoogleSignInGateway(
        serverClientId: 'x',
        signIn: _FakeSignIn(result: _Account(null)),
      ).idToken(),
      _failed('ไม่ส่งข้อมูลยืนยัน'),
    );
    await expectLater(
      PluginGoogleSignInGateway(
        serverClientId: 'x',
        signIn: _FakeSignIn(supported: false),
      ).idToken(),
      _failed('จากแอปไม่ได้'),
    );
    await expectLater(
      PluginGoogleSignInGateway(
        serverClientId: 'x',
        signIn: _FakeSignIn(
          result: const GoogleSignInException(
            code: GoogleSignInExceptionCode.unknownError,
          ),
        ),
      ).idToken(),
      _failed('ไม่สำเร็จ'),
    );
    await expectLater(
      PluginGoogleSignInGateway(
        serverClientId: 'x',
        signIn: _FakeSignIn(result: PlatformException(code: 'boom')),
      ).idToken(),
      _failed('ไม่สำเร็จ'),
    );
  });

  test('a failed initialize is tried again on the next tap', () async {
    final plugin = _FakeSignIn(
      result: _Account('id.token'),
      initError: PlatformException(code: 'busy'),
    );
    final gateway = PluginGoogleSignInGateway(
      serverClientId: 'x',
      signIn: plugin,
    );
    await expectLater(gateway.idToken(), _failed('ไม่สำเร็จ'));
    expect(await gateway.idToken(), 'id.token');
    expect(plugin.initializedWith, ['x', 'x']);
  });

  test('a build without the client id refuses politely', () async {
    await expectLater(
      const DisabledGoogleSignInGateway().idToken(),
      _failed('GOOGLE_SIGNIN_CLIENT_ID'),
    );
  });
}
