import 'dart:convert';

import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

/// The session lives in the platform keystore (flutter_secure_storage),
/// never in SharedPreferences, a file or the drift database.
void main() {
  late Map<String, String> vault;

  setUp(() {
    vault = {};
    // The plugin's own in-memory platform; [vault] is the backing map.
    FlutterSecureStorage.setMockInitialValues(vault);
  });

  test('the app wires SecureTokenStorage unless a test overrides it', () {
    final container = ProviderContainer();
    addTearDown(container.dispose);
    expect(container.read(tokenStorageProvider), isA<SecureTokenStorage>());
  });

  test(
    'token and cached user are written to secure storage and read back',
    () async {
      final storage = SecureTokenStorage();
      await storage.write('tok-1');
      await storage.writeUser({'id': 1, 'name': 'ครู', 'role': 'teacher'});

      expect(vault.keys, unorderedEquals(['auth_token', 'auth_user']));
      expect(vault['auth_token'], 'tok-1');
      expect(jsonDecode(vault['auth_user']!), {
        'id': 1,
        'name': 'ครู',
        'role': 'teacher',
      });
      expect(await storage.read(), 'tok-1');
      expect((await storage.readUser())?['name'], 'ครู');
    },
  );

  test(
    'clear() forgets the token and the user but keeps the data owner',
    () async {
      final storage = SecureTokenStorage();
      await storage.write('tok-1');
      await storage.writeUser({'id': 1, 'name': 'ครู', 'role': 'teacher'});
      await storage.writeDataOwner(1);

      await storage.clear();

      expect(await storage.read(), isNull);
      expect(await storage.readUser(), isNull);
      expect(await storage.readDataOwner(), 1);
      expect(vault.keys, ['local_data_owner']);
    },
  );

  test('writeDataOwner(null) deletes the key', () async {
    final storage = SecureTokenStorage();
    await storage.writeDataOwner(4);
    expect(vault['local_data_owner'], '4');
    await storage.writeDataOwner(null);
    expect(vault.containsKey('local_data_owner'), isFalse);
    expect(await storage.readDataOwner(), isNull);
  });

  test('damaged payloads read as null instead of throwing', () async {
    final storage = SecureTokenStorage();
    vault['auth_user'] = '{not json';
    expect(await storage.readUser(), isNull);
    vault['auth_user'] = '[1, 2]';
    expect(await storage.readUser(), isNull);
    vault['local_data_owner'] = 'abc';
    expect(await storage.readDataOwner(), isNull);
  });

  test('the Google sign-up mark is a UTC time; null deletes it', () async {
    final storage = SecureTokenStorage();
    final at = DateTime.utc(2026, 10, 2, 3, 4, 5);
    await storage.writeGoogleSignUpStartedAt(at.toLocal());
    expect(vault['google_signup_started_at'], '2026-10-02T03:04:05.000Z');
    expect(await storage.readGoogleSignUpStartedAt(), at);

    await storage.clear();
    expect(await storage.readGoogleSignUpStartedAt(), at);

    await storage.writeGoogleSignUpStartedAt(null);
    expect(vault.containsKey('google_signup_started_at'), isFalse);
    expect(await storage.readGoogleSignUpStartedAt(), isNull);
    vault['google_signup_started_at'] = 'junk';
    expect(await storage.readGoogleSignUpStartedAt(), isNull);
  });
}
