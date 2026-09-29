import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../assignments/answer_key_models.dart';
import 'hand_in_models.dart';

/// Upload progress: bytes sent so far and in all (-1 when unknown).
typedef UploadProgress = void Function(int sent, int total);

/// Whole-page hand-ins (DESIGN §19.6, §19.9): the student's own from the
/// app, and the teacher's upload of a student's pages from files.
abstract class HandInRepository {
  /// `GET /student/assignments`.
  Future<List<StudentAssignment>> studentAssignments();

  /// `POST /student/assignments/{id}/submission` multipart `files[]`.
  Future<HandInReceipt> submit(
    int assignmentId,
    List<PickedDocument> files, {
    UploadProgress? onProgress,
  });

  /// `POST /assignments/{id}/students/{studentId}/pages` multipart
  /// `files[]` (teacher).
  Future<TeacherUploadResult> uploadForStudent(
    int assignmentId,
    int studentId,
    List<PickedDocument> files, {
    UploadProgress? onProgress,
  });

  /// Students of [assignmentId] who already have a submission, by student
  /// id: `meta.submissions` of the review queue (one row asked for).
  Future<Map<int, HandedIn>> handedIn(int assignmentId);
}

class ApiHandInRepository implements HandInRepository {
  ApiHandInRepository(this._dio);

  final Dio _dio;

  /// Five photos or PDFs of up to 10 MB each on a school connection.
  static final _uploadOptions = Options(
    sendTimeout: const Duration(minutes: 5),
    receiveTimeout: const Duration(minutes: 2),
  );

  @override
  Future<List<StudentAssignment>> studentAssignments() async {
    final res = await _dio.get<Object?>('/student/assignments');
    return unwrapList(res.data).map(StudentAssignment.fromJson).toList();
  }

  @override
  Future<HandInReceipt> submit(
    int assignmentId,
    List<PickedDocument> files, {
    UploadProgress? onProgress,
  }) async {
    final res = await _dio.post<Object?>(
      '/student/assignments/$assignmentId/submission',
      data: await handInForm(files),
      options: _uploadOptions,
      onSendProgress: onProgress,
    );
    return HandInReceipt.fromJson(unwrapJson(res.data));
  }

  @override
  Future<TeacherUploadResult> uploadForStudent(
    int assignmentId,
    int studentId,
    List<PickedDocument> files, {
    UploadProgress? onProgress,
  }) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/students/$studentId/pages',
      data: await handInForm(files),
      options: _uploadOptions,
      onSendProgress: onProgress,
    );
    return TeacherUploadResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Map<int, HandedIn>> handedIn(int assignmentId) async {
    final res = await _dio.get<Object?>(
      '/assignments/$assignmentId/review-queue',
      queryParameters: {'per_page': 1},
    );
    final body = res.data;
    final meta = body is Map ? body['meta'] : null;
    final rows = meta is Map ? meta['submissions'] : null;
    final byStudent = <int, HandedIn>{};
    if (rows is List) {
      for (final row in rows.whereType<Map>()) {
        final student = row['student'];
        final studentId = student is Map ? student['id'] : null;
        final id = row['id'];
        if (studentId is! num || id is! num) continue;
        byStudent[studentId.toInt()] = HandedIn(
          studentId: studentId.toInt(),
          submissionId: id.toInt(),
          status: row['status'] as String? ?? 'awaiting_scan',
          late: row['late'] == true,
        );
      }
    }
    return byStudent;
  }
}

/// `files[]` of a hand-in: in-memory bytes (web, file_picker) or a local
/// file (camera photos on the phone).
Future<FormData> handInForm(List<PickedDocument> files) async {
  final form = FormData();
  for (final f in files) {
    final type = DioMediaType.parse(f.mimeType);
    final bytes = f.bytes;
    form.files.add(
      MapEntry(
        'files[]',
        bytes != null
            ? MultipartFile.fromBytes(
                bytes,
                filename: f.name,
                contentType: type,
              )
            : await MultipartFile.fromFile(
                f.path!,
                filename: f.name,
                contentType: type,
              ),
      ),
    );
  }
  return form;
}

final handInRepositoryProvider = Provider<HandInRepository>(
  (ref) => ApiHandInRepository(ref.watch(dioProvider)),
);

/// The student's "งานที่ต้องส่ง".
final studentAssignmentsProvider =
    FutureProvider.autoDispose<List<StudentAssignment>>((ref) {
      watchSignedInUser(ref);
      return ref.watch(handInRepositoryProvider).studentAssignments();
    });

/// Who already handed in [assignmentId] (teacher).
final handedInProvider = FutureProvider.autoDispose
    .family<Map<int, HandedIn>, int>((ref, assignmentId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(handInRepositoryProvider).handedIn(assignmentId);
    });
