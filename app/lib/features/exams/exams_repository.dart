import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/assignment.dart';
import '../worksheets/print_job.dart';
import 'exam_models.dart';
import 'exam_print.dart';

/// The teacher's exam endpoints of build 1 (DESIGN §22.15): the exam
/// itself (`POST`/`PATCH /assignments` with the exam fields), sections,
/// questions, images, the master key, its approval and the versions.
abstract class ExamsRepository {
  Future<Assignment> create(ExamSettingsDraft draft);

  /// `PATCH /assignments/{id}` with only the changed fields.
  Future<Assignment> updateSettings(int examId, Map<String, Object?> changes);

  Future<ExamDetail> get(int examId);

  Future<ExamSection> addSection(int examId, ExamSectionDraft draft);
  Future<ExamSection> updateSection(int sectionId, Map<String, Object?> body);
  Future<void> deleteSection(int sectionId);

  Future<ExamQuestion> addQuestion(int sectionId, ExamQuestionDraft draft);
  Future<ExamQuestion> updateQuestion(int questionId, ExamQuestionDraft draft);
  Future<void> deleteQuestion(int questionId);

  /// Question and mcq option images (JPEG/PNG/WebP up to 5 MB).
  Future<ExamQuestion> uploadQuestionImage(int questionId, PickedDocument f);
  Future<ExamQuestion> deleteQuestionImage(int questionId);
  Future<ExamQuestion> uploadOptionImage(int optionId, PickedDocument f);
  Future<ExamQuestion> deleteOptionImage(int optionId);

  /// JPEG bytes streamed through the policy (§22.17).
  Future<Uint8List> image(ExamImageKey key);

  Future<ExamDetail> approveQuestions(int examId, List<int> questionIds);

  /// `PUT /exams/{id}/answer-key`: only the listed questions; a null key
  /// clears that question's key.
  Future<ExamDetail> saveAnswerKey(
    int examId,
    List<({int questionId, ExamSectionType type, ExamKey? key})> answers,
  );

  /// `POST /assignments/{id}/answer-key/approve` of an exam.
  Future<ExamDetail> approveKey(int examId);

  Future<ExamVersions> versions(int examId);
  Future<ExamVersions> reshuffle(int examId);
  Future<ExamDetail> unlockStructure(int examId);

  /// `POST /exams/{id}/prints` (build 2, §22.6): queues a booklet, the
  /// answer sheets or the key sheet; the first print locks the structure.
  Future<PrintJob> requestPrint(int examId, ExamPrintRequest request);

  /// `GET /worksheet-prints/{id}` (or the job's `status_url`).
  Future<PrintJob> printStatus(PrintJob job);
}

/// An image of a question (`option == false`) or of an mcq option.
typedef ExamImageKey = ({bool option, int id});

class ApiExamsRepository implements ExamsRepository {
  ApiExamsRepository(this._dio);

  final Dio _dio;

  @override
  Future<Assignment> create(ExamSettingsDraft draft) async {
    final res = await _dio.post<Object?>(
      '/assignments',
      data: draft.toCreateJson(),
    );
    return Assignment.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Assignment> updateSettings(
    int examId,
    Map<String, Object?> changes,
  ) async {
    final res = await _dio.patch<Object?>(
      '/assignments/$examId',
      data: changes,
    );
    return Assignment.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamDetail> get(int examId) async {
    final res = await _dio.get<Object?>('/exams/$examId');
    return ExamDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamSection> addSection(int examId, ExamSectionDraft draft) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/sections',
      data: draft.toCreateJson(),
    );
    return ExamSection.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamSection> updateSection(
    int sectionId,
    Map<String, Object?> body,
  ) async {
    final res = await _dio.patch<Object?>(
      '/exam-sections/$sectionId',
      data: body,
    );
    return ExamSection.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteSection(int sectionId) async {
    await _dio.delete<Object?>('/exam-sections/$sectionId');
  }

  @override
  Future<ExamQuestion> addQuestion(
    int sectionId,
    ExamQuestionDraft draft,
  ) async {
    final res = await _dio.post<Object?>(
      '/exam-sections/$sectionId/questions',
      data: draft.toJson(create: true),
    );
    return ExamQuestion.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamQuestion> updateQuestion(
    int questionId,
    ExamQuestionDraft draft,
  ) async {
    final res = await _dio.patch<Object?>(
      '/questions/$questionId',
      data: draft.toJson(),
    );
    return ExamQuestion.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteQuestion(int questionId) async {
    await _dio.delete<Object?>('/questions/$questionId');
  }

  Future<ExamQuestion> _upload(String path, PickedDocument f) async {
    final type = DioMediaType.parse(f.mimeType);
    final bytes = f.bytes;
    final form = FormData.fromMap({
      'image': bytes != null
          ? MultipartFile.fromBytes(bytes, filename: f.name, contentType: type)
          : await MultipartFile.fromFile(
              f.path!,
              filename: f.name,
              contentType: type,
            ),
    });
    final res = await _dio.post<Object?>(path, data: form);
    return ExamQuestion.fromJson(unwrapJson(res.data));
  }

  Future<ExamQuestion> _delete(String path) async {
    final res = await _dio.delete<Object?>(path);
    return ExamQuestion.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamQuestion> uploadQuestionImage(int questionId, PickedDocument f) =>
      _upload('/questions/$questionId/image', f);

  @override
  Future<ExamQuestion> deleteQuestionImage(int questionId) =>
      _delete('/questions/$questionId/image');

  @override
  Future<ExamQuestion> uploadOptionImage(int optionId, PickedDocument f) =>
      _upload('/question-options/$optionId/image', f);

  @override
  Future<ExamQuestion> deleteOptionImage(int optionId) =>
      _delete('/question-options/$optionId/image');

  @override
  Future<Uint8List> image(ExamImageKey key) async {
    final path = key.option
        ? '/question-options/${key.id}/image'
        : '/questions/${key.id}/image';
    final res = await _dio.get<List<int>>(
      path,
      options: Options(
        responseType: ResponseType.bytes,
        headers: {'Accept': 'image/jpeg,image/*'},
      ),
    );
    final data = res.data ?? const <int>[];
    return data is Uint8List ? data : Uint8List.fromList(data);
  }

  @override
  Future<ExamDetail> approveQuestions(int examId, List<int> questionIds) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/questions/approve',
      data: {'question_ids': questionIds},
    );
    return ExamDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamDetail> saveAnswerKey(
    int examId,
    List<({int questionId, ExamSectionType type, ExamKey? key})> answers,
  ) async {
    final res = await _dio.put<Object?>(
      '/exams/$examId/answer-key',
      data: {
        'answers': [
          for (final a in answers)
            {
              'question_id': a.questionId,
              if (a.type == ExamSectionType.numeric)
                'accepted_values': a.key?.values ?? const <String>[]
              else
                'accepted_options': a.key?.options ?? const <int>[],
            },
        ],
      },
    );
    return ExamDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamDetail> approveKey(int examId) async {
    final res = await _dio.post<Object?>(
      '/assignments/$examId/answer-key/approve',
    );
    return ExamDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamVersions> versions(int examId) async {
    final res = await _dio.get<Object?>('/exams/$examId/versions');
    return ExamVersions.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamVersions> reshuffle(int examId) async {
    final res = await _dio.post<Object?>('/exams/$examId/versions/reshuffle');
    return ExamVersions.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamDetail> unlockStructure(int examId) async {
    final res = await _dio.post<Object?>('/exams/$examId/unlock-structure');
    return ExamDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<PrintJob> requestPrint(int examId, ExamPrintRequest request) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/prints',
      data: request.toJson(),
    );
    return PrintJob.fromJson(unwrapJson(res.data));
  }

  @override
  Future<PrintJob> printStatus(PrintJob job) async {
    final path = job.pollUrl != null
        ? resolveApiPath(job.pollUrl!)
        : '/worksheet-prints/${job.id}';
    final res = await _dio.get<Object?>(path);
    return PrintJob.fromJson(unwrapJson(res.data));
  }
}

final examsRepositoryProvider = Provider<ExamsRepository>(
  (ref) => ApiExamsRepository(ref.watch(dioProvider)),
);

/// The field errors of a 422 (`errors`: {"answers.3.accepted_values.0":
/// ["…"]}), first message per field; empty for anything else.
Map<String, String> apiFieldErrors(Object error) {
  if (error is! DioException) return const {};
  final data = error.response?.data;
  if (data is! Map || data['errors'] is! Map) return const {};
  return {
    for (final e in (data['errors'] as Map).entries)
      e.key.toString(): switch (e.value) {
        List l when l.isNotEmpty => l.first.toString(),
        final v => v.toString(),
      },
  };
}
