import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';

// Co-teaching by invitation (DESIGN §29.13.4).

/// A row of `GET /classrooms/{id}/teachers`: an invitation, or the
/// co-teacher who accepted it with the courses of theirs taught here.
class CoTeacherRow {
  const CoTeacherRow({
    required this.id,
    required this.email,
    required this.status,
    this.teacherName,
    this.courses = const [],
    this.invitedAt,
  });

  factory CoTeacherRow.fromJson(Map<String, dynamic> json) => CoTeacherRow(
    id: (json['id'] as num).toInt(),
    email: json['email'] as String? ?? '',
    status: json['status'] as String? ?? 'pending',
    teacherName: (json['teacher'] as Map?)?['name'] as String?,
    courses: [
      for (final c in json['courses'] as List? ?? const [])
        if (c is Map) '${c['code'] ?? ''} ${c['name'] ?? ''}'.trim(),
    ],
    invitedAt: switch (json['invited_at']) {
      String s => DateTime.tryParse(s),
      _ => null,
    },
  );

  final int id;
  final String email;

  /// pending | accepted | declined.
  final String status;
  final String? teacherName;

  /// "code name" of each course of the co-teacher bound to the classroom.
  final List<String> courses;
  final DateTime? invitedAt;

  bool get accepted => status == 'accepted';

  String get statusLabel => switch (status) {
    'accepted' => 'ร่วมสอนอยู่',
    'declined' => 'ปฏิเสธแล้ว',
    _ => 'รอตอบรับ',
  };
}

/// A row of `GET /classroom-invitations`: a classroom whose owner invited
/// the signed-in teacher.
class ClassroomInvitation {
  const ClassroomInvitation({
    required this.id,
    required this.classroomId,
    required this.classroomName,
    required this.academicYear,
    this.ownerName,
  });

  factory ClassroomInvitation.fromJson(Map<String, dynamic> json) {
    final classroom = (json['classroom'] as Map?)?.cast<String, dynamic>();
    return ClassroomInvitation(
      id: (json['id'] as num).toInt(),
      classroomId: (classroom?['id'] as num?)?.toInt() ?? 0,
      classroomName: classroom?['name'] as String? ?? '',
      academicYear: (classroom?['academic_year'] as num?)?.toInt() ?? 0,
      ownerName: (json['owner'] as Map?)?['name'] as String?,
    );
  }

  final int id;
  final int classroomId;
  final String classroomName;
  final int academicYear;
  final String? ownerName;
}

abstract class CoTeachersRepository {
  /// `GET /classrooms/{id}/teachers` (the owner).
  Future<List<CoTeacherRow>> list(int classroomId);

  /// `POST /classrooms/{id}/teachers {email}`.
  Future<CoTeacherRow> invite(int classroomId, String email);

  /// `DELETE /classrooms/{id}/teachers/{row}`: withdraws an invitation or
  /// removes a co-teacher (409 course_in_use while their work is here).
  Future<void> remove(int classroomId, int rowId);

  /// `GET /classroom-invitations`: the ones waiting for the signed-in teacher.
  Future<List<ClassroomInvitation>> invitations();

  /// `POST /classroom-invitations/{id}/accept`.
  Future<void> accept(int id);

  /// `POST /classroom-invitations/{id}/decline`.
  Future<void> decline(int id);
}

class ApiCoTeachersRepository implements CoTeachersRepository {
  ApiCoTeachersRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<CoTeacherRow>> list(int classroomId) async {
    final res = await _dio.get<Object?>('/classrooms/$classroomId/teachers');
    return unwrapList(res.data).map(CoTeacherRow.fromJson).toList();
  }

  @override
  Future<CoTeacherRow> invite(int classroomId, String email) async {
    final res = await _dio.post<Object?>(
      '/classrooms/$classroomId/teachers',
      data: {'email': email.trim()},
    );
    return CoTeacherRow.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> remove(int classroomId, int rowId) async {
    await _dio.delete<Object?>('/classrooms/$classroomId/teachers/$rowId');
  }

  @override
  Future<List<ClassroomInvitation>> invitations() async {
    final res = await _dio.get<Object?>('/classroom-invitations');
    return unwrapList(res.data).map(ClassroomInvitation.fromJson).toList();
  }

  @override
  Future<void> accept(int id) async {
    await _dio.post<Object?>('/classroom-invitations/$id/accept');
  }

  @override
  Future<void> decline(int id) async {
    await _dio.post<Object?>('/classroom-invitations/$id/decline');
  }
}

final coTeachersRepositoryProvider = Provider<CoTeachersRepository>(
  (ref) => ApiCoTeachersRepository(ref.watch(dioProvider)),
);

/// The co-teachers and invitations of one classroom (the owner's view).
final coTeachersProvider = FutureProvider.autoDispose
    .family<List<CoTeacherRow>, int>((ref, classroomId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(coTeachersRepositoryProvider).list(classroomId);
    }, retry: apiRetry);

/// The invitations waiting for the signed-in teacher.
final classroomInvitationsProvider =
    FutureProvider.autoDispose<List<ClassroomInvitation>>((ref) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(coTeachersRepositoryProvider).invitations();
    }, retry: apiRetry);
