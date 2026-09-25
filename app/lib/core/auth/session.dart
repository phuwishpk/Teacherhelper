import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import 'auth_repository.dart';
import 'token_storage.dart';
import 'user.dart';

sealed class SessionState {
  const SessionState();
}

/// App just started; token not yet read from secure storage.
class SessionRestoring extends SessionState {
  const SessionRestoring();
}

class SignedOut extends SessionState {
  const SignedOut();
}

class SignedIn extends SessionState {
  const SignedIn(this.user);
  final User user;
}

/// Prefix of the payload printed on a student login card (DESIGN §5.4).
const studentCardQrPrefix = 'EVL1.';

class SessionNotifier extends Notifier<SessionState> {
  @override
  SessionState build() => const SessionRestoring();

  /// True when the current [SignedIn] state came from the cached `/me`
  /// payload because the server could not be reached at startup.
  bool get restoredOffline => _restoredOffline;
  bool _restoredOffline = false;

  /// Called once at startup: reuse a stored token if the API still accepts
  /// it. Without a network the last known user is used instead, so the
  /// teacher can still scan and queue uploads (DESIGN §6.3, §6.4); only a
  /// 401 (token revoked) signs the user out.
  Future<void> restore() async {
    final storage = ref.read(tokenStorageProvider);
    final token = await storage.read();
    if (token == null) {
      state = const SignedOut();
      return;
    }
    try {
      final user = await ref.read(authRepositoryProvider).me();
      await storage.writeUser(user.toJson());
      _restoredOffline = false;
      state = SignedIn(user);
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) {
        // The interceptor already cleared the token; make the state agree.
        await forceSignOut();
        return;
      }
      final cached = await _cachedUser(storage);
      if (cached == null) {
        state = const SignedOut();
        return;
      }
      _restoredOffline = true;
      state = SignedIn(cached);
    }
  }

  /// Re-fetches `/me` after an offline start; a no-op when the session is
  /// already fresh. Errors other than 401 keep the cached user.
  Future<void> refreshUser() async {
    if (!_restoredOffline || state is! SignedIn) return;
    try {
      final user = await ref.read(authRepositoryProvider).me();
      await ref.read(tokenStorageProvider).writeUser(user.toJson());
      _restoredOffline = false;
      state = SignedIn(user);
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) await forceSignOut();
    }
  }

  Future<User?> _cachedUser(TokenStorage storage) async {
    final json = await storage.readUser();
    if (json == null) return null;
    try {
      return User.fromJson(json);
    } on TypeError {
      return null;
    }
  }

  /// Throws DioException on failure; the screen shows apiErrorMessage().
  Future<void> signIn({required String email, required String password}) async {
    final repo = ref.read(authRepositoryProvider);
    await _finishSignIn(repo.login(email: email, password: password));
  }

  /// [qrPayload] is the raw QR content (`EVL1.{token}`) or the bare token.
  Future<void> signInStudentQr(String qrPayload) async {
    final token = qrPayload.startsWith(studentCardQrPrefix)
        ? qrPayload.substring(studentCardQrPrefix.length)
        : qrPayload;
    final repo = ref.read(authRepositoryProvider);
    await _finishSignIn(repo.loginStudentQr(token.trim()));
  }

  Future<void> signInStudentPin({
    required String classCode,
    required int studentNumber,
    required String pin,
  }) async {
    final repo = ref.read(authRepositoryProvider);
    await _finishSignIn(
      repo.loginStudentPin(
        classCode: classCode,
        studentNumber: studentNumber,
        pin: pin,
      ),
    );
  }

  Future<void> _finishSignIn(Future<String> tokenFuture) async {
    final token = await tokenFuture;
    final storage = ref.read(tokenStorageProvider);
    await storage.write(token);
    final user = await ref.read(authRepositoryProvider).me();
    await storage.writeUser(user.toJson());
    _restoredOffline = false;
    state = SignedIn(user);
  }

  Future<void> signOut() async {
    try {
      await ref.read(authRepositoryProvider).logout();
    } on DioException {
      // Token may already be invalid; clearing locally is what matters.
    }
    await forceSignOut();
  }

  Future<void> forceSignOut() async {
    await ref.read(tokenStorageProvider).clear();
    _restoredOffline = false;
    state = const SignedOut();
  }
}

final sessionProvider = NotifierProvider<SessionNotifier, SessionState>(
  SessionNotifier.new,
);

/// The signed-in user, or null while restoring / signed out.
final currentUserProvider = Provider<User?>((ref) {
  return switch (ref.watch(sessionProvider)) {
    SignedIn(:final user) => user,
    _ => null,
  };
});
