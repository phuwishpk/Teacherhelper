import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../worksheets/print_job.dart';
import 'classroom.dart';
import 'school_students.dart';

/// Result of `POST /students/{id}/pin`: the new PIN is shown exactly once.
class PinReset {
  const PinReset(this.pin, {this.googleUnlinked = false});

  final String pin;

  /// The reset also removed the student's Google sign-in link (§24.9.5).
  final bool googleUnlinked;
}

/// Teacher-side classroom endpoints (DESIGN §9.2).
abstract class ClassroomsRepository {
  /// The open classrooms (`GET /classrooms`, DESIGN §24.6).
  Future<List<Classroom>> list();

  /// "ห้องเก่า": `GET /classrooms?state=closed` (DESIGN §24.6).
  Future<List<Classroom>> listClosed();
  Future<Classroom> get(int id);
  Future<Classroom> create({
    required String name,
    required int gradeLevel,
    required int academicYear,
  });
  Future<Classroom> update(
    int id, {
    String? name,
    int? gradeLevel,
    int? academicYear,
  });

  /// Moves the classroom to "ห้องเก่า" / back (DESIGN §24.6).
  Future<Classroom> close(int id);
  Future<Classroom> reopen(int id);

  /// Deletes an empty classroom; 409 `classroom_has_data` otherwise.
  Future<void> delete(int id);

  /// Enrols new and existing students (DESIGN §24.4); the answer carries
  /// each new student's initial PIN, which the server never returns again.
  Future<List<EnrolledStudent>> addStudents(
    int id,
    List<StudentEnrolment> students,
  );
  Future<List<RosterStudent>> roster(int id);

  /// "นำนักเรียนจากห้องเดิม" (DESIGN §24.6): enrols [studentIds] of
  /// [sourceClassroomId] with their accounts; [newPins] issues every one a
  /// new PIN (shown once), otherwise they keep PIN and QR card.
  Future<StudentsCopyResult> copyStudents(
    int classroomId, {
    required int sourceClassroomId,
    required List<int> studentIds,
    required CopyNumbering numbering,
    required bool newPins,
  });

  /// `PATCH /classrooms/{id}/students/{student_id}` {username}: the
  /// student's sign-in name (422 `errors.username` when taken or malformed).
  Future<RosterStudent> updateStudentUsername(
    int classroomId,
    int studentId,
    String username,
  );

  /// `PATCH /classrooms/{id}/students/{student_id}` {student_number}.
  Future<RosterStudent> updateStudentNumber(
    int classroomId,
    int studentId,
    int studentNumber,
  );

  /// Takes the student out of the room; the account stays. 409
  /// `student_has_data` once they have work or scores here.
  Future<void> removeStudent(int classroomId, int studentId);

  /// `GET /school-students?q=` (DESIGN §24.4), at least 2 characters.
  Future<List<SchoolStudent>> searchSchoolStudents(String query);

  /// `PATCH /students/{id}` {name, student_code}; an empty code clears it.
  Future<SchoolStudent> updateStudent(
    int studentId, {
    required String name,
    String? studentCode,
  });

  /// `GET /students/duplicate-candidates` (DESIGN §24.4).
  Future<List<DuplicateCandidate>> duplicateCandidates();

  /// `GET /students/merge-preview` and `POST /students/merge` (§24.5).
  Future<MergePreview> mergePreview({
    required int keepId,
    required int mergeId,
  });
  Future<SchoolStudent> merge({required int keepId, required int mergeId});

  /// Queues the PDF with every student's QR login card.
  Future<PrintJob> requestLoginCards(int id);
  Future<PrintJob> loginCardPrint(PrintJob job);

  /// Issues a new card for one student; the old QR token stops working.
  Future<PrintJob> reissueLoginCard(int studentId);

  /// Resets a student's PIN; returns the new PIN (shown once).
  Future<PinReset> resetPin(int studentId);

  /// `POST /classrooms/{id}/students/pending-pins` (DESIGN §19.2): the
  /// first PINs of the students the background roster sync added, shown
  /// once. Empty when nobody waits.
  Future<List<EnrolledStudent>> issuePendingPins(int classroomId);
}

class ApiClassroomsRepository implements ClassroomsRepository {
  ApiClassroomsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<Classroom>> list() async {
    final rows = await fetchAllPages(_dio, '/classrooms');
    return rows.map(Classroom.fromJson).toList();
  }

  @override
  Future<List<Classroom>> listClosed() async {
    final rows = await fetchAllPages(
      _dio,
      '/classrooms',
      query: {'state': 'closed'},
    );
    return rows.map(Classroom.fromJson).toList();
  }

  @override
  Future<Classroom> get(int id) async {
    final res = await _dio.get<Object?>('/classrooms/$id');
    return Classroom.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Classroom> create({
    required String name,
    required int gradeLevel,
    required int academicYear,
  }) async {
    final res = await _dio.post<Object?>(
      '/classrooms',
      data: {
        'name': name,
        'grade_level': gradeLevel,
        'academic_year': academicYear,
      },
    );
    return Classroom.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Classroom> update(
    int id, {
    String? name,
    int? gradeLevel,
    int? academicYear,
  }) async {
    final res = await _dio.patch<Object?>(
      '/classrooms/$id',
      data: {
        'name': ?name,
        'grade_level': ?gradeLevel,
        'academic_year': ?academicYear,
      },
    );
    return Classroom.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Classroom> close(int id) async {
    final res = await _dio.post<Object?>('/classrooms/$id/close');
    return Classroom.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Classroom> reopen(int id) async {
    final res = await _dio.post<Object?>('/classrooms/$id/reopen');
    return Classroom.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> delete(int id) async {
    await _dio.delete<Object?>('/classrooms/$id');
  }

  @override
  Future<List<EnrolledStudent>> addStudents(
    int id,
    List<StudentEnrolment> students,
  ) async {
    // DESIGN §9.2, §24.4: body {students: [{name, student_number,
    // student_code?} | {student_id, student_number, reissue_pin?}]}, answer
    // 201 {data: [{student_id, student_number, name, status, pin, existing}]}.
    final res = await _dio.post<Object?>(
      '/classrooms/$id/students',
      data: {'students': students.map((s) => s.toJson()).toList()},
    );
    return unwrapList(res.data).map(EnrolledStudent.fromJson).toList();
  }

  @override
  Future<StudentsCopyResult> copyStudents(
    int classroomId, {
    required int sourceClassroomId,
    required List<int> studentIds,
    required CopyNumbering numbering,
    required bool newPins,
  }) async {
    final res = await _dio.post<Object?>(
      '/classrooms/$classroomId/students/from-classroom',
      data: {
        'source_classroom_id': sourceClassroomId,
        'student_ids': studentIds,
        'numbering': numbering.apiValue,
        'pin': newPins ? 'new' : 'keep',
      },
    );
    return StudentsCopyResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<RosterStudent> updateStudentUsername(
    int classroomId,
    int studentId,
    String username,
  ) async {
    final res = await _dio.patch<Object?>(
      '/classrooms/$classroomId/students/$studentId',
      data: {'username': username},
    );
    return RosterStudent.fromJson(unwrapJson(res.data));
  }

  @override
  Future<RosterStudent> updateStudentNumber(
    int classroomId,
    int studentId,
    int studentNumber,
  ) async {
    final res = await _dio.patch<Object?>(
      '/classrooms/$classroomId/students/$studentId',
      data: {'student_number': studentNumber},
    );
    return RosterStudent.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> removeStudent(int classroomId, int studentId) async {
    await _dio.delete<Object?>('/classrooms/$classroomId/students/$studentId');
  }

  @override
  Future<List<SchoolStudent>> searchSchoolStudents(String query) async {
    final res = await _dio.get<Object?>(
      '/school-students',
      queryParameters: {'q': query},
    );
    return unwrapList(res.data).map(SchoolStudent.fromJson).toList();
  }

  @override
  Future<SchoolStudent> updateStudent(
    int studentId, {
    required String name,
    String? studentCode,
  }) async {
    final res = await _dio.patch<Object?>(
      '/students/$studentId',
      data: {'name': name, 'student_code': studentCode},
    );
    return SchoolStudent.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<DuplicateCandidate>> duplicateCandidates() async {
    final res = await _dio.get<Object?>('/students/duplicate-candidates');
    return unwrapList(res.data).map(DuplicateCandidate.fromJson).toList();
  }

  @override
  Future<MergePreview> mergePreview({
    required int keepId,
    required int mergeId,
  }) async {
    final res = await _dio.get<Object?>(
      '/students/merge-preview',
      queryParameters: {'keep_id': keepId, 'merge_id': mergeId},
    );
    return MergePreview.fromJson(unwrapJson(res.data));
  }

  @override
  Future<SchoolStudent> merge({
    required int keepId,
    required int mergeId,
  }) async {
    final res = await _dio.post<Object?>(
      '/students/merge',
      data: {'keep_id': keepId, 'merge_id': mergeId},
    );
    final body = unwrapJson(res.data);
    return SchoolStudent.fromJson(
      (body['kept_student'] as Map).cast<String, dynamic>(),
    );
  }

  @override
  Future<List<RosterStudent>> roster(int id) async {
    final rows = await fetchAllPages(_dio, '/classrooms/$id/roster');
    return rows.map(RosterStudent.fromJson).toList();
  }

  @override
  Future<PrintJob> requestLoginCards(int id) async {
    final res = await _dio.post<Object?>('/classrooms/$id/login-cards');
    return PrintJob.fromJson(unwrapJson(res.data));
  }

  @override
  Future<PrintJob> loginCardPrint(PrintJob job) async {
    final path = job.pollUrl != null
        ? resolveApiPath(job.pollUrl!)
        : '/login-card-prints/${job.id}';
    final res = await _dio.get<Object?>(path);
    return PrintJob.fromJson(unwrapJson(res.data));
  }

  @override
  Future<PrintJob> reissueLoginCard(int studentId) async {
    final res = await _dio.post<Object?>('/students/$studentId/login-card');
    return PrintJob.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<EnrolledStudent>> issuePendingPins(int classroomId) async {
    final res = await _dio.post<Object?>(
      '/classrooms/$classroomId/students/pending-pins',
    );
    return unwrapList(res.data).map(EnrolledStudent.fromJson).toList();
  }

  @override
  Future<PinReset> resetPin(int studentId) async {
    final res = await _dio.post<Object?>('/students/$studentId/pin');
    final body = unwrapJson(res.data);
    return PinReset(
      body['pin'].toString(),
      googleUnlinked: body['google_unlinked'] == true,
    );
  }
}

final classroomsRepositoryProvider = Provider<ClassroomsRepository>(
  (ref) => ApiClassroomsRepository(ref.watch(dioProvider)),
);
