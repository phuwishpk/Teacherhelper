import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../../core/util/thai_date.dart';
import 'classroom.dart';

/// A row of the school's classroom directory (`GET /classrooms/directory`,
/// DESIGN §24.7 step 1, §24.20): an open room to ask for, without its
/// roster.
class DirectoryClassroom {
  const DirectoryClassroom({
    required this.id,
    required this.name,
    required this.gradeLevel,
    required this.academicYear,
    this.homeroomTeacher,
    this.studentCount = 0,
    this.myRole,
  });

  final int id;
  final String name;
  final int gradeLevel;
  final int academicYear;
  final TeacherRef? homeroomTeacher;
  final int studentCount;

  /// The signed-in teacher's role in the room, null when they have none.
  final ClassroomRole? myRole;

  /// A course bound to one's own room is bound at once, no request.
  bool get isMine => myRole == ClassroomRole.homeroom;

  factory DirectoryClassroom.fromJson(Map<String, dynamic> json) =>
      DirectoryClassroom(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        gradeLevel: (json['grade_level'] as num?)?.toInt() ?? 1,
        academicYear: (json['academic_year'] as num?)?.toInt() ?? 0,
        homeroomTeacher: TeacherRef.maybe(json['homeroom_teacher']),
        studentCount: (json['students_count'] as num?)?.toInt() ?? 0,
        myRole: switch (json['my_role']) {
          'homeroom' => ClassroomRole.homeroom,
          'subject' => ClassroomRole.subject,
          _ => null,
        },
      );

  /// The room the teacher is already in, as the directory shows it.
  factory DirectoryClassroom.of(Classroom c) => DirectoryClassroom(
    id: c.id,
    name: c.name,
    gradeLevel: c.gradeLevel,
    academicYear: c.academicYear,
    homeroomTeacher: c.homeroomTeacher,
    studentCount: c.studentCount ?? 0,
    myRole: c.myRole,
  );
}

/// `classroom_course_requests.status` (DESIGN §24.3 B).
enum CourseRequestStatus {
  pending('pending', 'รออนุมัติ'),
  approved('approved', 'อนุมัติแล้ว'),
  declined('declined', 'ไม่อนุมัติ'),
  cancelled('cancelled', 'ยกเลิกแล้ว');

  const CourseRequestStatus(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static CourseRequestStatus fromApi(Object? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => CourseRequestStatus.pending,
  );
}

/// Which side of the requests: to the teacher's homerooms, or their own.
enum CourseRequestBox {
  incoming('incoming'),
  outgoing('outgoing');

  const CourseRequestBox(this.apiValue);

  final String apiValue;
}

/// The classroom of a request (DESIGN §24.20).
class RequestClassroom {
  const RequestClassroom({
    required this.id,
    required this.name,
    required this.gradeLevel,
    required this.academicYear,
    this.closed = false,
    this.homeroomTeacher,
  });

  final int id;
  final String name;
  final int gradeLevel;
  final int academicYear;
  final bool closed;
  final TeacherRef? homeroomTeacher;

  factory RequestClassroom.fromJson(Map<String, dynamic> json) =>
      RequestClassroom(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        gradeLevel: (json['grade_level'] as num?)?.toInt() ?? 1,
        academicYear: (json['academic_year'] as num?)?.toInt() ?? 0,
        closed: json['closed'] == true,
        homeroomTeacher: TeacherRef.maybe(json['homeroom_teacher']),
      );
}

/// The course of a request (DESIGN §24.20).
class RequestCourse {
  const RequestCourse({
    required this.id,
    required this.code,
    required this.name,
    required this.gradeLevel,
  });

  final int id;
  final String code;
  final String name;
  final int gradeLevel;

  String get title => '$code $name';

  factory RequestCourse.fromJson(Map<String, dynamic> json) => RequestCourse(
    id: (json['id'] as num).toInt(),
    code: json['code'] as String? ?? '',
    name: json['name'] as String? ?? '',
    gradeLevel: (json['grade_level'] as num?)?.toInt() ?? 1,
  );
}

/// A request to bind a course to another teacher's classroom (DESIGN
/// §24.7, §24.12 B `GET /course-requests`).
class CourseRequest {
  const CourseRequest({
    required this.id,
    required this.status,
    this.classroom,
    this.course,
    this.requester,
    this.origin = 'teacher',
    this.message,
    this.googleCourseName,
    this.decidedAt,
    this.declineReason,
    this.createdAt,
  });

  final int id;
  final CourseRequestStatus status;
  final RequestClassroom? classroom;
  final RequestCourse? course;
  final TeacherRef? requester;

  /// `teacher`, `classroom_import` (§24.10) or `admin`.
  final String origin;
  final String? message;
  final String? googleCourseName;
  final DateTime? decidedAt;
  final String? declineReason;
  final DateTime? createdAt;

  bool get isPending => status == CourseRequestStatus.pending;

  /// The course's grade differs from the room's: only a warning (§24.7).
  bool get gradeMismatch =>
      classroom != null &&
      course != null &&
      classroom!.gradeLevel != course!.gradeLevel;

  factory CourseRequest.fromJson(Map<String, dynamic> json) {
    DateTime? time(String key) => switch (json[key]) {
      String s => DateTime.tryParse(s),
      _ => null,
    };
    Map<String, dynamic>? map(String key) =>
        json[key] is Map ? (json[key] as Map).cast<String, dynamic>() : null;
    final classroom = map('classroom');
    final course = map('course');
    return CourseRequest(
      id: (json['id'] as num).toInt(),
      status: CourseRequestStatus.fromApi(json['status']),
      classroom: classroom == null
          ? null
          : RequestClassroom.fromJson(classroom),
      course: course == null ? null : RequestCourse.fromJson(course),
      requester: TeacherRef.maybe(json['requester']),
      origin: json['origin'] as String? ?? 'teacher',
      message: _text(json['message']),
      googleCourseName: _text(json['google_course_name']),
      decidedAt: time('decided_at'),
      declineReason: _text(json['decline_reason']),
      createdAt: time('created_at'),
    );
  }
}

String? _text(Object? v) => v is String && v.trim().isNotEmpty ? v : null;

/// The answer of `POST /classrooms/{id}/course-requests`: a request that
/// waits for the homeroom teacher, or ([request] null) a course bound at
/// once because the room is the teacher's own.
class CourseRequestResult {
  const CourseRequestResult.sent(CourseRequest this.request);
  const CourseRequestResult.bound() : request = null;

  final CourseRequest? request;

  bool get bound => request == null;
}

/// "ระดับชั้นของรายวิชา (ป.4) ไม่ตรงกับห้อง (ป.5)", or null when they match.
String? gradeMismatchText({required int courseGrade, required int roomGrade}) =>
    courseGrade == roomGrade
    ? null
    : 'ระดับชั้นของรายวิชา (${gradeLevelLabel(courseGrade)}) '
          'ไม่ตรงกับห้อง (${gradeLevelLabel(roomGrade)}) ส่งคำขอได้ แต่ตรวจให้แน่ใจก่อน';

/// A Thai message for the codes of the request endpoints (DESIGN §24.12 B,
/// §24.20); anything else falls back to the server's message.
String courseRequestErrorText(Object error) => switch (apiErrorCode(error)) {
  'course_already_in_classroom' => 'รายวิชานี้ผูกกับห้องนี้อยู่แล้ว',
  'request_pending' => 'มีคำขอของรายวิชานี้กับห้องนี้รออนุมัติอยู่แล้ว',
  'classroom_closed' => 'ห้องนี้ปิดแล้ว (ห้องเก่า) ผูกรายวิชาเพิ่มไม่ได้',
  'request_closed' => 'คำขอนี้ถูกตัดสินหรือยกเลิกไปแล้ว',
  'request_busy' => 'มีการส่งคำขอเดียวกันอยู่ ลองใหม่อีกครั้ง',
  'course_in_use' =>
    'เลิกผูกไม่ได้ เพราะมีการบ้านของรายวิชานี้ในห้องแล้ว ห้องที่จบปีให้ใช้ "ปิดห้อง" แทน',
  _ => apiErrorMessage(error),
};

/// Shared homerooms (DESIGN §24.7, §24.12 B): the school's directory of
/// open rooms, the requests to bind a course to one, and deciding them.
abstract class CourseRequestsRepository {
  /// `GET /classrooms/directory?q=&academic_year=`.
  Future<List<DirectoryClassroom>> directory({String? q, int? academicYear});

  /// `POST /classrooms/{id}/course-requests {course_id, message?}`.
  Future<CourseRequestResult> request(
    int classroomId, {
    required int courseId,
    String? message,
  });

  /// `GET /course-requests?box=`, newest first.
  Future<List<CourseRequest>> list(CourseRequestBox box);

  /// `POST /course-requests/{id}/approve` (the homeroom teacher).
  Future<CourseRequest> approve(int id);

  /// `POST /course-requests/{id}/decline {reason?}` (the homeroom teacher).
  Future<CourseRequest> decline(int id, {String? reason});

  /// `DELETE /course-requests/{id}` (the requester, while pending).
  Future<void> cancel(int id);
}

class ApiCourseRequestsRepository implements CourseRequestsRepository {
  ApiCourseRequestsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<DirectoryClassroom>> directory({
    String? q,
    int? academicYear,
  }) async {
    final query = q?.trim() ?? '';
    final res = await _dio.get<Object?>(
      '/classrooms/directory',
      queryParameters: {
        if (query.isNotEmpty) 'q': query,
        'academic_year': ?academicYear,
      },
    );
    return unwrapList(res.data).map(DirectoryClassroom.fromJson).toList();
  }

  @override
  Future<CourseRequestResult> request(
    int classroomId, {
    required int courseId,
    String? message,
  }) async {
    final text = message?.trim() ?? '';
    final res = await _dio.post<Object?>(
      '/classrooms/$classroomId/course-requests',
      data: {'course_id': courseId, if (text.isNotEmpty) 'message': text},
    );
    final body = unwrapJson(res.data);
    if (body['bound'] == true) return const CourseRequestResult.bound();
    return CourseRequestResult.sent(CourseRequest.fromJson(body));
  }

  @override
  Future<List<CourseRequest>> list(CourseRequestBox box) async {
    final res = await _dio.get<Object?>(
      '/course-requests',
      queryParameters: {'box': box.apiValue},
    );
    return unwrapList(res.data).map(CourseRequest.fromJson).toList();
  }

  @override
  Future<CourseRequest> approve(int id) async {
    final res = await _dio.post<Object?>('/course-requests/$id/approve');
    return CourseRequest.fromJson(unwrapJson(res.data));
  }

  @override
  Future<CourseRequest> decline(int id, {String? reason}) async {
    final text = reason?.trim() ?? '';
    final res = await _dio.post<Object?>(
      '/course-requests/$id/decline',
      data: {if (text.isNotEmpty) 'reason': text},
    );
    return CourseRequest.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> cancel(int id) async {
    await _dio.delete<Object?>('/course-requests/$id');
  }
}

final courseRequestsRepositoryProvider = Provider<CourseRequestsRepository>(
  (ref) => ApiCourseRequestsRepository(ref.watch(dioProvider)),
);

/// The directory filtered by a search text (empty = every open room).
final classroomDirectoryProvider = FutureProvider.autoDispose
    .family<List<DirectoryClassroom>, String>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(courseRequestsRepositoryProvider).directory(q: q);
    });

/// One box of "คำขอผูกรายวิชา".
final courseRequestsProvider = FutureProvider.autoDispose
    .family<List<CourseRequest>, CourseRequestBox>((ref, box) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(courseRequestsRepositoryProvider).list(box);
    });
