import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../worksheets/print_job.dart';
import 'classroom.dart';

/// Result of `POST /students/{id}/pin`: the new PIN is shown exactly once.
class PinReset {
  const PinReset(this.pin);

  final String pin;
}

/// Teacher-side classroom endpoints (DESIGN §9.2).
abstract class ClassroomsRepository {
  Future<List<Classroom>> list();
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
  Future<List<RosterStudent>> addStudents(int id, List<NewStudent> students);
  Future<List<RosterStudent>> roster(int id);

  /// Queues the PDF with every student's QR login card.
  Future<PrintJob> requestLoginCards(int id);
  Future<PrintJob> loginCardPrint(PrintJob job);

  /// Issues a new card for one student; the old QR token stops working.
  Future<PrintJob> reissueLoginCard(int studentId);

  /// Resets a student's PIN; returns the new PIN (shown once).
  Future<PinReset> resetPin(int studentId);
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
  Future<List<RosterStudent>> addStudents(
    int id,
    List<NewStudent> students,
  ) async {
    final res = await _dio.post<Object?>(
      '/classrooms/$id/students',
      data: {'students': students.map((s) => s.toJson()).toList()},
    );
    // The server may answer with the created rows or with the full roster.
    try {
      return unwrapList(res.data).map(RosterStudent.fromJson).toList();
    } on FormatException {
      return roster(id);
    }
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
  Future<PinReset> resetPin(int studentId) async {
    final res = await _dio.post<Object?>('/students/$studentId/pin');
    final body = unwrapJson(res.data);
    return PinReset(body['pin'].toString());
  }
}

final classroomsRepositoryProvider = Provider<ClassroomsRepository>(
  (ref) => ApiClassroomsRepository(ref.watch(dioProvider)),
);
