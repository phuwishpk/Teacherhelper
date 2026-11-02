import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/features/onboarding/onboarding_store.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('OnboardingStore', () {
    late InMemoryTokenStorage storage;
    late FakeFirebaseFirestore firestore;

    setUp(() {
      storage = InMemoryTokenStorage();
      firestore = FakeFirebaseFirestore();
    });

    test(
      '1. needed() is true when neither device nor Firestore has completed onboarding',
      () async {
        final store = OnboardingStore(
          userId: 1,
          storage: storage,
          db: firestore,
          uid: 'uid_1',
          device: 'web',
        );

        expect(await store.needed(), isTrue);
      },
    );

    test(
      '2. needed() is false when device already completed onboarding without reading Firestore',
      () async {
        await storage.writeOnboardingDone(1);
        final store = OnboardingStore(
          userId: 1,
          storage: storage,
          db: firestore,
          uid: 'uid_1',
          device: 'web',
        );

        expect(await store.needed(), isFalse);
        // Document shouldn't even be created/queried
        final snap = await firestore
            .collection('users')
            .doc('uid_1')
            .collection('settings')
            .doc('app')
            .get();
        expect(snap.exists, isFalse);
      },
    );

    test(
      '3. needed() is false when Firestore has completed onboarding, and syncs to device',
      () async {
        final doneAt = DateTime.utc(2026, 11, 1, 10, 0);
        await firestore
            .collection('users')
            .doc('uid_1')
            .collection('settings')
            .doc('app')
            .set({
              'onboarding_done_at': Timestamp.fromDate(doneAt),
              'device': 'android',
            });

        final store = OnboardingStore(
          userId: 1,
          storage: storage,
          db: firestore,
          uid: 'uid_1',
          device: 'web',
        );

        expect(await storage.readOnboardingDone(1), isFalse);
        expect(await store.needed(), isFalse);
        // Syncs to local storage
        expect(await storage.readOnboardingDone(1), isTrue);

        final info = await store.doneInfo();
        expect(info, isNotNull);
        expect(info?.at, doneAt);
        expect(info?.device, 'android');
      },
    );

    test(
      '4. markDone() writes to both device and Firestore with correct device',
      () async {
        final nowTime = DateTime.utc(2026, 11, 2, 8, 30);
        final store = OnboardingStore(
          userId: 1,
          storage: storage,
          db: firestore,
          uid: 'uid_1',
          device: 'web',
          now: () => nowTime,
        );

        await store.markDone();

        expect(await storage.readOnboardingDone(1), isTrue);

        final snap = await firestore
            .collection('users')
            .doc('uid_1')
            .collection('settings')
            .doc('app')
            .get();
        expect(snap.exists, isTrue);
        expect(snap.data()?['device'], 'web');
        expect(
          (snap.data()?['onboarding_done_at'] as Timestamp).toDate().toUtc(),
          nowTime,
        );
      },
    );

    test(
      '5. second markDone() does not overwrite the first completion time',
      () async {
        final firstTime = DateTime.utc(2026, 11, 1, 9, 0);
        final secondTime = DateTime.utc(2026, 11, 2, 9, 0);

        var currentTime = firstTime;
        final store = OnboardingStore(
          userId: 1,
          storage: storage,
          db: firestore,
          uid: 'uid_1',
          device: 'ios',
          now: () => currentTime,
        );

        await store.markDone();

        currentTime = secondTime;
        await store.markDone();

        final snap = await firestore
            .collection('users')
            .doc('uid_1')
            .collection('settings')
            .doc('app')
            .get();
        expect(
          (snap.data()?['onboarding_done_at'] as Timestamp).toDate().toUtc(),
          firstTime,
        );
      },
    );

    test('6. works with device storage alone when db is null', () async {
      final store = OnboardingStore(
        userId: 1,
        storage: storage,
        db: null,
        uid: null,
        device: 'web',
      );

      expect(await store.needed(), isTrue);
      await store.markDone();
      expect(await store.needed(), isFalse);
      expect(await store.doneInfo(), isNull);
    });

    test('7. onboarding of one user does not affect another user', () async {
      final storeUser1 = OnboardingStore(
        userId: 1,
        storage: storage,
        db: firestore,
        uid: 'uid_1',
        device: 'web',
      );
      final storeUser2 = OnboardingStore(
        userId: 2,
        storage: storage,
        db: firestore,
        uid: 'uid_2',
        device: 'web',
      );

      await storeUser1.markDone();

      expect(await storeUser1.needed(), isFalse);
      expect(await storeUser2.needed(), isTrue);
    });
  });
}
