import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_connection.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_status_card.dart';
import 'package:eduvision/core/live/live_token_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

const _teacher = User(id: 12, name: 'ครูเอ', role: 'teacher');

/// Answers each sign-in with the next outcome; on success Firebase holds u12.
class _Live implements LiveSession {
  _Live(this.outcomes, {this.uid});

  final List<LiveSignInOutcome> outcomes;
  String? uid;
  int signIns = 0;

  @override
  bool get enabled => true;
  @override
  FirebaseFirestore? get firestore => null;
  @override
  String? get currentUid => uid;
  @override
  Stream<String?> get uidChanges => Stream.value(uid);
  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async {
    final outcome = outcomes[signIns++];
    if (outcome == LiveSignInOutcome.signedIn) uid = liveUidOf(userId);
    return outcome;
  }

  @override
  Future<void> signOut() async => uid = null;
}

class _Tokens implements LiveTokenApi {
  @override
  Future<FirebaseToken> fetch() async =>
      FirebaseToken(token: 't', uid: 'u12', expiresAt: DateTime.utc(2026));
}

Future<ProviderContainer> _pump(WidgetTester tester, LiveSession live) async {
  final container = ProviderContainer(
    overrides: [
      liveSessionProvider.overrideWithValue(live),
      liveTokenApiProvider.overrideWithValue(_Tokens()),
      currentUserProvider.overrideWithValue(_teacher),
    ],
  );
  addTearDown(container.dispose);
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: const MaterialApp(home: Scaffold(body: LiveStatusCard())),
    ),
  );
  await tester.pumpAndSettle();
  return container;
}

void main() {
  testWidgets('off when this build has no Firebase', (tester) async {
    await _pump(tester, const DisabledLiveSession());
    expect(find.byKey(const ValueKey('live_status_off')), findsOneWidget);
    expect(find.byKey(const ValueKey('live_status_retry')), findsNothing);
  });

  testWidgets('connected when Firebase holds this user', (tester) async {
    await _pump(tester, _Live(const [], uid: 'u12'));
    expect(find.byKey(const ValueKey('live_status_connected')), findsOneWidget);
    expect(find.byIcon(Icons.cloud_done_outlined), findsOneWidget);
  });

  testWidgets('server off, then "ลองอีกครั้ง" connects', (tester) async {
    final live = _Live(const [
      LiveSignInOutcome.serverOff,
      LiveSignInOutcome.signedIn,
    ]);
    final container = await _pump(tester, live);
    await container.read(liveConnectionProvider.notifier).connect(12);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('live_status_serverOff')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('live_status_retry')));
    await tester.pumpAndSettle();

    expect(live.signIns, 2);
    expect(container.read(liveConnectionProvider), LiveSignInOutcome.signedIn);
    // The uid stream of this fake does not re-emit; the outcome alone no
    // longer reports a problem and the retry button is gone.
    expect(find.byKey(const ValueKey('live_status_retry')), findsNothing);
  });

  testWidgets('a failed attempt offers a retry', (tester) async {
    final container = await _pump(
      tester,
      _Live(const [LiveSignInOutcome.failed]),
    );
    await container.read(liveConnectionProvider.notifier).connect(12);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('live_status_failed')), findsOneWidget);
    expect(find.byKey(const ValueKey('live_status_retry')), findsOneWidget);

    container.read(liveConnectionProvider.notifier).reset();
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('live_status_connecting')),
      findsOneWidget,
    );
  });

  test('connect on a disabled build reports disabled', () async {
    final container = ProviderContainer();
    addTearDown(container.dispose);
    expect(
      await container.read(liveConnectionProvider.notifier).connect(1),
      LiveSignInOutcome.disabled,
    );
  });
}
