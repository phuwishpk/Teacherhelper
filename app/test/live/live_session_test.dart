import 'package:dio/dio.dart';
import 'package:eduvision/core/firebase/firebase_bootstrap.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_token_api.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_test/flutter_test.dart';

/// FirebaseAuth stand-in: remembers the uid of the last custom token.
class _FakeAuth implements LiveAuth {
  _FakeAuth({this.currentUid, this.failSignIn = false});

  @override
  String? currentUid;
  bool failSignIn = false;
  final events = <String>[];

  @override
  Future<void> signInWithCustomToken(String token) async {
    events.add('signIn:$token');
    if (failSignIn) throw StateError('invalid custom token');
    currentUid = 'u${token.split('-').last}';
  }

  @override
  Future<void> signOut() async {
    events.add('signOut');
    currentUid = null;
  }
}

FirebaseToken _token(int userId) => FirebaseToken(
  token: 'tok-$userId',
  uid: 'u$userId',
  expiresAt: DateTime.utc(2026, 10, 21, 4),
);

DioException _api(int status, String code) => DioException(
  requestOptions: RequestOptions(path: '/auth/firebase-token'),
  response: Response(
    requestOptions: RequestOptions(path: '/auth/firebase-token'),
    statusCode: status,
    data: {'message': 'x', 'errors': {}, 'code': code},
  ),
);

void main() {
  group('FirebaseLiveSession', () {
    test(
      'signs in with the fetched custom token and exposes Firestore',
      () async {
        final auth = _FakeAuth();
        final firestore = FakeFirebaseFirestore();
        final session = FirebaseLiveSession(auth: auth, firestore: firestore);
        var fetches = 0;

        final outcome = await session.signIn(12, () async {
          fetches++;
          return _token(12);
        });

        expect(outcome, LiveSignInOutcome.signedIn);
        expect(fetches, 1);
        expect(auth.events, ['signIn:tok-12']);
        expect(session.currentUid, 'u12');
        expect(session.enabled, isTrue);
        expect(session.firestore, same(firestore));
      },
    );

    test('skips the server when Firebase already holds this uid', () async {
      final auth = _FakeAuth(currentUid: 'u12');
      final session = FirebaseLiveSession(auth: auth);

      final outcome = await session.signIn(12, () async {
        fail('no token needed');
      });

      expect(outcome, LiveSignInOutcome.alreadySignedIn);
      expect(auth.events, isEmpty);
    });

    test('signs another user\'s leftover out first', () async {
      final auth = _FakeAuth(currentUid: 'u3');
      final session = FirebaseLiveSession(auth: auth);

      await session.signIn(12, () async => _token(12));

      expect(auth.events, ['signOut', 'signIn:tok-12']);
      expect(auth.currentUid, 'u12');
    });

    test('a server without Firestore answers 503 and nothing throws', () async {
      final auth = _FakeAuth();
      final session = FirebaseLiveSession(auth: auth);

      final outcome = await session.signIn(
        12,
        () async => throw _api(503, 'firestore_not_configured'),
      );

      expect(outcome, LiveSignInOutcome.serverOff);
      expect(auth.events, isEmpty);
      expect(auth.currentUid, isNull);
    });

    test('other API errors and Firebase errors are swallowed', () async {
      final session = FirebaseLiveSession(auth: _FakeAuth());
      expect(
        await session.signIn(12, () async => throw _api(500, 'server_error')),
        LiveSignInOutcome.failed,
      );
      expect(
        await session.signIn(
          12,
          () async => throw DioException(
            requestOptions: RequestOptions(path: '/x'),
            type: DioExceptionType.connectionTimeout,
          ),
        ),
        LiveSignInOutcome.failed,
      );

      final broken = _FakeAuth(failSignIn: true);
      expect(
        await FirebaseLiveSession(
          auth: broken,
        ).signIn(12, () async => _token(12)),
        LiveSignInOutcome.failed,
      );
      expect(broken.currentUid, isNull);
    });

    test('signOut only calls Firebase when someone is signed in', () async {
      final auth = _FakeAuth();
      final session = FirebaseLiveSession(auth: auth);
      await session.signOut();
      expect(auth.events, isEmpty);

      auth.currentUid = 'u12';
      await session.signOut();
      expect(auth.events, ['signOut']);
      expect(session.currentUid, isNull);
    });

    test('a mismatching uid from the server still signs in', () async {
      final auth = _FakeAuth();
      final session = FirebaseLiveSession(auth: auth);
      final token = FirebaseToken(
        token: 'tok-99',
        uid: 'u99',
        expiresAt: DateTime.utc(2026),
      );
      expect(
        await session.signIn(12, () async => token),
        LiveSignInOutcome.signedIn,
      );
    });
  });

  test('DisabledLiveSession does nothing', () async {
    const session = DisabledLiveSession();
    expect(session.enabled, isFalse);
    expect(session.firestore, isNull);
    expect(session.currentUid, isNull);
    expect(
      await session.signIn(1, () async => fail('never fetched')),
      LiveSignInOutcome.disabled,
    );
    await session.signOut();
  });

  test('liveUidOf matches the server\'s FirestoreUid', () {
    expect(liveUidOf(12), 'u12');
  });

  test('FirebaseToken parses the API body', () {
    final token = FirebaseToken.fromJson({
      'token': 'abc',
      'uid': 'u5',
      'expires_at': '2026-10-21T04:00:00Z',
    });
    expect(token.token, 'abc');
    expect(token.uid, 'u5');
    expect(token.expiresAt, DateTime.utc(2026, 10, 21, 4));
  });

  group('webFirebaseOptions', () {
    test('needs api key, app id and project id', () {
      expect(
        webFirebaseOptions(),
        isNull,
        reason: 'tests pass no dart-defines',
      );
      expect(
        webFirebaseOptions(apiKey: 'k', appId: 'a', projectId: ''),
        isNull,
      );
      final options = webFirebaseOptions(
        apiKey: 'k',
        appId: 'a',
        projectId: 'krucheck',
        senderId: '123',
      )!;
      expect(options.projectId, 'krucheck');
      expect(options.authDomain, 'krucheck.firebaseapp.com');
      expect(options.messagingSenderId, '123');
    });
  });
}
