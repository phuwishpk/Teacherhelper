import 'dart:io';

import 'package:eduvision/core/api/api_config.dart';
import 'package:flutter_test/flutter_test.dart';

String _read(String path) => File(path).readAsStringSync();

/// Build-level guarantees from CLAUDE.md and app/README.md: plain HTTP only
/// in debug builds, no backups of the keystore/database, and the private
/// Android files never in git. `flutter test` runs with `app/` as the
/// working directory.
void main() {
  test('the release manifest keeps Android\'s cleartext block and adds no '
      'network security config', () {
    final manifest = _read('android/app/src/main/AndroidManifest.xml');
    expect(manifest, isNot(contains('networkSecurityConfig')));
    expect(manifest, isNot(contains('usesCleartextTraffic="true"')));
    expect(
      File(
        'android/app/src/main/res/xml/network_security_config.xml',
      ).existsSync(),
      isFalse,
    );
    expect(
      File(
        'android/app/src/profile/res/xml/network_security_config.xml',
      ).existsSync(),
      isFalse,
    );
  });

  test('cleartext is permitted only by the debug source set', () {
    final debug = _read('android/app/src/debug/AndroidManifest.xml');
    expect(
      debug,
      contains('android:networkSecurityConfig="@xml/network_security_config"'),
    );
    final config = _read(
      'android/app/src/debug/res/xml/network_security_config.xml',
    );
    expect(config, contains('cleartextTrafficPermitted="true"'));
  });

  test('backups are off, so the token store and local database stay on the '
      'device', () {
    final manifest = _read('android/app/src/main/AndroidManifest.xml');
    expect(manifest, contains('android:allowBackup="false"'));
  });

  test('the debug default points at the emulator host under /api/v1', () {
    expect(apiBaseUrl, 'http://10.0.2.2:8000');
    expect(apiPrefix, '/api/v1');
    expect(apiBaseUrlConfigured, isTrue);
  });

  test('Firebase config, keystores and .env files are ignored by git', () {
    final ignore = _read('../.gitignore');
    for (final pattern in [
      '**/google-services.json',
      '**/key.properties',
      '**/*.jks',
      '**/*.keystore',
      '.env',
    ]) {
      expect(ignore.split('\n'), contains(pattern));
    }
    expect(File('android/app/google-services.json').existsSync(), isFalse);
    expect(File('android/key.properties').existsSync(), isFalse);
  });
}
