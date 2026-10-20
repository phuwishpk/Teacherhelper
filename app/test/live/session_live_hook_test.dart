import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/local_user_data.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_token_api.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

const _teacher = User(id: 7, name: 'ครูเอ', role: 'teacher');

class _FakeAuth extends Fake implements AuthRepository {
  _FakeAuth({this.meFails = false});

  bool meFails = false;

  @override
  Future<String> login({
    required String email,
    required String password,
  }) async => 'tok-7';

  @override
  Future<User> me() async {
    if (meFails) {
      throw DioException(
        requestOptions: RequestOptions(path: '/me'),
        type: DioExceptionType.connectionTimeout,
      );
    }
    return _teacher;
  }

  @override
  Future<void> logout() async {}
}

class _NoLocalData extends Fake implements LocalUserData {
  @override
  Future<void> wipe() async {}
}

/// Records what the session asks of the live layer, and runs the fetch so
/// the test sees the token call too.
class _RecordingLive implements LiveSession {
  _RecordingLive({this.enabled = true});

  @override
  final bool enabled;
  final events = <String>[];

  @override
  FirebaseFirestore? get firestore => null;
  @override
  String? get currentUid => null;

  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async {
    events.add('signIn:$userId');
    try {
      final token = await fetchToken();
      events.add('token:${token.uid}');
    } on DioException catch (e) {
      events.add('fetchFailed:${e.response?.statusCode}');
    }
    return LiveSignInOutcome.signedIn;
  }

  @override
  Future<void> signOut() async => events.add('signOut');
}

class _FakeTokenApi extends Fake implements LiveTokenApi {
  _FakeTokenApi({this.status});

  final int? status;

  @override
  Future<FirebaseToken> fetch() async {
    if (status != null) {
      throw DioException(
        requestOptions: RequestOptions(path: '/auth/firebase-token'),
        response: Response(
          requestOptions: RequestOptions(path: '/auth/firebase-token'),
          statusCode: status,
          data: {'code': 'firestore_not_configured'},
        ),
      );
    }
    return FirebaseToken(token: 't', uid: 'u7', expiresAt: DateTime.utc(2026));
  }
}

ProviderContainer _container({
  required LiveSession live,
  AuthRepository? auth,
  LiveTokenApi? tokens,
  TokenStorage? storage,
}) => ProviderContainer(
  overrides: [
    authRepositoryProvider.overrideWithValue(auth ?? _FakeAuth()),
    liveSessionProvider.overrideWithValue(live),
    liveTokenApiProvider.overrideWithValue(tokens ?? _FakeTokenApi()),
    tokenStorageProvider.overrideWithValue(storage ?? InMemoryTokenStorage()),
    localUserDataProvider.overrideWithValue(_NoLocalData()),
  ],
);

void main() {
  test(
    'sign-in starts the live session with the user id and fetches a token',
    () async {
      final live = _RecordingLive();
      final container = _container(live: live);
      addTearDown(container.dispose);

      await container
          .read(sessionProvider.notifier)
          .signIn(email: 'a@b.c', password: 'secret1234');
      await Future<void>.delayed(Duration.zero);

      expect(container.read(sessionProvider), isA<SignedIn>());
      expect(live.events, ['signIn:7', 'token:u7']);
    },
  );

  test('restoring a stored token starts the live session too', () async {
    final live = _RecordingLive();
    final storage = InMemoryTokenStorage();
    await storage.write('tok-7');
    final container = _container(live: live, storage: storage);
    addTearDown(container.dispose);

    await container.read(sessionProvider.notifier).restore();
    await Future<void>.delayed(Duration.zero);

    expect(live.events, ['signIn:7', 'token:u7']);
  });

  test('an offline restore does not ask for a token', () async {
    final live = _RecordingLive();
    final storage = InMemoryTokenStorage();
    await storage.write('tok-7');
    await storage.writeUser(_teacher.toJson());
    final container = _container(
      live: live,
      storage: storage,
      auth: _FakeAuth(meFails: true),
    );
    addTearDown(container.dispose);

    await container.read(sessionProvider.notifier).restore();
    await Future<void>.delayed(Duration.zero);

    expect(container.read(sessionProvider), isA<SignedIn>());
    expect(live.events, isEmpty);
  });

  test('a disabled live layer is never asked', () async {
    final live = _RecordingLive(enabled: false);
    final container = _container(live: live);
    addTearDown(container.dispose);

    await container
        .read(sessionProvider.notifier)
        .signIn(email: 'a@b.c', password: 'secret1234');
    await Future<void>.delayed(Duration.zero);

    expect(live.events, isEmpty);
  });

  test('a 503 from the server never fails the sign-in', () async {
    final live = _RecordingLive();
    final container = _container(
      live: live,
      tokens: _FakeTokenApi(status: 503),
    );
    addTearDown(container.dispose);

    await container
        .read(sessionProvider.notifier)
        .signIn(email: 'a@b.c', password: 'secret1234');
    await Future<void>.delayed(Duration.zero);

    expect(container.read(sessionProvider), isA<SignedIn>());
    expect(live.events, ['signIn:7', 'fetchFailed:503']);
  });

  test('signing out signs the live session out first', () async {
    final live = _RecordingLive();
    final container = _container(live: live);
    addTearDown(container.dispose);
    final session = container.read(sessionProvider.notifier);
    await session.signIn(email: 'a@b.c', password: 'secret1234');
    await Future<void>.delayed(Duration.zero);

    await session.signOut();

    expect(container.read(sessionProvider), isA<SignedOut>());
    expect(live.events.last, 'signOut');

    // A 401 (forceSignOut) too.
    live.events.clear();
    await session.forceSignOut();
    expect(live.events, ['signOut']);
  });
}
