import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/local_user_data.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/push/devices_repository.dart';
import 'package:eduvision/core/push/push_coordinator.dart';
import 'package:eduvision/core/push/push_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

const _fcmToken = 'fcm-secret-token-xyz';
const _bearer = 'sanctum-secret-123';
const _teacher = User(id: 1, name: 'ครู', role: 'teacher');

class _Push implements PushMessaging {
  _Push({this.tokenError});

  final Object? tokenError;

  @override
  bool get enabled => true;
  @override
  Future<void> requestPermission() async {}
  @override
  Future<String?> token() async {
    if (tokenError != null) throw tokenError!;
    return _fcmToken;
  }

  @override
  Future<void> deleteToken() async {}
  @override
  Stream<String> get tokenRefreshes => const Stream.empty();
  @override
  Stream<PushMessage> get foregroundMessages => const Stream.empty();
  @override
  Stream<PushMessage> get openedMessages => const Stream.empty();
  @override
  Future<PushMessage?> initialMessage() async => null;
}

/// Fails the way a real 500 would: the request (with its bearer header and
/// body) and the response body all carry the secrets.
class _FailingDevices implements DevicesRepository {
  @override
  Future<void> register(String fcmToken) async {
    final options = RequestOptions(
      path: '/devices',
      data: {'fcm_token': fcmToken},
      headers: {'Authorization': 'Bearer $_bearer'},
    );
    throw DioException(
      requestOptions: options,
      response: Response(
        requestOptions: options,
        statusCode: 500,
        data: {'message': 'could not store $fcmToken for $_bearer'},
      ),
      type: DioExceptionType.badResponse,
    );
  }
}

class _Auth extends Fake implements AuthRepository {
  @override
  Future<String> login({
    required String email,
    required String password,
  }) async => _bearer;

  @override
  Future<User> me() async => _teacher;
}

/// Diagnostics printed on failure paths must never contain a credential.
void main() {
  late List<String> logs;

  setUp(() {
    logs = [];
    final original = debugPrint;
    debugPrint = (String? message, {int? wrapWidth}) => logs.add(message ?? '');
    addTearDown(() => debugPrint = original);
  });

  void expectNoSecret() {
    for (final line in logs) {
      expect(line, isNot(contains(_fcmToken)));
      expect(line, isNot(contains(_bearer)));
    }
  }

  test('a failed device registration logs the error type only', () async {
    final coordinator = PushCoordinator(
      push: _Push(),
      devices: _FailingDevices(),
      navigate: (_) {},
      canNavigate: () => true,
    );
    await coordinator.onSession(const SignedIn(_teacher));

    expect(logs, ['FCM device registration failed: DioException']);
    expectNoSecret();
  });

  test(
    'an FCM failure whose message carries the token logs the type only',
    () async {
      final coordinator = PushCoordinator(
        push: _Push(tokenError: StateError('no token: $_fcmToken $_bearer')),
        devices: _FailingDevices(),
        navigate: (_) {},
        canNavigate: () => true,
      );
      await coordinator.onSession(const SignedIn(_teacher));

      expect(logs, ['FCM call failed: StateError']);
      expectNoSecret();
    },
  );

  test(
    'sign-in survives a failed local wipe and logs nothing secret',
    () async {
      final storage = InMemoryTokenStorage(dataOwner: 99); // another teacher
      final container = ProviderContainer(
        overrides: [
          authRepositoryProvider.overrideWithValue(_Auth()),
          tokenStorageProvider.overrideWithValue(storage),
          localUserDataProvider.overrideWith(
            (ref) => throw StateError('no local database in this build'),
          ),
        ],
      );
      addTearDown(container.dispose);

      await container
          .read(sessionProvider.notifier)
          .signIn(email: 't@school.test', password: 'pw');

      expect(container.read(sessionProvider), isA<SignedIn>());
      expect(storage.token, _bearer);
      // The wipe failed, so the previous owner is kept for the next attempt.
      expect(storage.dataOwner, 99);
      expect(logs.single, startsWith('Local data wipe failed:'));
      expectNoSecret();
    },
  );
}
