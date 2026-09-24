/// Base URL of the backend, injected at build time:
///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
/// Default targets the Android emulator's host alias (10.0.2.2 = host 127.0.0.1).
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8000',
);

const String apiPrefix = '/api/v1';
