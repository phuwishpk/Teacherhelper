import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import 'auth_repository.dart';
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

class SessionNotifier extends Notifier<SessionState> {
  @override
  SessionState build() => const SessionRestoring();

  /// Called once at startup: reuse a stored token if the API still accepts it.
  Future<void> restore() async {
    final token = await ref.read(tokenStorageProvider).read();
    if (token == null) {
      state = const SignedOut();
      return;
    }
    try {
      state = SignedIn(await ref.read(authRepositoryProvider).me());
    } on DioException {
      // 401 already cleared the token via the interceptor; network errors
      // also fall back to the login screen in M0.
      state = const SignedOut();
    }
  }

  /// Throws DioException on failure; the screen shows apiErrorMessage().
  Future<void> signIn({required String email, required String password}) async {
    final repo = ref.read(authRepositoryProvider);
    final token = await repo.login(email: email, password: password);
    await ref.read(tokenStorageProvider).write(token);
    state = SignedIn(await repo.me());
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
    state = const SignedOut();
  }
}

final sessionProvider = NotifierProvider<SessionNotifier, SessionState>(
  SessionNotifier.new,
);
