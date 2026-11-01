import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/live/app_settings.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_test/flutter_test.dart';

class _ThrowingFirestore extends FakeFirebaseFirestore {
  @override
  CollectionReference<Map<String, dynamic>> collection(String collectionPath) {
    throw FirebaseException(
      plugin: 'cloud_firestore',
      code: 'unavailable',
      message: 'Firestore is temporarily unavailable',
    );
  }
}

void main() {
  group('AppSettingsStore', () {
    late InMemoryTokenStorage storage;
    late FakeFirebaseFirestore firestore;

    setUp(() {
      storage = InMemoryTokenStorage();
      firestore = FakeFirebaseFirestore();
    });

    test('1. returns true when neither storage nor firestore has a value', () async {
      final store = AppSettingsStore(
        storage: storage,
        userId: 1,
        db: firestore,
        uid: 'uid_1',
      );

      final result = await store.readMascotEnabled();
      expect(result, isTrue);
    });

    test('2. writes to both local storage and Firestore when disabled', () async {
      final store = AppSettingsStore(
        storage: storage,
        userId: 1,
        db: firestore,
        uid: 'uid_1',
      );

      await store.writeMascotEnabled(false);

      expect(await storage.readMascotEnabled(1), isFalse);
      final doc = await firestore
          .collection('users')
          .doc('uid_1')
          .collection('settings')
          .doc('app')
          .get();
      expect(doc.exists, isTrue);
      expect(doc.data()?['mascot_enabled'], isFalse);
      expect(await store.readMascotEnabled(), isFalse);
    });

    test('3. new device without local value reads from Firestore and writes back to local', () async {
      await firestore
          .collection('users')
          .doc('uid_1')
          .collection('settings')
          .doc('app')
          .set({'mascot_enabled': false});

      final newDeviceStorage = InMemoryTokenStorage();
      expect(await newDeviceStorage.readMascotEnabled(1), isNull);

      final store = AppSettingsStore(
        storage: newDeviceStorage,
        userId: 1,
        db: firestore,
        uid: 'uid_1',
      );

      final result = await store.readMascotEnabled();
      expect(result, isFalse);
      expect(await newDeviceStorage.readMascotEnabled(1), isFalse);
    });

    test('4. operates locally when db is null', () async {
      final store = AppSettingsStore(
        storage: storage,
        userId: 1,
        db: null,
        uid: null,
      );

      expect(await store.readMascotEnabled(), isTrue);

      await store.writeMascotEnabled(false);
      expect(await storage.readMascotEnabled(1), isFalse);
      expect(await store.readMascotEnabled(), isFalse);
    });

    test('5. does not rethrow on Firestore exception and preserves previous/default value', () async {
      final throwingDb = _ThrowingFirestore();
      final store = AppSettingsStore(
        storage: storage,
        userId: 1,
        db: throwingDb,
        uid: 'uid_1',
      );

      expect(await store.readMascotEnabled(), isTrue);

      await store.writeMascotEnabled(false);
      expect(await storage.readMascotEnabled(1), isFalse);
      expect(await store.readMascotEnabled(), isFalse);
    });

    test('6. settings for one user do not affect another user', () async {
      final store1 = AppSettingsStore(
        storage: storage,
        userId: 1,
        db: firestore,
        uid: 'uid_1',
      );
      final store2 = AppSettingsStore(
        storage: storage,
        userId: 2,
        db: firestore,
        uid: 'uid_2',
      );

      await store1.writeMascotEnabled(false);

      expect(await store1.readMascotEnabled(), isFalse);
      expect(await store2.readMascotEnabled(), isTrue);

      final doc1 = await firestore
          .collection('users')
          .doc('uid_1')
          .collection('settings')
          .doc('app')
          .get();
      final doc2 = await firestore
          .collection('users')
          .doc('uid_2')
          .collection('settings')
          .doc('app')
          .get();

      expect(doc1.data()?['mascot_enabled'], isFalse);
      expect(doc2.exists, isFalse);
    });
  });
}

