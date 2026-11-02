import 'dart:async';

import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_connection.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_token_api.dart';
import 'package:eduvision/features/onboarding/onboarding_store.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Firebase signs in a moment after the shell opens, as
/// SessionNotifier._startLive does: in the background, never awaited.
class _LateLive implements LiveSession {
  _LateLive(this.db);

  final FirebaseFirestore db;
  final _uids = StreamController<String?>.broadcast();
  final _answer = Completer<LiveSignInOutcome>();
  String? _uid;

  void connected(String uid) {
    _uid = uid;
    _uids.add(uid);
    _answer.complete(LiveSignInOutcome.signedIn);
  }

  void failed() => _answer.complete(LiveSignInOutcome.failed);

  @override
  bool get enabled => true;
  @override
  FirebaseFirestore? get firestore => db;
  @override
  String? get currentUid => _uid;
  @override
  Stream<String?> get uidChanges => _uids.stream;
  @override
  Future<LiveSignInOutcome> signIn(int userId, FetchFirebaseToken fetchToken) =>
      _answer.future;
  @override
  Future<void> signOut() async {}
}

void main() {
  const teacher = User(id: 1, name: 'ครูสมศรี', role: 'teacher');

  Future<void> settle() =>
      Future<void>.delayed(const Duration(milliseconds: 20));

  /// What a shell listening to [onboardingNeededProvider] is told.
  List<bool> listen(ProviderContainer container) {
    final seen = <bool>[];
    final sub = container.listen(onboardingNeededProvider, (_, next) {
      if (next.hasValue && !next.isLoading) seen.add(next.requireValue);
    });
    addTearDown(sub.close);
    return seen;
  }

  ProviderContainer containerWith(LiveSession live, TokenStorage storage) {
    final container = ProviderContainer(
      overrides: [
        liveSessionProvider.overrideWithValue(live),
        currentUserProvider.overrideWithValue(teacher),
        tokenStorageProvider.overrideWithValue(storage),
      ],
    );
    addTearDown(container.dispose);
    return container;
  }

  test('a second device waits for Firebase and skips the slides', () async {
    final db = FakeFirebaseFirestore();
    await db.doc('users/u1/settings/app').set({
      'onboarding_done_at': Timestamp.fromDate(DateTime.utc(2026, 11, 1)),
      'device': 'web',
    });
    final storage = InMemoryTokenStorage();
    final live = _LateLive(db);
    final container = containerWith(live, storage);

    final seen = listen(container);
    unawaited(container.read(liveConnectionProvider.notifier).connect(1));
    await settle();
    live.connected('u1');
    await settle();

    expect(seen, isNot(contains(true)));
    expect(storage.onboardingDone, contains(1));
  });

  test('a new account gets the slides once Firebase has answered', () async {
    final live = _LateLive(FakeFirebaseFirestore());
    final container = containerWith(live, InMemoryTokenStorage());

    final seen = listen(container);
    unawaited(container.read(liveConnectionProvider.notifier).connect(1));
    await settle();
    expect(seen, isNot(contains(true)));

    live.connected('u1');
    await settle();
    expect(seen.last, isTrue);
  });

  test('Firebase failing falls back to the device', () async {
    final live = _LateLive(FakeFirebaseFirestore());
    final container = containerWith(live, InMemoryTokenStorage());

    final seen = listen(container);
    unawaited(container.read(liveConnectionProvider.notifier).connect(1));
    await settle();
    live.failed();
    await settle();

    expect(seen.last, isTrue);
  });

  test('without Firestore the device answers at once', () async {
    final container = containerWith(
      const DisabledLiveSession(),
      InMemoryTokenStorage(),
    );

    final seen = listen(container);
    await settle();

    expect(seen.last, isTrue);
  });
}
