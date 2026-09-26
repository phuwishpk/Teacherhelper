import 'package:dio/dio.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

const _token = 'sanctum-token-8f3a';

/// The Dio every repository uses (DESIGN §6.1 "interceptor แนบ token").
void main() {
  late FakeHttpAdapter adapter;
  late int unauthorizedCalls;

  Dio dio({String? token, int status = 200}) {
    adapter = FakeHttpAdapter(
      (_) async => jsonResponse(status, {
        'message': status == 200 ? 'ok' : 'Unauthenticated.',
        'errors': <String, Object>{},
        'code': status == 200 ? null : 'unauthenticated',
      }),
    );
    unauthorizedCalls = 0;
    return createDio(
      tokenStorage: InMemoryTokenStorage(token: token),
      onUnauthorized: () => unauthorizedCalls++,
      baseUrl: 'https://api.school.test',
    )..httpClientAdapter = adapter;
  }

  String? authOf(int i) =>
      adapter.requests[i].headers['Authorization'] as String?;

  test('the bearer token goes only to the API origin', () async {
    final d = dio(token: _token);
    await d.get<Object?>('/classrooms');
    await d.get<Object?>(
      'https://api.school.test/api/v1/worksheet-prints/1/file',
    );
    // Absolute links elsewhere (Drive downloads, a CDN) get no credentials.
    await d.get<Object?>('https://drive.google.com/uc?id=abc');
    // Same host but another scheme or port is another origin.
    await d.get<Object?>('http://api.school.test/api/v1/classrooms');
    await d.get<Object?>('https://api.school.test:8443/api/v1/classrooms');

    expect(authOf(0), 'Bearer $_token');
    expect(authOf(1), 'Bearer $_token');
    expect(authOf(2), isNull);
    expect(authOf(3), isNull);
    expect(authOf(4), isNull);
  });

  test('without a stored token no Authorization header is sent', () async {
    await dio().get<Object?>('/me');
    expect(
      adapter.requests.single.headers.containsKey('Authorization'),
      isFalse,
    );
  });

  test(
    'a 401 signs the session out once and still surfaces the error',
    () async {
      final d = dio(token: _token, status: 401);
      await expectLater(
        d.get<Object?>('/me'),
        throwsA(
          isA<DioException>().having(
            (e) => e.response?.statusCode,
            'status',
            401,
          ),
        ),
      );
      expect(unauthorizedCalls, 1);
    },
  );

  test('a 403 (wrong classroom) or 422 keeps the session', () async {
    await expectLater(
      dio(token: _token, status: 403).get<Object?>('/classrooms/9'),
      throwsA(isA<DioException>()),
    );
    expect(unauthorizedCalls, 0);
    await expectLater(
      dio(token: _token, status: 422).post<Object?>('/classrooms'),
      throwsA(isA<DioException>()),
    );
    expect(unauthorizedCalls, 0);
  });

  test('no LogInterceptor: headers and bodies never reach the console', () {
    expect(
      dio(token: _token).interceptors.whereType<LogInterceptor>(),
      isEmpty,
    );
  });

  test('every request asks for JSON, so Laravel never answers with an HTML '
      'page that could carry a stack trace', () async {
    await dio(token: _token).get<Object?>('/me');
    expect(adapter.requests.single.headers['Accept'], 'application/json');
    expect(
      adapter.requests.single.uri.toString(),
      'https://api.school.test/api/v1/me',
    );
  });

  test('resolveApiPath keeps absolute links and strips the API prefix', () {
    expect(resolveApiPath('https://cdn.test/f.pdf'), 'https://cdn.test/f.pdf');
    expect(
      resolveApiPath('/api/v1/worksheet-prints/1/file'),
      '/worksheet-prints/1/file',
    );
    expect(
      resolveApiPath('/worksheet-prints/1/file'),
      '/worksheet-prints/1/file',
    );
  });
}
