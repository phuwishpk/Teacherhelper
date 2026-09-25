import 'package:flutter/foundation.dart';

/// Base URL of the backend, injected at build time:
///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
///
/// Debug and profile builds default to the Android emulator's host alias
/// (10.0.2.2 = host 127.0.0.1). A release build has NO default: cleartext is
/// blocked there anyway, so a missing define must fail loudly at startup
/// (see [apiBaseUrlConfigured] and main.dart) instead of silently pointing a
/// production APK at the emulator alias.
const String _definedApiBaseUrl = String.fromEnvironment('API_BASE_URL');

const String apiBaseUrl = _definedApiBaseUrl != ''
    ? _definedApiBaseUrl
    : (kReleaseMode ? '' : 'http://10.0.2.2:8000');

/// False when a release build was made without `--dart-define=API_BASE_URL`.
const bool apiBaseUrlConfigured = apiBaseUrl != '';

const String apiPrefix = '/api/v1';
