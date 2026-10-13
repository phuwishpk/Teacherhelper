import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';

// Joining a classroom with its class code (DESIGN §29.13.3).

/// What `GET /join/{code}` and `GET /student/join/{code}` say about the
/// classroom behind a code.
class JoinInfo {
  const JoinInfo({
    required this.name,
    required this.gradeLevel,
    required this.academicYear,
    required this.open,
    this.teacherName,
    this.schoolName,
    this.member = false,
    this.seats = const [],
  });

  factory JoinInfo.fromJson(Map<String, dynamic> json) => JoinInfo(
    name: json['name'] as String? ?? '',
    gradeLevel: (json['grade_level'] as num?)?.toInt() ?? 0,
    academicYear: (json['academic_year'] as num?)?.toInt() ?? 0,
    open: json['open'] == true,
    teacherName: json['teacher_name'] as String?,
    schoolName: json['school_name'] as String?,
    member: json['member'] == true,
    seats: [
      for (final s in json['seats'] as List? ?? const [])
        JoinSeat.fromJson(s as Map<String, dynamic>),
    ],
  );

  final String name;
  final int gradeLevel;
  final int academicYear;

  /// False when the classroom is closed or the owner stopped taking members.
  final bool open;
  final String? teacherName;
  final String? schoolName;

  /// The signed-in pupil is in the classroom already (student route only).
  final bool member;

  /// Seats the teacher made that nobody has used yet (student route only).
  final List<JoinSeat> seats;
}

/// A roster account the teacher made for a pupil who has not signed in yet.
class JoinSeat {
  const JoinSeat({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    required this.needsCode,
  });

  factory JoinSeat.fromJson(Map<String, dynamic> json) => JoinSeat(
    studentId: (json['student_id'] as num).toInt(),
    studentNumber: (json['student_number'] as num?)?.toInt() ?? 0,
    name: json['name'] as String? ?? '',
    needsCode: json['needs_code'] == true,
  );

  final int studentId;
  final int studentNumber;
  final String name;

  /// The teacher typed a student code for this seat: the pupil must give it.
  final bool needsCode;
}

/// The answer of `POST /student/join`.
class JoinResult {
  const JoinResult({
    required this.member,
    required this.joined,
    this.studentNumber,
  });

  factory JoinResult.fromJson(Map<String, dynamic> json) => JoinResult(
    member: json['member'] == true,
    joined: json['joined'] == true,
    studentNumber: (json['student_number'] as num?)?.toInt(),
  );

  final bool member;

  /// False when the pupil was in the classroom already.
  final bool joined;
  final int? studentNumber;
}

/// Upper case without spaces, as the server stores class codes.
String normalizeJoinCode(String raw) =>
    raw.replaceAll(RegExp(r'\s+'), '').toUpperCase();

abstract class JoinRepository {
  /// `GET /join/{code}` (public).
  Future<JoinInfo> publicInfo(String code);

  /// `GET /student/join/{code}` (signed-in pupil).
  Future<JoinInfo> preview(String code);

  /// `POST /student/join {code, student_id?, student_code?}`.
  Future<JoinResult> join(String code, {int? seatId, String? studentCode});

  /// `POST /auth/google/join {link_ticket, join_code, accept_notice}`: an
  /// unknown Google account becomes a student because the pupil holds a
  /// class code; returns the token like any sign-in.
  Future<String> googleJoin({required String linkTicket, required String code});
}

class ApiJoinRepository implements JoinRepository {
  ApiJoinRepository(this._dio);

  final Dio _dio;

  @override
  Future<JoinInfo> publicInfo(String code) async {
    final res = await _dio.get<Object?>('/join/${normalizeJoinCode(code)}');
    return JoinInfo.fromJson(unwrapJson(res.data));
  }

  @override
  Future<JoinInfo> preview(String code) async {
    final res = await _dio.get<Object?>(
      '/student/join/${normalizeJoinCode(code)}',
    );
    return JoinInfo.fromJson(unwrapJson(res.data));
  }

  @override
  Future<JoinResult> join(
    String code, {
    int? seatId,
    String? studentCode,
  }) async {
    final res = await _dio.post<Object?>(
      '/student/join',
      data: {
        'code': normalizeJoinCode(code),
        'student_id': ?seatId,
        'student_code': ?studentCode,
      },
    );
    return JoinResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<String> googleJoin({
    required String linkTicket,
    required String code,
  }) async {
    final res = await _dio.post<Object?>(
      '/auth/google/join',
      data: {
        'link_ticket': linkTicket,
        'join_code': normalizeJoinCode(code),
        'accept_notice': true,
      },
    );
    return unwrapJson(res.data)['token'] as String;
  }
}

final joinRepositoryProvider = Provider<JoinRepository>(
  (ref) => ApiJoinRepository(ref.watch(dioProvider)),
);

/// The public page of a join code (no token).
final joinPublicInfoProvider = FutureProvider.autoDispose
    .family<JoinInfo, String>(
      (ref, code) => ref.watch(joinRepositoryProvider).publicInfo(code),
      retry: apiRetry,
    );

/// The same for the signed-in pupil, with the free seats.
final joinPreviewProvider = FutureProvider.autoDispose.family<JoinInfo, String>(
  (ref, code) => ref.watch(joinRepositoryProvider).preview(code),
  retry: apiRetry,
);
