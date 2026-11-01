import 'dart:io';

import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/live/app_settings.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

class _CapturingAssistantRepository implements AssistantRepository {
  AssistantStatus statusValue = const AssistantStatus(available: true);
  final List<String> sentMessages = [];

  @override
  Future<AssistantStatus> status() async => statusValue;

  @override
  Future<List<AssistantConversation>> conversations() async => const [];

  @override
  Future<AssistantReply> conversation(int id) async => throw UnimplementedError();

  @override
  Future<AssistantStatus> acknowledgeStudentData() async => statusValue;

  @override
  Future<void> delete(int id) async {}

  @override
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  }) async {
    sentMessages.add(text);
    return AssistantReply.tryParse({
      'conversation': {
        'id': conversationId ?? 1,
        'title': 'สนทนา',
        'input_tokens': 10,
        'output_tokens': 10,
        'updated_at': '2026-10-20T03:00:00Z',
      },
      'messages': [
        {
          'id': 1,
          'role': 'user',
          'content': text,
        },
        {
          'id': 2,
          'role': 'assistant',
          'content': 'รับทราบข้อความแล้ว',
        },
      ],
    })!;
  }

  @override
  Future<AssistantReply> retry(int conversationId) async => throw UnimplementedError();

  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) async =>
      throw UnimplementedError();
}

void main() {
  group('Assistant Firestore Privacy (DESIGN §38.5, §43)', () {
    test('1. sending chat text never reaches Firestore', () async {
      const secret = 'ลับเฉพาะ123';
      final firestore = FakeFirebaseFirestore();
      final storage = InMemoryTokenStorage();
      final assistantRepo = _CapturingAssistantRepository();

      final container = ProviderContainer(
        overrides: [
          tokenStorageProvider.overrideWithValue(storage),
          assistantRepositoryProvider.overrideWithValue(assistantRepo),
          appSettingsStoreProvider.overrideWithValue(
            AppSettingsStore(
              storage: storage,
              userId: 1,
              db: firestore,
              uid: 'user_1',
            ),
          ),
        ],
      );
      addTearDown(container.dispose);

      // Perform chat interaction with the secret text
      await container.read(assistantChatProvider.notifier).send(secret);
      expect(assistantRepo.sentMessages, contains(secret));

      // Also perform an app settings write to ensure Firestore is actively used
      await container.read(mascotEnabledProvider.notifier).setEnabled(true);

      // Check all collections and documents under users/
      final dump = firestore.dump();
      expect(dump, isNot(contains(secret)));
    });

    test('2. settings/app document only contains allowed fields', () async {
      final firestore = FakeFirebaseFirestore();
      final storage = InMemoryTokenStorage();
      final store = AppSettingsStore(
        storage: storage,
        userId: 1,
        db: firestore,
        uid: 'user_1',
      );

      await store.writeMascotEnabled(false);

      final doc = await firestore
          .collection('users')
          .doc('user_1')
          .collection('settings')
          .doc('app')
          .get();

      expect(doc.exists, isTrue);
      final data = doc.data()!;
      const allowedKeys = ['mascot_enabled', 'onboarding_done_at', 'device'];
      for (final key in data.keys) {
        expect(
          allowedKeys.contains(key),
          isTrue,
          reason: 'Key "$key" is not permitted in settings/app',
        );
      }
    });

    test('3. firestore.rules enforces validAppSettings and uid ownership', () {
      final rules = File('../firebase/firestore.rules').readAsStringSync();

      expect(
        rules,
        contains("['mascot_enabled', 'onboarding_done_at', 'device']"),
      );
      expect(rules, contains('hasOnly'));
      expect(rules, contains('request.auth.uid == uid'));
      expect(rules, contains('validAppSettings()'));
    });
  });
}

