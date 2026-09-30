import 'dart:async';

import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/hand_in/hand_in_models.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';

/// Canned hand-in endpoints; records what the screens sent.
class FakeHandIn implements HandInRepository {
  FakeHandIn({
    this.assignments = const [],
    this.handedInByAssignment = const {},
  });

  List<StudentAssignment> assignments;
  Map<int, Map<int, HandedIn>> handedInByAssignment;

  final submitted = <(int, List<PickedDocument>)>[];
  final uploaded = <(int, int, List<PickedDocument>)>[];
  int listCalls = 0;

  /// Makes the next upload wait until completed (progress shown meanwhile).
  Completer<void>? gate;

  /// Thrown by the next submit/upload.
  Object? error;

  HandInReceipt receipt = HandInReceipt(
    submissionId: 55,
    submittedAt: DateTime.utc(2026, 9, 30, 1),
    pages: 2,
  );

  TeacherUploadResult uploadResult = const TeacherUploadResult(
    submissionId: 90,
    studentId: 41,
    pages: 1,
    grading: true,
  );

  @override
  Future<List<StudentAssignment>> studentAssignments() async {
    listCalls++;
    return assignments;
  }

  Future<void> _wait(UploadProgress? onProgress) async {
    onProgress?.call(50, 100);
    if (gate case final g?) await g.future;
    onProgress?.call(100, 100);
    if (error case final e?) {
      error = null;
      throw e;
    }
  }

  @override
  Future<HandInReceipt> submit(
    int assignmentId,
    List<PickedDocument> files, {
    UploadProgress? onProgress,
  }) async {
    submitted.add((assignmentId, files));
    await _wait(onProgress);
    return receipt;
  }

  @override
  Future<TeacherUploadResult> uploadForStudent(
    int assignmentId,
    int studentId,
    List<PickedDocument> files, {
    UploadProgress? onProgress,
  }) async {
    uploaded.add((assignmentId, studentId, files));
    await _wait(onProgress);
    return uploadResult;
  }

  @override
  Future<Map<int, HandedIn>> handedIn(int assignmentId) async =>
      handedInByAssignment[assignmentId] ?? const {};
}

/// A 422 in the API envelope, as the server answers a refused hand-in.
DioException apiError(int status, String message, String code) => DioException(
  requestOptions: RequestOptions(path: '/x'),
  response: Response(
    requestOptions: RequestOptions(path: '/x'),
    statusCode: status,
    data: {'message': message, 'errors': {}, 'code': code},
  ),
);
