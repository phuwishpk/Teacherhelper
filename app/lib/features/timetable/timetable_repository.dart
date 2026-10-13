import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import 'timetable_models.dart';

/// The timetable endpoints of DESIGN §29.12.
abstract class TimetableRepository {
  /// `GET /timetable?week=`.
  Future<TimetableWeek> week({String? week});

  /// `POST /courses/{id}/timetable-slots`.
  Future<TimetableSlot> addSlot(
    int courseId, {
    required int classroomId,
    required int weekday,
    int? periodNo,
    String? startsAt,
    String? endsAt,
  });

  /// `PUT /timetable-slots/{id}`.
  Future<TimetableSlot> updateSlot(
    int id, {
    int? weekday,
    int? periodNo,
    bool clearPeriod = false,
    String? startsAt,
    String? endsAt,
    bool clearTimes = false,
  });

  /// `DELETE /timetable-slots/{id}`.
  Future<void> deleteSlot(int id);

  /// `POST /timetable-slots/{id}/cancellations`.
  Future<TimetableCancellation> cancel(
    int slotId,
    String heldOn, {
    String? note,
  });

  /// `DELETE /timetable-cancellations/{id}`.
  Future<void> uncancel(int cancellationId);

  /// `PATCH /courses/{id}` with the term dates only.
  Future<void> setTerm(int courseId, {String? startsOn, String? endsOn});

  /// `GET /student/timetable?week=`.
  Future<TimetableWeek> studentWeek({String? week});
}

class ApiTimetableRepository implements TimetableRepository {
  ApiTimetableRepository(this._dio);

  final Dio _dio;

  @override
  Future<TimetableWeek> week({String? week}) async {
    final res = await _dio.get<Object?>(
      '/timetable',
      queryParameters: {'week': ?week},
    );
    return TimetableWeek.fromJson(unwrapJson(res.data));
  }

  @override
  Future<TimetableSlot> addSlot(
    int courseId, {
    required int classroomId,
    required int weekday,
    int? periodNo,
    String? startsAt,
    String? endsAt,
  }) async {
    final res = await _dio.post<Object?>(
      '/courses/$courseId/timetable-slots',
      data: {
        'classroom_id': classroomId,
        'weekday': weekday,
        'period_no': periodNo,
        'starts_at': startsAt,
        'ends_at': endsAt,
      },
    );
    return TimetableSlot.fromJson(unwrapJson(res.data));
  }

  @override
  Future<TimetableSlot> updateSlot(
    int id, {
    int? weekday,
    int? periodNo,
    bool clearPeriod = false,
    String? startsAt,
    String? endsAt,
    bool clearTimes = false,
  }) async {
    final res = await _dio.put<Object?>(
      '/timetable-slots/$id',
      data: {
        'weekday': ?weekday,
        'period_no': ?periodNo,
        if (clearPeriod) 'period_no': null,
        'starts_at': ?startsAt,
        'ends_at': ?endsAt,
        if (clearTimes) ...{'starts_at': null, 'ends_at': null},
      },
    );
    return TimetableSlot.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteSlot(int id) =>
      _dio.delete<Object?>('/timetable-slots/$id');

  @override
  Future<TimetableCancellation> cancel(
    int slotId,
    String heldOn, {
    String? note,
  }) async {
    final res = await _dio.post<Object?>(
      '/timetable-slots/$slotId/cancellations',
      data: {'held_on': heldOn, 'note': ?note},
    );
    return TimetableCancellation.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> uncancel(int cancellationId) =>
      _dio.delete<Object?>('/timetable-cancellations/$cancellationId');

  @override
  Future<void> setTerm(int courseId, {String? startsOn, String? endsOn}) =>
      _dio.patch<Object?>(
        '/courses/$courseId',
        data: {'term_starts_on': startsOn, 'term_ends_on': endsOn},
      );

  @override
  Future<TimetableWeek> studentWeek({String? week}) async {
    final res = await _dio.get<Object?>(
      '/student/timetable',
      queryParameters: {'week': ?week},
    );
    return TimetableWeek.fromJson(unwrapJson(res.data));
  }
}

final timetableRepositoryProvider = Provider<TimetableRepository>(
  (ref) => ApiTimetableRepository(ref.watch(dioProvider)),
);

/// Teacher: one week ([week] = any date of it, ISO; null = this week).
final timetableWeekProvider = FutureProvider.autoDispose
    .family<TimetableWeek, String?>((ref, week) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(timetableRepositoryProvider).week(week: week);
    }, retry: apiRetry);

/// Student: one week of their classrooms.
final studentTimetableProvider = FutureProvider.autoDispose
    .family<TimetableWeek, String?>((ref, week) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(timetableRepositoryProvider).studentWeek(week: week);
    }, retry: apiRetry);
