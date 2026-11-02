import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/auth_repository.dart';

enum ServerState { ok, degraded, unreachable }

class ServerHealth {
  const ServerHealth({required this.state, this.queueLastRunAt});

  final ServerState state;
  final DateTime? queueLastRunAt; // UTC

  factory ServerHealth.fromJson(Map<String, dynamic> json) {
    final db = json['db']?.toString();
    final status = json['status']?.toString();

    final ServerState state;
    if (db == 'error') {
      state = ServerState.unreachable;
    } else if (status == 'ok') {
      state = ServerState.ok;
    } else if (status == 'degraded') {
      state = ServerState.degraded;
    } else {
      state = ServerState.unreachable;
    }

    final rawDate = json['queue_last_run_at'];
    final DateTime? queueLastRunAt = rawDate is String
        ? DateTime.tryParse(rawDate)?.toUtc()
        : null;

    return ServerHealth(state: state, queueLastRunAt: queueLastRunAt);
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ServerHealth &&
          runtimeType == other.runtimeType &&
          state == other.state &&
          queueLastRunAt == other.queueLastRunAt;

  @override
  int get hashCode => Object.hash(state, queueLastRunAt);

  @override
  String toString() =>
      'ServerHealth(state: $state, queueLastRunAt: $queueLastRunAt)';
}

abstract class HealthRepository {
  Future<ServerHealth> fetch();
}

class ApiHealthRepository implements HealthRepository {
  ApiHealthRepository(this._dio);

  final Dio _dio;

  @override
  Future<ServerHealth> fetch() async {
    try {
      final res = await _dio.get<Object?>(
        '/health',
        options: Options(validateStatus: (s) => s != null && s < 600),
      );
      final data = res.data;
      if (data is Map) {
        return ServerHealth.fromJson(data.cast<String, dynamic>());
      }
      return const ServerHealth(state: ServerState.unreachable);
    } catch (_) {
      return const ServerHealth(state: ServerState.unreachable);
    }
  }
}

final healthRepositoryProvider = Provider<HealthRepository>(
  (ref) => ApiHealthRepository(ref.watch(dioProvider)),
);

final serverHealthProvider = FutureProvider.autoDispose<ServerHealth>(
  (ref) => ref.watch(healthRepositoryProvider).fetch(),
);

