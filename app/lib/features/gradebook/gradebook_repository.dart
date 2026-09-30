import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'gradebook_models.dart';

/// The gradebook endpoints of DESIGN §23.11: settings of a course, the
/// grid of a classroom, scores, ร/มส, publishing, the CSV, and the
/// student's own published grades.
abstract class GradebookRepository {
  /// `GET /gradebook/templates`.
  Future<List<GradebookTemplate>> templates();

  /// `GET /courses/{id}/gradebook/settings`.
  Future<GradebookSettings> settings(int courseId);

  /// `PUT /courses/{id}/gradebook/categories {template}`: only while the
  /// course has no category (409 `gradebook_configured`).
  Future<GradebookSettings> applyTemplate(int courseId, String template);

  /// `PUT /courses/{id}/gradebook/categories {categories}`: replaces the
  /// whole set in this order (422 `weights_not_100`).
  Future<GradebookSettings> saveCategories(
    int courseId,
    List<CategoryDraft> categories,
  );

  /// `PUT /courses/{id}/gradebook/cutoffs`; null = the defaults.
  Future<GradebookSettings> saveCutoffs(int courseId, List<int>? cutoffs);

  /// `GET /courses/{id}/gradebook?classroom_id=`.
  Future<GradebookGrid> grid(int courseId, int classroomId);

  /// `POST /courses/{id}/gradebook-items`: one item per classroom.
  Future<List<GradebookItem>> addItem(int courseId, GradebookItemDraft draft);

  /// `PATCH /gradebook-items/{id}`.
  Future<GradebookItem> updateItem(int itemId, GradebookItemDraft draft);

  /// `DELETE /gradebook-items/{id}` (its scores go with it).
  Future<void> deleteItem(int itemId);

  /// `PUT /gradebook-items/{id}/scores` or `PUT
  /// /assignments/{id}/gradebook-scores`, by [column] type, in chunks of
  /// [kMaxScoreRows].
  Future<void> saveScores(GradebookColumn column, List<ScoreChange> changes);

  /// "ให้เต็มทั้งห้อง": the number of cells filled.
  Future<int> fillFull(GradebookColumn column);

  /// `PUT /courses/{id}/gradebook/special-grades`; [special] null clears.
  Future<void> setSpecialGrade(
    int courseId, {
    required int classroomId,
    required int studentId,
    String? special,
    String? note,
  });

  /// `POST /courses/{id}/gradebook/publish` (422 `gradebook_incomplete`).
  Future<PublishResult> publish(int courseId, int classroomId);

  /// `DELETE /courses/{id}/gradebook/publish?classroom_id=`.
  Future<void> withdraw(int courseId, int classroomId);

  /// `GET /courses/{id}/gradebook/export?classroom_id=` (§23.8).
  Future<CsvExport> exportCsv(int courseId, int classroomId);

  /// `GET /student/grades`.
  Future<List<StudentGradeSummary>> myGrades();

  /// `GET /student/courses/{id}/grade` (404 until published).
  Future<StudentGradeDetail> myCourseGrade(int courseId);
}

class ApiGradebookRepository implements GradebookRepository {
  ApiGradebookRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<GradebookTemplate>> templates() async {
    final res = await _dio.get<Object?>('/gradebook/templates');
    return unwrapList(res.data).map(GradebookTemplate.fromJson).toList();
  }

  @override
  Future<GradebookSettings> settings(int courseId) async => _settings(
    await _dio.get<Object?>('/courses/$courseId/gradebook/settings'),
  );

  @override
  Future<GradebookSettings> applyTemplate(
    int courseId,
    String template,
  ) async => _settings(
    await _dio.put<Object?>(
      '/courses/$courseId/gradebook/categories',
      data: {'template': template},
    ),
  );

  @override
  Future<GradebookSettings> saveCategories(
    int courseId,
    List<CategoryDraft> categories,
  ) async => _settings(
    await _dio.put<Object?>(
      '/courses/$courseId/gradebook/categories',
      data: {
        'categories': [for (final c in categories) c.toJson()],
      },
    ),
  );

  @override
  Future<GradebookSettings> saveCutoffs(
    int courseId,
    List<int>? cutoffs,
  ) async => _settings(
    await _dio.put<Object?>(
      '/courses/$courseId/gradebook/cutoffs',
      data: {'cutoffs': cutoffs},
    ),
  );

  @override
  Future<GradebookGrid> grid(int courseId, int classroomId) async {
    final res = await _dio.get<Object?>(
      '/courses/$courseId/gradebook',
      queryParameters: {'classroom_id': classroomId},
    );
    return GradebookGrid.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<GradebookItem>> addItem(
    int courseId,
    GradebookItemDraft draft,
  ) async {
    final res = await _dio.post<Object?>(
      '/courses/$courseId/gradebook-items',
      data: draft.toCreateJson(),
    );
    return unwrapList(res.data).map(GradebookItem.fromJson).toList();
  }

  @override
  Future<GradebookItem> updateItem(int itemId, GradebookItemDraft draft) async {
    final res = await _dio.patch<Object?>(
      '/gradebook-items/$itemId',
      data: draft.toUpdateJson(),
    );
    return GradebookItem.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteItem(int itemId) async {
    await _dio.delete<Object?>('/gradebook-items/$itemId');
  }

  String _scoresPath(GradebookColumn column) => column.type == ColumnType.custom
      ? '/gradebook-items/${column.id}/scores'
      : '/assignments/${column.id}/gradebook-scores';

  @override
  Future<void> saveScores(
    GradebookColumn column,
    List<ScoreChange> changes,
  ) async {
    for (var i = 0; i < changes.length; i += kMaxScoreRows) {
      final chunk = changes.skip(i).take(kMaxScoreRows);
      await _dio.put<Object?>(
        _scoresPath(column),
        data: {
          'scores': [for (final c in chunk) c.toJson()],
        },
      );
    }
  }

  @override
  Future<int> fillFull(GradebookColumn column) async {
    final path = column.type == ColumnType.custom
        ? '/gradebook-items/${column.id}/fill-full'
        : '/assignments/${column.id}/gradebook-scores/fill-full';
    final res = await _dio.post<Object?>(path);
    return (unwrapJson(res.data)['filled'] as num?)?.toInt() ?? 0;
  }

  @override
  Future<void> setSpecialGrade(
    int courseId, {
    required int classroomId,
    required int studentId,
    String? special,
    String? note,
  }) async {
    final trimmed = note?.trim();
    await _dio.put<Object?>(
      '/courses/$courseId/gradebook/special-grades',
      data: {
        'classroom_id': classroomId,
        'student_id': studentId,
        'special': special,
        if (special != null && trimmed != null && trimmed.isNotEmpty)
          'note': trimmed,
      },
    );
  }

  @override
  Future<PublishResult> publish(int courseId, int classroomId) async {
    final res = await _dio.post<Object?>(
      '/courses/$courseId/gradebook/publish',
      data: {'classroom_id': classroomId},
    );
    return PublishResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> withdraw(int courseId, int classroomId) async {
    await _dio.delete<Object?>(
      '/courses/$courseId/gradebook/publish',
      queryParameters: {'classroom_id': classroomId},
    );
  }

  @override
  Future<CsvExport> exportCsv(int courseId, int classroomId) async {
    try {
      final res = await _dio.get<List<int>>(
        '/courses/$courseId/gradebook/export',
        queryParameters: {'classroom_id': classroomId},
        options: Options(
          responseType: ResponseType.bytes,
          headers: {'Accept': 'text/csv, application/json'},
        ),
      );
      final data = res.data ?? const <int>[];
      return CsvExport(
        fileName:
            csvFileName(res.headers.value('content-disposition')) ??
            'gradebook-$courseId-$classroomId.csv',
        bytes: data is Uint8List ? data : Uint8List.fromList(data),
      );
    } on DioException catch (e) {
      // The error envelope came back as bytes: decode it so
      // apiErrorMessage / apiErrorCode read it like any other answer.
      final response = e.response;
      final data = response?.data;
      if (response != null && data is List<int>) {
        try {
          response.data = jsonDecode(utf8.decode(data));
        } on FormatException {
          // Not JSON: keep the generic message.
        }
      }
      rethrow;
    }
  }

  @override
  Future<List<StudentGradeSummary>> myGrades() async {
    final res = await _dio.get<Object?>('/student/grades');
    return unwrapList(res.data).map(StudentGradeSummary.fromJson).toList();
  }

  @override
  Future<StudentGradeDetail> myCourseGrade(int courseId) async {
    final res = await _dio.get<Object?>('/student/courses/$courseId/grade');
    return StudentGradeDetail.fromJson(unwrapJson(res.data));
  }

  static GradebookSettings _settings(Response<Object?> res) =>
      GradebookSettings.fromJson(unwrapJson(res.data));
}

/// The file name of a `Content-Disposition` header: the UTF-8
/// `filename*` (a Thai course code or classroom) before the ASCII one.
String? csvFileName(String? disposition) {
  if (disposition == null) return null;
  final star = RegExp(
    r"filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)",
  ).firstMatch(disposition);
  if (star != null) {
    try {
      return Uri.decodeComponent(star.group(1)!.trim());
    } on ArgumentError {
      // Fall back to the plain name.
    }
  }
  final plain = RegExp(r'filename\s*=\s*"?([^";]+)"?').firstMatch(disposition);
  return plain?.group(1)?.trim();
}

final gradebookRepositoryProvider = Provider<GradebookRepository>(
  (ref) => ApiGradebookRepository(ref.watch(dioProvider)),
);
