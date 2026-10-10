import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import 'attendance_models.dart';

/// The attendance endpoints of DESIGN §29.5.
abstract class AttendanceRepository {
  /// `GET /courses/{id}/attendance?classroom_id=`.
  Future<AttendanceOverview> overview(int courseId, int classroomId);

  /// `GET /attendance-sessions/{id}`.
  Future<AttendanceSession> session(int sessionId);

  /// `POST /courses/{id}/attendance-sessions`: students left out of the
  /// draft are มาตรง.
  Future<AttendanceSession> create(
    int courseId,
    int classroomId,
    AttendanceDraft draft,
  );

  /// `PUT /attendance-sessions/{id}`.
  Future<AttendanceSession> update(int sessionId, AttendanceDraft draft);

  /// `DELETE /attendance-sessions/{id}`.
  Future<void> delete(int sessionId);

  /// `PUT /courses/{id}/attendance/scores`.
  Future<AttendanceScores> saveScores(int courseId, AttendanceScores scores);

  /// `POST /courses/{id}/gradebook-items` with `auto_attendance`: the
  /// "การเข้าเรียน" item of one classroom.
  Future<void> addAutoItem(
    int courseId,
    int classroomId, {
    required int categoryId,
    required double maxPoints,
  });

  /// `GET /student/attendance`.
  Future<List<MyAttendanceCourse>> mine();
}

class ApiAttendanceRepository implements AttendanceRepository {
  ApiAttendanceRepository(this._dio);

  final Dio _dio;

  @override
  Future<AttendanceOverview> overview(int courseId, int classroomId) async {
    final res = await _dio.get<Object?>(
      '/courses/$courseId/attendance',
      queryParameters: {'classroom_id': classroomId},
    );
    return AttendanceOverview.fromJson(unwrapJson(res.data));
  }

  @override
  Future<AttendanceSession> session(int sessionId) async {
    final res = await _dio.get<Object?>('/attendance-sessions/$sessionId');
    return AttendanceSession.fromJson(unwrapJson(res.data));
  }

  @override
  Future<AttendanceSession> create(
    int courseId,
    int classroomId,
    AttendanceDraft draft,
  ) async {
    final res = await _dio.post<Object?>(
      '/courses/$courseId/attendance-sessions',
      data: draft.toJson(classroomId: classroomId),
    );
    return AttendanceSession.fromJson(unwrapJson(res.data));
  }

  @override
  Future<AttendanceSession> update(int sessionId, AttendanceDraft draft) async {
    final res = await _dio.put<Object?>(
      '/attendance-sessions/$sessionId',
      data: draft.toJson(),
    );
    return AttendanceSession.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> delete(int sessionId) =>
      _dio.delete<Object?>('/attendance-sessions/$sessionId');

  @override
  Future<AttendanceScores> saveScores(
    int courseId,
    AttendanceScores scores,
  ) async {
    final res = await _dio.put<Object?>(
      '/courses/$courseId/attendance/scores',
      data: scores.toJson(),
    );
    return AttendanceScores.fromJson(unwrapJson(res.data)['scores']);
  }

  @override
  Future<void> addAutoItem(
    int courseId,
    int classroomId, {
    required int categoryId,
    required double maxPoints,
  }) => _dio.post<Object?>(
    '/courses/$courseId/gradebook-items',
    data: {
      'classroom_ids': [classroomId],
      'category_id': categoryId,
      'name': 'การเข้าเรียน',
      'max_points': maxPoints,
      'auto_attendance': true,
    },
  );

  @override
  Future<List<MyAttendanceCourse>> mine() async {
    final res = await _dio.get<Object?>('/student/attendance');
    final body = res.data;
    final rows = body is Map ? body['data'] as List? ?? const [] : const [];
    return [
      for (final r in rows)
        MyAttendanceCourse.fromJson(r as Map<String, dynamic>),
    ];
  }
}

final attendanceRepositoryProvider = Provider<AttendanceRepository>(
  (ref) => ApiAttendanceRepository(ref.watch(dioProvider)),
);

typedef AttendanceKey = ({int courseId, int classroomId});

/// The history and totals of one classroom in one course.
final attendanceOverviewProvider = FutureProvider.autoDispose
    .family<AttendanceOverview, AttendanceKey>((ref, key) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(attendanceRepositoryProvider)
          .overview(key.courseId, key.classroomId);
    }, retry: apiRetry);

final attendanceSessionProvider = FutureProvider.autoDispose
    .family<AttendanceSession, int>((ref, id) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(attendanceRepositoryProvider).session(id);
    }, retry: apiRetry);

/// Student: "การเข้าเรียนของฉัน".
final myAttendanceProvider =
    FutureProvider.autoDispose<List<MyAttendanceCourse>>((ref) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(attendanceRepositoryProvider).mine();
    }, retry: apiRetry);
