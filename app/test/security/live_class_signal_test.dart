import 'dart:io';

import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_scope.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/features/class_posts/class_posts_repository.dart';
import 'package:eduvision/features/student/live_refresh.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../live/live_test_support.dart';

const _student = User(id: 11, name: 'นักเรียน ก', role: 'student');

/// Security tests for live class-feed and attendance signals (C17 / DESIGN §38.11).
///
/// CI does not run the Firebase emulator, so these tests check the rules file
/// content rather than actually evaluating the rules; see comment on test 4.
void main() {
  late FakeFirebaseFirestore db;

  setUp(() => db = FakeFirebaseFirestore());

  // ── 1. Documents of another uid never trigger a class-feed refetch ────────

  test('another uid class_feed document does not tick liveClassFeedTicks', () async {
    final container = ProviderContainer(
      overrides: [
        liveSessionProvider.overrideWithValue(
          FakeLiveSession(db: db, uid: 'u11'),
        ),
        currentUserProvider.overrideWithValue(_student),
      ],
    );
    addTearDown(container.dispose);

    // Pre-seed so first snapshot is empty for the signed-in user.
    await db.doc('users/u11/class_feed/7').set({'classroom_id': 7});

    final feedTicks = <int>[];
    container.listen(
      liveClassFeedTicksProvider,
      (_, next) => next.whenData(feedTicks.add),
      fireImmediately: true,
    );
    await pumpEventQueue();
    expect(feedTicks, isEmpty, reason: 'initial snapshot is not a change');

    // Write another user's document – should NOT tick our feed.
    await db.doc('users/u99/class_feed/7').set({'classroom_id': 7, 'updated_at': 1});
    await pumpEventQueue();
    expect(feedTicks, isEmpty, reason: 'u99 feed must not reach u11 ticks');

    // Write our own document – SHOULD tick.
    await db.doc('users/u11/class_feed/7').set({'classroom_id': 7, 'updated_at': 1});
    await pumpEventQueue();
    expect(feedTicks, [1]);
  });

  // ── 2. Mismatched Firebase uid → no live listening, no badge ─────────────

  test('live layer off when Firebase uid does not match currentUser', () async {
    // FakeLiveSession with uid='u99', but currentUser is id=11 (uid='u11').
    final container = ProviderContainer(
      overrides: [
        liveSessionProvider.overrideWithValue(
          FakeLiveSession(db: db, uid: 'u99'), // wrong uid
        ),
        currentUserProvider.overrideWithValue(_student),
      ],
    );
    addTearDown(container.dispose);

    final feedTicks = <int>[];
    container.listen(
      liveClassFeedTicksProvider,
      (_, next) => next.whenData(feedTicks.add),
    );
    await pumpEventQueue();

    // Live layer should be unavailable → no badge, no ticks.
    expect(container.read(liveAvailableProvider), isFalse);

    // Write to both paths – nothing should tick.
    await db.doc('users/u11/class_feed/7').set({'classroom_id': 7, 'updated_at': 1});
    await db.doc('users/u99/class_feed/7').set({'classroom_id': 7, 'updated_at': 1});
    await pumpEventQueue();
    expect(feedTicks, isEmpty);
  });

  // ── 3. The app never writes to Firestore ─────────────────────────────────

  test('after a full live flow the app wrote no Firestore documents itself', () async {
    final container = ProviderContainer(
      overrides: [
        liveSessionProvider.overrideWithValue(
          FakeLiveSession(db: db, uid: 'u11'),
        ),
        currentUserProvider.overrideWithValue(_student),
        // Also watch classFeedProvider to exercise the listener path.
        classPostsRepositoryProvider.overrideWith(
          (ref) => throw UnimplementedError(),
        ),
      ],
    );
    addTearDown(container.dispose);

    // Let the providers initialise.
    container.listen(
      liveClassFeedTicksProvider,
      (_, next) => next.whenData((_) {}),
    );
    await pumpEventQueue();

    // Backend writes both of these (simulated here).
    await db.doc('users/u11/class_feed/7').set({'classroom_id': 7, 'updated_at': 1});
    await db.doc('users/u11/attendance/3_7').set({'course_id': 3, 'classroom_id': 7, 'updated_at': 1});
    await pumpEventQueue();

    // Verify: exactly the two documents the "backend" wrote, nothing more.
    final feedDocs =
        (await db.collection('users/u11/class_feed').get()).docs;
    final attendanceDocs =
        (await db.collection('users/u11/attendance').get()).docs;
    expect(feedDocs, hasLength(1),
        reason: 'app must not write extra class_feed documents');
    expect(attendanceDocs, hasLength(1),
        reason: 'app must not write extra attendance documents');

    // No classrooms collection must exist.
    final classroomDocs =
        (await db.collection('classrooms').get()).docs;
    expect(classroomDocs, isEmpty,
        reason: 'signals live under users/{uid}, never under classrooms/');
  });

  // ── 4. Firestore rules file content check ────────────────────────────────
  //
  // CI cannot run the Firebase emulator, so we only verify that the rules file
  // still contains the expected uid guard and that no classrooms match was
  // added. This is a canary: if someone edits the rules the test will fail.

  test('firestore.rules enforces uid guard and has no classrooms match', () {
    final rules = File('../firebase/firestore.rules').readAsStringSync();

    expect(
      rules,
      contains('request.auth.uid == uid'),
      reason: 'rules must guard each user subtree with their own uid',
    );
    expect(
      rules,
      isNot(contains('match /classrooms')),
      reason: 'signals must NOT be under classrooms/ – see DESIGN §38.5',
    );
    // NOTE: This test only checks the text of the file; it does NOT evaluate
    // the rules against a real Firebase project. Full rule evaluation would
    // require the Firebase emulator, which CI does not run.
  });
}
