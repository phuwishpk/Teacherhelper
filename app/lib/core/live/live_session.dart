import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:dio/dio.dart';
import 'package:firebase_auth/firebase_auth.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import 'live_token_api.dart';

/// The Firebase uid of a Krucheck user, as the server mints it (DESIGN §38.2).
String liveUidOf(int userId) => 'u$userId';

/// Seam over FirebaseAuth so the session logic runs in tests without Firebase.
abstract class LiveAuth {
  String? get currentUid;
  Future<void> signInWithCustomToken(String token);
  Future<void> signOut();
}

class FirebaseLiveAuth implements LiveAuth {
  FirebaseAuth get _auth => FirebaseAuth.instance;

  @override
  String? get currentUid => _auth.currentUser?.uid;

  @override
  Future<void> signInWithCustomToken(String token) =>
      _auth.signInWithCustomToken(token);

  @override
  Future<void> signOut() => _auth.signOut();
}

/// Why the last [LiveSession.signIn] did not end signed in (for the settings
/// screen and tests; the app itself never blocks on it).
enum LiveSignInOutcome {
  signedIn,
  alreadySignedIn,
  disabled,

  /// The server answered 503 `firestore_not_configured`.
  serverOff,
  failed,
}

/// The app's Firebase session next to its Sanctum one (DESIGN §38.6):
/// signed in with a custom token after login, signed out with it. Never
/// throws and never blocks login; a failure only means the live screens
/// fall back to pull-to-refresh.
abstract class LiveSession {
  bool get enabled;

  /// Null when Firebase is off; the Firestore the live screens listen on.
  FirebaseFirestore? get firestore;

  /// The uid Firebase currently holds, if any.
  String? get currentUid;

  Future<LiveSignInOutcome> signIn(int userId, FetchFirebaseToken fetchToken);

  Future<void> signOut();
}

class DisabledLiveSession implements LiveSession {
  const DisabledLiveSession();

  @override
  bool get enabled => false;
  @override
  FirebaseFirestore? get firestore => null;
  @override
  String? get currentUid => null;
  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async => LiveSignInOutcome.disabled;
  @override
  Future<void> signOut() async {}
}

class FirebaseLiveSession implements LiveSession {
  /// [firestore] overrides `FirebaseFirestore.instance` (tests).
  FirebaseLiveSession({required this.auth, FirebaseFirestore? firestore})
    : firestoreOverride = firestore;

  final LiveAuth auth;
  final FirebaseFirestore? firestoreOverride;

  @override
  bool get enabled => true;

  @override
  FirebaseFirestore? get firestore =>
      firestoreOverride ?? FirebaseFirestore.instance;

  @override
  String? get currentUid => auth.currentUid;

  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async {
    final uid = liveUidOf(userId);
    try {
      final current = auth.currentUid;
      if (current == uid) return LiveSignInOutcome.alreadySignedIn;
      if (current != null) await auth.signOut(); // another user's leftover
      final token = await fetchToken();
      await auth.signInWithCustomToken(token.token);
      if (token.uid != uid) {
        debugPrint('Live layer: server uid ${token.uid} differs from $uid');
      }
      return LiveSignInOutcome.signedIn;
    } on DioException catch (e) {
      if (apiErrorCode(e) == 'firestore_not_configured') {
        debugPrint('Live layer off on this server');
        return LiveSignInOutcome.serverOff;
      }
      debugPrint('Live layer sign-in failed: ${e.message}');
      return LiveSignInOutcome.failed;
    } catch (e) {
      debugPrint('Live layer sign-in failed: $e');
      return LiveSignInOutcome.failed;
    }
  }

  @override
  Future<void> signOut() async {
    try {
      if (auth.currentUid != null) await auth.signOut();
    } catch (e) {
      debugPrint('Live layer sign-out failed: $e');
    }
  }
}

/// Overridden in main.dart when Firebase started; tests keep the disabled one.
final liveSessionProvider = Provider<LiveSession>(
  (ref) => const DisabledLiveSession(),
);
