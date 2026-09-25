import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeAuth extends Fake implements AuthRepository {
  _FakeAuth({this.meError, this.user});

  Object? meError;
  User? user;
  int meCalls = 0;

  @override
  Future<User> me() async {
    meCalls++;
    if (meError != null) throw meError!;
    return user!;
  }

  @override
  Future<void> logout() async {}
}

DioException _connectionError() => DioException.connectionError(
  requestOptions: RequestOptions(path: '/me'),
  reason: 'offline',
);

DioException _unauthorized() {
  final req = RequestOptions(path: '/me');
  return DioException(
    requestOptions: req,
    response: Response(requestOptions: req, statusCode: 401),
  );
}

const _teacher = User(
  id: 1,
  name: 'ครูสมศรี',
  role: 'teacher',
  email: 't@example.com',
  status: 'active',
  schoolId: 3,
  schoolName: 'โรงเรียนทดสอบ',
);

ProviderContainer _container(_FakeAuth auth, TokenStorage storage) {
  final container = ProviderContainer(
    overrides: [
      authRepositoryProvider.overrideWithValue(auth),
      tokenStorageProvider.overrideWithValue(storage),
    ],
  );
  addTearDown(container.dispose);
  return container;
}

void main() {
  test('User.toJson round-trips through fromJson', () {
    final again = User.fromJson(_teacher.toJson());
    expect(again.id, 1);
    expect(again.name, 'ครูสมศรี');
    expect(again.role, 'teacher');
    expect(again.email, 't@example.com');
    expect(again.status, 'active');
    expect(again.schoolId, 3);
    expect(again.schoolName, 'โรงเรียนทดสอบ');
  });

  test('no stored token -> SignedOut without calling /me', () async {
    final auth = _FakeAuth(user: _teacher);
    final c = _container(auth, InMemoryTokenStorage());
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider), isA<SignedOut>());
    expect(auth.meCalls, 0);
  });

  test('online restore caches the /me payload', () async {
    final auth = _FakeAuth(user: _teacher);
    final storage = InMemoryTokenStorage(token: 'tok');
    final c = _container(auth, storage);
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider), isA<SignedIn>());
    expect((await storage.readUser())?['name'], 'ครูสมศรี');
    expect(c.read(sessionProvider.notifier).restoredOffline, isFalse);
  });

  test(
    'offline restore with a cached user keeps the teacher signed in',
    () async {
      final auth = _FakeAuth(meError: _connectionError());
      final storage = InMemoryTokenStorage(
        token: 'tok',
        user: _teacher.toJson(),
      );
      final c = _container(auth, storage);
      await c.read(sessionProvider.notifier).restore();

      final session = c.read(sessionProvider);
      expect(session, isA<SignedIn>());
      expect((session as SignedIn).user.isTeacher, isTrue);
      expect(session.user.schoolName, 'โรงเรียนทดสอบ');
      expect(await storage.read(), 'tok', reason: 'token is kept');
      expect(c.read(sessionProvider.notifier).restoredOffline, isTrue);
    },
  );

  test('offline restore without a cached user falls back to login', () async {
    final auth = _FakeAuth(meError: _connectionError());
    final storage = InMemoryTokenStorage(token: 'tok');
    final c = _container(auth, storage);
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider), isA<SignedOut>());
  });

  test('401 on restore clears token and cached user', () async {
    final auth = _FakeAuth(meError: _unauthorized());
    final storage = InMemoryTokenStorage(
      token: 'revoked',
      user: _teacher.toJson(),
    );
    final c = _container(auth, storage);
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider), isA<SignedOut>());
    expect(await storage.read(), isNull);
    expect(await storage.readUser(), isNull);
  });

  test(
    'refreshUser replaces the cached user once the server answers',
    () async {
      final auth = _FakeAuth(meError: _connectionError());
      final storage = InMemoryTokenStorage(
        token: 'tok',
        user: _teacher.toJson(),
      );
      final c = _container(auth, storage);
      final notifier = c.read(sessionProvider.notifier);
      await notifier.restore();

      // Still offline: nothing changes.
      await notifier.refreshUser();
      expect(notifier.restoredOffline, isTrue);

      auth
        ..meError = null
        ..user = const User(id: 1, name: 'ครูสมศรี (แก้ชื่อ)', role: 'teacher');
      await notifier.refreshUser();
      expect(notifier.restoredOffline, isFalse);
      expect(
        (c.read(sessionProvider) as SignedIn).user.name,
        'ครูสมศรี (แก้ชื่อ)',
      );
      expect((await storage.readUser())?['name'], 'ครูสมศรี (แก้ชื่อ)');

      // A fresh session does not hit /me again.
      final calls = auth.meCalls;
      await notifier.refreshUser();
      expect(auth.meCalls, calls);
    },
  );
}
