import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/teacher_guidance.dart';
import '../../core/auth/auth_repository.dart';
import '../assignments/answer_key_models.dart';
import 'exam_import_models.dart';
import 'exam_models.dart';
import 'exams_repository.dart';

/// The endpoints of build 5 (DESIGN §22.4, §22.15): read an exam file once
/// into draft questions, the page images its figures are cropped from, the
/// source file for rendering, boxing a figure again, and copying questions
/// from the teacher's earlier exams.
abstract class ExamImportRepository {
  /// `POST /exams/{id}/import/estimate`: free, queues nothing.
  Future<KeyEstimate> estimate(
    int examId, {
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  });

  /// `POST /exams/{id}/import`: 200 applied at once (read before in this
  /// school) or 202 queued.
  Future<ExamImportResult> import(
    int examId, {
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  });

  /// `GET /document-extractions/{id}`, polled while the read is queued.
  Future<ExamRead> read(int extractionId);

  /// `GET /exam-page-images/{id}`: JPEG of a page (for drawing a box).
  Future<Uint8List> pageImage(int pageImageId);

  /// `GET /exams/{id}/documents/{document_id}/file`: the file the exam
  /// read, for rendering its pages when the phone has no copy.
  Future<Uint8List> documentFile(int examId, int documentId);

  /// `POST /exams/{id}/page-images`: a page the app rendered; answers the
  /// pages still waiting.
  Future<List<FigurePending>> uploadPageImage(
    int examId, {
    required int sourceDocumentId,
    required int pageNo,
    required String jpegPath,
  });

  /// `PUT /questions/{id}/figure` or `/question-options/{id}/figure`.
  Future<ExamQuestion> setFigure(
    ExamImageKey target, {
    required int pageImageId,
    required List<int> box,
  });

  /// `GET /teacher/exam-questions`: only the teacher's own exams.
  Future<LibraryPage> library({
    int? courseId,
    int? examId,
    String? query,
    int? excludeExam,
    String? cursor,
  });

  /// `POST /exams/{id}/copy-questions`; [sectionId] null creates sections
  /// like the source ones.
  Future<ExamCopyResult> copyQuestions(
    int examId, {
    required List<int> questionIds,
    int? sectionId,
  });
}

class ApiExamImportRepository implements ExamImportRepository {
  ApiExamImportRepository(this._dio);

  final Dio _dio;

  static Map<String, Object?> _selection(
    List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  ) => {
    'document_ids': documentIds,
    if (pageFrom != null && pageTo != null) ...{
      'page_from': pageFrom,
      'page_to': pageTo,
    },
    'guidance': ?normalizeGuidance(guidance),
  };

  static Options get _bytes => Options(
    responseType: ResponseType.bytes,
    receiveTimeout: const Duration(minutes: 2),
  );

  static Uint8List _asBytes(List<int>? data) {
    final d = data ?? const <int>[];
    return d is Uint8List ? d : Uint8List.fromList(d);
  }

  @override
  Future<KeyEstimate> estimate(
    int examId, {
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/import/estimate',
      data: _selection(documentIds, pageFrom, pageTo, guidance),
    );
    return KeyEstimate.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamImportResult> import(
    int examId, {
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/import',
      data: _selection(documentIds, pageFrom, pageTo, guidance),
    );
    return ExamImportResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamRead> read(int extractionId) async {
    final res = await _dio.get<Object?>('/document-extractions/$extractionId');
    return ExamRead.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Uint8List> pageImage(int pageImageId) async {
    final res = await _dio.get<List<int>>(
      '/exam-page-images/$pageImageId',
      options: _bytes.copyWith(headers: {'Accept': 'image/jpeg,image/*'}),
    );
    return _asBytes(res.data);
  }

  @override
  Future<Uint8List> documentFile(int examId, int documentId) async {
    final res = await _dio.get<List<int>>(
      '/exams/$examId/documents/$documentId/file',
      options: _bytes.copyWith(headers: {'Accept': '*/*'}),
    );
    return _asBytes(res.data);
  }

  @override
  Future<List<FigurePending>> uploadPageImage(
    int examId, {
    required int sourceDocumentId,
    required int pageNo,
    required String jpegPath,
  }) async {
    final form = FormData.fromMap({
      'source_document_id': sourceDocumentId,
      'page_no': pageNo,
      'image': await MultipartFile.fromFile(
        jpegPath,
        filename: 'page-$sourceDocumentId-$pageNo.jpg',
        contentType: DioMediaType('image', 'jpeg'),
      ),
    });
    final res = await _dio.post<Object?>(
      '/exams/$examId/page-images',
      data: form,
      options: Options(sendTimeout: const Duration(minutes: 2)),
    );
    return FigurePending.listOf(unwrapJson(res.data)['figures_pending']);
  }

  @override
  Future<ExamQuestion> setFigure(
    ExamImageKey target, {
    required int pageImageId,
    required List<int> box,
  }) async {
    final path = target.option
        ? '/question-options/${target.id}/figure'
        : '/questions/${target.id}/figure';
    final res = await _dio.put<Object?>(
      path,
      data: {'page_image_id': pageImageId, 'box_2d': box},
    );
    return ExamQuestion.fromJson(unwrapJson(res.data));
  }

  @override
  Future<LibraryPage> library({
    int? courseId,
    int? examId,
    String? query,
    int? excludeExam,
    String? cursor,
  }) async {
    final q = query?.trim();
    final res = await _dio.get<Object?>(
      '/teacher/exam-questions',
      queryParameters: {
        'course_id': ?courseId,
        'exam_id': ?examId,
        if (q != null && q.isNotEmpty) 'q': q,
        'exclude_exam': ?excludeExam,
        'cursor': ?cursor,
      },
    );
    final body = res.data;
    if (body is! Map) throw const FormatException('Expected a JSON object');
    return LibraryPage.fromJson(body.cast<String, dynamic>());
  }

  @override
  Future<ExamCopyResult> copyQuestions(
    int examId, {
    required List<int> questionIds,
    int? sectionId,
  }) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/copy-questions',
      data: {'question_ids': questionIds, 'section_id': ?sectionId},
    );
    return ExamCopyResult.fromJson(unwrapJson(res.data));
  }
}

final examImportRepositoryProvider = Provider<ExamImportRepository>(
  (ref) => ApiExamImportRepository(ref.watch(dioProvider)),
);
