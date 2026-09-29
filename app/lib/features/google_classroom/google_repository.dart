import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../assignments/assignment.dart';
import '../classrooms/classroom.dart';
import 'google_models.dart';

/// The Google Classroom endpoints of our server (DESIGN §18.6). The server
/// talks to Google with the teacher's refresh token; nothing here reaches
/// Google directly.
abstract class GoogleClassroomRepository {
  Future<GoogleStatus> status();

  /// Sends the one-time server auth code; the server exchanges it for a
  /// refresh token. 422 `google_scope_missing` when a scope was not granted.
  Future<GoogleStatus> connect(String serverAuthCode);

  /// Google's consent page for the browser flow (`POST /google/oauth/url`):
  /// the teacher finishes it in a browser, Google returns to the server,
  /// and [status] shows the connection. For devices without the native
  /// sign-in (the web, a build without GOOGLE_SERVER_CLIENT_ID).
  Future<Uri> oauthUrl();
  Future<void> disconnect();

  Future<List<GoogleCourse>> courses();

  /// The room, grade, year and numbered students proposed for importing
  /// [courseId] (DESIGN §19.2). 409 `course_already_linked`.
  Future<ClassroomImportPreview> importPreview(String courseId);

  /// Creates the room, its students, the course link and every account
  /// match in one step. The answer carries the one-time PINs.
  Future<ClassroomImportResult> importClassroom(ClassroomImportRequest request);

  /// Appends new course accounts, marks students who left and matches
  /// returning ones back ("ซิงก์รายชื่อ"). 422 `classroom_not_linked`.
  Future<RosterSyncResult> syncRoster(int classroomId);
  Future<ClassroomGoogleLink> link(int classroomId, GoogleCourse course);
  Future<void> unlink(int classroomId);
  Future<List<GoogleRosterEntry>> roster(int classroomId);

  /// [matches]: Google user id -> student id, or null for "not matched".
  Future<void> saveRoster(int classroomId, Map<String, int?> matches);

  /// Creates the courseWork. 409 `already_posted` when it exists.
  Future<AssignmentGoogleLink> post(
    int assignmentId, {
    required bool attachBlankWorksheet,
    String? instructions,
    DateTime? dueAt,
  });

  /// Syncs from Classroom first, so it can take a few seconds.
  Future<List<GoogleSubmission>> submissions(int assignmentId);

  /// Returns the submission in Classroom so the student can send a new
  /// photo; the student is told [reason] in our app.
  Future<GoogleSubmission?> returnForRetake(int importId, String reason);

  /// Sends the grades of `grade_failed` rows again; returns how many were
  /// queued when the server says.
  Future<int?> retryGrades(int assignmentId);

  /// "ซิงก์ตอนนี้": queues one sync round of the classroom (new courseWork
  /// from the website, hand-ins, grades) instead of waiting for the cron
  /// (DESIGN §19.3). 409 `google_reconnect_required`.
  Future<void> syncNow(int classroomId);

  /// "คะแนนไม่ตรงกัน" of an assignment, open ones first.
  Future<List<GradeConflict>> gradeConflicts(int assignmentId);

  /// Settles one conflict. 409 `conflict_resolved` / `coursework_not_owned`.
  Future<GradeConflict> resolveConflict(
    int conflictId,
    GradeConflictAction action,
  );

  /// Takes a hand-in the late policy refused (`rejected_late` -> `new`);
  /// the next sync round downloads and grades it. 409
  /// `import_not_rejected`.
  Future<GoogleSubmission?> acceptLate(int importId);
}

class ApiGoogleClassroomRepository implements GoogleClassroomRepository {
  ApiGoogleClassroomRepository(this._dio);

  final Dio _dio;

  /// Listing courses, syncing submissions and posting call Google on the
  /// server, which may take longer than an ordinary request.
  static final _slow = Options(receiveTimeout: const Duration(seconds: 60));

  @override
  Future<GoogleStatus> status() async {
    final res = await _dio.get<Object?>('/google/status');
    return GoogleStatus.fromJson(unwrapJson(res.data));
  }

  @override
  Future<GoogleStatus> connect(String serverAuthCode) async {
    final res = await _dio.post<Object?>(
      '/google/connect',
      data: {'server_auth_code': serverAuthCode},
      options: _slow,
    );
    final body = unwrapJson(res.data);
    return GoogleStatus.fromJson({
      'connected': true,
      'needs_reconnect': false,
      ...body,
    });
  }

  @override
  Future<Uri> oauthUrl() async {
    final res = await _dio.post<Object?>('/google/oauth/url');
    final url = Uri.tryParse('${unwrapJson(res.data)['url'] ?? ''}');
    // Only ever open an https page (Google's consent screen).
    if (url == null || url.scheme != 'https' || url.host.isEmpty) {
      throw const FormatException('ไม่มีลิงก์หน้าเชื่อม Google');
    }
    return url;
  }

  @override
  Future<void> disconnect() async {
    await _dio.delete<Object?>('/google/disconnect', options: _slow);
  }

  @override
  Future<List<GoogleCourse>> courses() async {
    final res = await _dio.get<Object?>('/google/courses', options: _slow);
    return unwrapList(res.data).map(GoogleCourse.fromJson).toList();
  }

  @override
  Future<ClassroomImportPreview> importPreview(String courseId) async {
    final res = await _dio.get<Object?>(
      '/google/courses/${Uri.encodeComponent(courseId)}/import-preview',
      options: _slow,
    );
    return ClassroomImportPreview.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ClassroomImportResult> importClassroom(
    ClassroomImportRequest request,
  ) async {
    final res = await _dio.post<Object?>(
      '/classrooms/import-google',
      data: request.toJson(),
      options: _slow,
    );
    return ClassroomImportResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<RosterSyncResult> syncRoster(int classroomId) async {
    final res = await _dio.post<Object?>(
      '/classrooms/$classroomId/google-roster/sync',
      options: _slow,
    );
    return RosterSyncResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ClassroomGoogleLink> link(int classroomId, GoogleCourse course) async {
    final res = await _dio.post<Object?>(
      '/classrooms/$classroomId/google-link',
      data: {'course_id': course.courseId},
      options: _slow,
    );
    final body = res.data;
    if (body is Map && (body['course_id'] != null || body['data'] is Map)) {
      final json = unwrapJson(body);
      return ClassroomGoogleLink.fromJson({
        'course_name': course.name,
        ...json,
      });
    }
    // 204: the link is what was asked for.
    return ClassroomGoogleLink(
      courseId: course.courseId,
      courseName: course.name,
      linkedAt: DateTime.now().toUtc(),
    );
  }

  @override
  Future<void> unlink(int classroomId) async {
    await _dio.delete<Object?>('/classrooms/$classroomId/google-link');
  }

  @override
  Future<List<GoogleRosterEntry>> roster(int classroomId) async {
    final res = await _dio.get<Object?>(
      '/classrooms/$classroomId/google-roster',
      options: _slow,
    );
    return unwrapList(res.data).map(GoogleRosterEntry.fromJson).toList();
  }

  @override
  Future<void> saveRoster(int classroomId, Map<String, int?> matches) async {
    await _dio.put<Object?>(
      '/classrooms/$classroomId/google-roster',
      data: {
        'matches': [
          for (final e in matches.entries)
            {'google_user_id': e.key, 'student_id': e.value},
        ],
      },
    );
  }

  @override
  Future<AssignmentGoogleLink> post(
    int assignmentId, {
    required bool attachBlankWorksheet,
    String? instructions,
    DateTime? dueAt,
  }) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/google-post',
      data: {
        'attach_blank_worksheet': attachBlankWorksheet,
        if (instructions != null && instructions.trim().isNotEmpty)
          'instructions': instructions.trim(),
        if (dueAt != null) 'due_at': dueAt.toUtc().toIso8601String(),
      },
      options: _slow,
    );
    final json = unwrapJson(res.data);
    final link = AssignmentGoogleLink.fromJson(json);
    if (json.containsKey('drive_file_id') ||
        json.containsKey('has_blank_worksheet')) {
      return link;
    }
    // The answer of §18.6 is only {course_work_id, alternate_link}.
    return AssignmentGoogleLink(
      courseWorkId: link.courseWorkId,
      alternateLink: link.alternateLink,
      hasBlankWorksheet: attachBlankWorksheet,
      postedAt: link.postedAt ?? DateTime.now().toUtc(),
    );
  }

  @override
  Future<List<GoogleSubmission>> submissions(int assignmentId) async {
    final rows = await fetchAllPages(
      _dio,
      '/assignments/$assignmentId/google-submissions',
    );
    return rows.map(GoogleSubmission.fromJson).toList();
  }

  @override
  Future<GoogleSubmission?> returnForRetake(int importId, String reason) async {
    final res = await _dio.post<Object?>(
      '/google-submissions/$importId/return',
      data: {'reason': reason},
      options: _slow,
    );
    final body = res.data;
    if (body is Map && (body['id'] != null || body['data'] is Map)) {
      return GoogleSubmission.fromJson(unwrapJson(body));
    }
    return null;
  }

  @override
  Future<int?> retryGrades(int assignmentId) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/google-grades/retry',
    );
    final body = res.data;
    if (body is! Map) return null;
    final json = unwrapJson(body);
    return (json['queued'] ?? json['retried'] ?? json['count']) is num
        ? ((json['queued'] ?? json['retried'] ?? json['count']) as num).toInt()
        : null;
  }

  @override
  Future<void> syncNow(int classroomId) async {
    await _dio.post<Object?>('/classrooms/$classroomId/google-sync');
  }

  @override
  Future<List<GradeConflict>> gradeConflicts(int assignmentId) async {
    final res = await _dio.get<Object?>(
      '/assignments/$assignmentId/grade-conflicts',
    );
    return unwrapList(res.data).map(GradeConflict.fromJson).toList();
  }

  @override
  Future<GradeConflict> resolveConflict(
    int conflictId,
    GradeConflictAction action,
  ) async {
    final res = await _dio.post<Object?>(
      '/grade-conflicts/$conflictId/resolve',
      data: {'action': action.apiValue},
    );
    return GradeConflict.fromJson(unwrapJson(res.data));
  }

  @override
  Future<GoogleSubmission?> acceptLate(int importId) async {
    final res = await _dio.post<Object?>(
      '/google-submissions/$importId/accept-late',
    );
    final body = res.data;
    if (body is Map && (body['id'] != null || body['data'] is Map)) {
      return GoogleSubmission.fromJson(unwrapJson(body));
    }
    return null;
  }
}

final googleClassroomRepositoryProvider = Provider<GoogleClassroomRepository>(
  (ref) => ApiGoogleClassroomRepository(ref.watch(dioProvider)),
);

/// Error codes of §18.6 that mean the teacher must connect (again).
const _reconnectCodes = {
  'google_not_connected',
  'google_reconnect_required',
  'google_needs_reconnect',
  'invalid_grant',
};

/// True when [error] says the Google connection is missing or expired, so
/// the caller can refresh the status card.
bool isGoogleReconnectError(Object error) =>
    _reconnectCodes.contains(apiErrorCode(error));

/// Thai message for a failed Google Classroom request.
String googleErrorMessage(Object error) {
  final code = apiErrorCode(error);
  return switch (code) {
    'google_not_configured' =>
      'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom กรุณาแจ้งผู้ดูแลระบบ',
    'google_not_connected' =>
      'ยังไม่ได้เชื่อมบัญชี Google ไปที่ ตั้งค่า → Google Classroom ก่อน',
    'google_reconnect_required' ||
    'google_needs_reconnect' ||
    'invalid_grant' =>
      'สิทธิ์ที่ให้ Google ไว้หมดอายุแล้ว ไปที่ ตั้งค่า → Google Classroom แล้วกด "เชื่อมใหม่"',
    'google_scope_missing' =>
      'ต้องติ๊กอนุญาตทุกสิทธิ์ที่แอปขอ (Classroom และ Drive) กดเชื่อมอีกครั้งแล้วอนุญาตให้ครบ',
    'already_posted' => 'การบ้านนี้โพสต์ลง Google Classroom แล้ว',
    'course_already_linked' =>
      'คอร์สนี้ผูกกับห้องเรียนในแอปแล้ว เลือกคอร์สอื่น หรือเปิดห้องที่ผูกไว้',
    'classroom_not_linked' =>
      'ห้องเรียนนี้ยังไม่ได้ผูกกับ Google Classroom ผูกที่หน้าห้องเรียนก่อน',
    'coursework_not_owned' =>
      'งานนี้สร้างในเว็บ Classroom แอปส่งคะแนนกลับให้ไม่ได้ '
          'เปิดใน Classroom แล้วกรอกคะแนนเอง',
    'conflict_resolved' => 'รายการนี้ตัดสินไปแล้ว ดึงรายการใหม่อีกครั้ง',
    'import_not_rejected' => 'งานนี้ไม่ได้ถูกปฏิเสธเพราะส่งช้าแล้ว',
    'ProjectPermissionDenied' || 'project_permission_denied' =>
      'งานนี้สร้างในเว็บ Classroom เอง แอปส่งคะแนนกลับหรือส่งคืนงานให้ไม่ได้ '
          'ต้องสั่งงานผ่านปุ่ม "โพสต์ลง Classroom" ในแอป',
    _ => apiErrorMessage(error),
  };
}
