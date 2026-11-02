import 'package:dio/dio.dart';
import 'package:eduvision/features/about/server_health.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

void main() {
  test('200 ok parses state and queue timestamp', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'status': 'ok',
        'db': 'ok',
        'queue_last_run_at': '2026-11-02T10:00:00Z',
      }),
    );
    final repo = ApiHealthRepository(fakeDio(adapter));
    final health = await repo.fetch();

    expect(adapter.requests.single.uri.path, '/api/v1/health');
    expect(health.state, ServerState.ok);
    expect(health.queueLastRunAt, DateTime.utc(2026, 11, 2, 10));
  });

  test('200 degraded with null queue timestamp', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'status': 'degraded',
        'db': 'ok',
        'queue_last_run_at': null,
      }),
    );
    final repo = ApiHealthRepository(fakeDio(adapter));
    final health = await repo.fetch();

    expect(health.state, ServerState.degraded);
    expect(health.queueLastRunAt, isNull);
  });

  test('503 db error returns unreachable', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(503, {
        'status': 'degraded',
        'db': 'error',
        'queue_last_run_at': null,
      }),
    );
    final repo = ApiHealthRepository(fakeDio(adapter));
    final health = await repo.fetch();

    expect(health.state, ServerState.unreachable);
  });

  test('connection failure returns unreachable', () async {
    final adapter = FakeHttpAdapter(
      (options) async => throw DioException(
        requestOptions: options,
        error: 'Network connection failed',
      ),
    );
    final repo = ApiHealthRepository(fakeDio(adapter));
    final health = await repo.fetch();

    expect(health.state, ServerState.unreachable);
    expect(health.queueLastRunAt, isNull);
  });
}

