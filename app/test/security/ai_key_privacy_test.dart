import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

const _key = 'AIzaSyFAKE0000000000000000000000WXYZ';

/// DESIGN §10.1: the teacher's Gemini key is sent once to the server and
/// kept nowhere on the device.
void main() {
  late Map<String, String> vault;
  late List<String> logs;
  late FakeHttpAdapter adapter;
  late FakeHandler onPut;

  setUp(() {
    vault = {};
    FlutterSecureStorage.setMockInitialValues(vault);
    logs = [];
    final original = debugPrint;
    debugPrint = (String? message, {int? wrapWidth}) => logs.add(message ?? '');
    addTearDown(() => debugPrint = original);
    onPut = (_) async => jsonResponse(200, {
      'configured': true,
      'key_last4': 'WXYZ',
      'last_verified_at': '2026-09-26T08:00:00Z',
      'server_key_available': false,
    });
    adapter = FakeHttpAdapter((options) {
      if (options.method == 'PUT') return onPut(options);
      return Future.value(
        jsonResponse(200, {
          'configured': false,
          'key_last4': null,
          'last_verified_at': null,
          'server_key_available': false,
        }),
      );
    });
  });

  Future<ProviderContainer> signedInContainer() async {
    final storage = SecureTokenStorage();
    await storage.write('sanctum-tok');
    final container = ProviderContainer(
      overrides: [
        tokenStorageProvider.overrideWithValue(storage),
        dioProvider.overrideWith(
          (ref) => createDio(
            tokenStorage: storage,
            onUnauthorized: () {},
            baseUrl: 'https://api.school.test',
          )..httpClientAdapter = adapter,
        ),
      ],
    );
    addTearDown(container.dispose);
    final sub = container.listen(aiKeyProvider, (_, _) {});
    addTearDown(sub.close);
    await container.read(aiKeyProvider.future);
    return container;
  }

  test('the key is sent once over HTTPS and stays off the device', () async {
    final container = await signedInContainer();

    await container.read(aiKeyProvider.notifier).save(_key);

    final puts = adapter.requests.where((r) => r.method == 'PUT').toList();
    expect(puts, hasLength(1));
    expect(
      puts.single.uri.toString(),
      'https://api.school.test/api/v1/me/ai-key',
    );
    expect(puts.single.data, {'gemini_api_key': _key});
    expect(puts.single.headers['Authorization'], 'Bearer sanctum-tok');

    // Nothing but the session token is in secure storage, and no value
    // contains the key or even its tail.
    expect(vault.keys, ['auth_token']);
    expect(vault.values.any((v) => v.contains('AIza')), isFalse);

    final status = container.read(aiKeyProvider).value!;
    expect(status.configured, isTrue);
    expect(status.keyLast4, 'WXYZ');
    expect(status.masked, '••••WXYZ');
    expect(logs.any((l) => l.contains(_key)), isFalse);
  });

  test('an invalid key: the reason comes from the server and the key is '
      'neither echoed nor kept', () async {
    onPut = (options) async => jsonResponse(422, {
      'message': 'Gemini ไม่ยอมรับ key นี้',
      'errors': {
        'gemini_api_key': ['key นี้ใช้ไม่ได้ ตรวจว่าคัดลอกมาครบ'],
      },
      'code': 'ai_key_invalid',
    });
    final container = await signedInContainer();

    Object? failure;
    try {
      await container.read(aiKeyProvider.notifier).save(_key);
    } catch (e) {
      failure = e;
    }

    expect(failure, isA<DioException>());
    final message = aiKeyErrorMessage(failure!);
    expect(message, 'key นี้ใช้ไม่ได้ ตรวจว่าคัดลอกมาครบ');
    expect(message, isNot(contains('AIza')));
    expect(container.read(aiKeyProvider).value?.configured, isFalse);
    expect(vault.keys, ['auth_token']);
    expect(logs.any((l) => l.contains(_key)), isFalse);
  });
}
