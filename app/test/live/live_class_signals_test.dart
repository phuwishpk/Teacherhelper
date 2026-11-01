import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_scope.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/features/student/live_refresh.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'live_test_support.dart';

const _student = User(id: 11, name: 'นักเรียน ก', role: 'student');

void main() {
  late FakeFirebaseFirestore db;

  setUp(() => db = FakeFirebaseFirestore());

  test('class feed and attendance change ticks start after first snapshot', () async {
    final container = ProviderContainer(
      overrides: [
        liveSessionProvider.overrideWithValue(
          FakeLiveSession(db: db, uid: 'u11'),
        ),
        currentUserProvider.overrideWithValue(_student),
      ],
    );
    addTearDown(container.dispose);

    // Initial documents exist before listening
    await db.doc('users/u11/class_feed/5').set({'classroom_id': 5});
    await db.doc('users/u11/attendance/1_5').set({'course_id': 1, 'classroom_id': 5});

    final feedTicks = <int>[];
    final attendanceTicks = <int>[];

    container.listen(
      liveClassFeedTicksProvider,
      (_, next) => next.whenData(feedTicks.add),
      fireImmediately: true,
    );
    container.listen(
      liveAttendanceTicksProvider,
      (_, next) => next.whenData(attendanceTicks.add),
      fireImmediately: true,
    );

    await pumpEventQueue();
    expect(feedTicks, isEmpty, reason: 'the existing class_feed documents are not a change');
    expect(attendanceTicks, isEmpty, reason: 'the existing attendance documents are not a change');
    expect(container.read(liveAvailableProvider), isTrue);

    // Write own class_feed document -> tick increments
    await db.doc('users/u11/class_feed/5').set({'classroom_id': 5, 'updated_at': 1});
    await pumpEventQueue();
    expect(feedTicks, [1]);

    // Write own attendance document -> tick increments
    await db.doc('users/u11/attendance/1_5').set({'course_id': 1, 'classroom_id': 5, 'updated_at': 1});
    await pumpEventQueue();
    expect(attendanceTicks, [1]);

    // Another user's document never ticks
    await db.doc('users/u99/class_feed/5').set({'classroom_id': 5});
    await db.doc('users/u99/attendance/1_5').set({'course_id': 1, 'classroom_id': 5});
    await pumpEventQueue();
    expect(feedTicks, [1]);
    expect(attendanceTicks, [1]);
  });

  test('no ticks while live layer is off and liveAvailableProvider is false', () async {
    final container = ProviderContainer(
      overrides: [currentUserProvider.overrideWithValue(_student)],
    );
    addTearDown(container.dispose);

    final feedTicks = <int>[];
    final attendanceTicks = <int>[];

    container.listen(
      liveClassFeedTicksProvider,
      (_, next) => next.whenData(feedTicks.add),
    );
    container.listen(
      liveAttendanceTicksProvider,
      (_, next) => next.whenData(attendanceTicks.add),
    );

    await pumpEventQueue();
    expect(feedTicks, isEmpty);
    expect(attendanceTicks, isEmpty);
    expect(container.read(liveAvailableProvider), isFalse);
  });
}
