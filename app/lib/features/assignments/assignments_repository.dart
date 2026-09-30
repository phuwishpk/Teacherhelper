import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../worksheets/print_job.dart';
import 'assignment.dart';
import 'question.dart';

/// Teacher-side assignment, rubric and worksheet endpoints (DESIGN §9.3).
abstract class AssignmentsRepository {
  Future<List<Assignment>> list({int? classroomId});
  Future<Assignment> get(int id);

  /// `POST /assignments`: [courseId] must be a course bound to the
  /// classroom; the subject comes from it (DESIGN §20.1). Without
  /// [gradebookCategoryId] homework gets the course's homework default
  /// category (§23.3).
  Future<Assignment> create({
    required int classroomId,
    required int courseId,
    int? lessonPlanId,
    required String title,
    Strictness strictness = Strictness.normal,
    DateTime? dueAt,
    AssignmentMode mode = AssignmentMode.worksheet,
    bool acceptLate = true,
    bool scoreOnly = false,
    int? gradebookCategoryId,
    bool excludedFromGrade = false,
  });
  Future<Assignment> update(
    int id, {
    String? title,
    Strictness? strictness,
    DateTime? dueAt,
    bool clearDueAt = false,
    String? status,
    AssignmentMode? mode,
    bool? acceptLate,
    bool? scoreOnly,
    int? courseId,
    int? lessonPlanId,
    bool clearLessonPlan = false,
    int? gradebookCategoryId,
    bool clearGradebookCategory = false,
    bool? excludedFromGrade,
  });
  Future<void> delete(int id);

  Future<Question> addQuestion(int assignmentId, QuestionDraft draft);
  Future<Question> updateQuestion(int questionId, QuestionDraft draft);
  Future<void> deleteQuestion(int questionId);

  /// Queues DraftRubricJob; poll the assignment until rubric_status changes.
  Future<void> requestRubricDraft(int questionId);
  Future<Question> saveRubric(
    int questionId, {
    required List<RubricCriterion> criteria,
    List<String>? referenceSteps,
  });

  Future<LayoutVersion> createLayout(int assignmentId);
  Future<List<LayoutVersion>> layouts(int assignmentId, {int? version});
  Future<PrintJob> requestWorksheets(int assignmentId);
  Future<PrintJob> worksheetPrint(PrintJob job);

  /// `GET /skills?subject=&grade=&q=&level=`; [level] is a comma list such
  /// as `indicator,sub_indicator` (DESIGN §20.7).
  Future<List<Skill>> searchSkills({
    int? subjectId,
    int? grade,
    String? q,
    String? level,
  });
  Future<List<Subject>> subjects();
}

class ApiAssignmentsRepository implements AssignmentsRepository {
  ApiAssignmentsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<Assignment>> list({int? classroomId}) async {
    final rows = await fetchAllPages(
      _dio,
      '/assignments',
      query: {'classroom_id': ?classroomId},
    );
    return rows.map(Assignment.fromJson).toList();
  }

  @override
  Future<Assignment> get(int id) async {
    final res = await _dio.get<Object?>('/assignments/$id');
    return Assignment.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Assignment> create({
    required int classroomId,
    required int courseId,
    int? lessonPlanId,
    required String title,
    Strictness strictness = Strictness.normal,
    DateTime? dueAt,
    AssignmentMode mode = AssignmentMode.worksheet,
    bool acceptLate = true,
    bool scoreOnly = false,
    int? gradebookCategoryId,
    bool excludedFromGrade = false,
  }) async {
    final res = await _dio.post<Object?>(
      '/assignments',
      data: {
        'classroom_id': classroomId,
        'course_id': courseId,
        'lesson_plan_id': ?lessonPlanId,
        'title': title,
        'strictness': strictness.apiValue,
        'due_at': dueAt?.toUtc().toIso8601String(),
        'mode': mode.apiValue,
        'accept_late': acceptLate,
        'score_only': scoreOnly,
        'gradebook_category_id': ?gradebookCategoryId,
        if (excludedFromGrade) 'excluded_from_grade': true,
      },
    );
    return Assignment.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Assignment> update(
    int id, {
    String? title,
    Strictness? strictness,
    DateTime? dueAt,
    bool clearDueAt = false,
    String? status,
    AssignmentMode? mode,
    bool? acceptLate,
    bool? scoreOnly,
    int? courseId,
    int? lessonPlanId,
    bool clearLessonPlan = false,
    int? gradebookCategoryId,
    bool clearGradebookCategory = false,
    bool? excludedFromGrade,
  }) async {
    final res = await _dio.patch<Object?>(
      '/assignments/$id',
      data: {
        'course_id': ?courseId,
        'lesson_plan_id': ?lessonPlanId,
        if (clearLessonPlan) 'lesson_plan_id': null,
        'title': ?title,
        'strictness': ?strictness?.apiValue,
        if (dueAt != null) 'due_at': dueAt.toUtc().toIso8601String(),
        if (clearDueAt) 'due_at': null,
        'status': ?status,
        'mode': ?mode?.apiValue,
        'accept_late': ?acceptLate,
        'score_only': ?scoreOnly,
        'gradebook_category_id': ?gradebookCategoryId,
        if (clearGradebookCategory) 'gradebook_category_id': null,
        'excluded_from_grade': ?excludedFromGrade,
      },
    );
    return Assignment.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> delete(int id) async {
    await _dio.delete<Object?>('/assignments/$id');
  }

  @override
  Future<Question> addQuestion(int assignmentId, QuestionDraft draft) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/questions',
      data: draft.toJson(),
    );
    return Question.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Question> updateQuestion(int questionId, QuestionDraft draft) async {
    final res = await _dio.patch<Object?>(
      '/questions/$questionId',
      data: draft.toJson(),
    );
    return Question.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteQuestion(int questionId) async {
    await _dio.delete<Object?>('/questions/$questionId');
  }

  @override
  Future<void> requestRubricDraft(int questionId) async {
    await _dio.post<Object?>('/questions/$questionId/rubric/draft');
  }

  @override
  Future<Question> saveRubric(
    int questionId, {
    required List<RubricCriterion> criteria,
    List<String>? referenceSteps,
  }) async {
    final res = await _dio.put<Object?>(
      '/questions/$questionId/rubric',
      data: {
        'criteria': [
          for (var i = 0; i < criteria.length; i++)
            criteria[i].copyWith(position: i + 1).toJson(),
        ],
        'reference_steps': ?referenceSteps,
      },
    );
    return Question.fromJson(unwrapJson(res.data));
  }

  @override
  Future<LayoutVersion> createLayout(int assignmentId) async {
    final res = await _dio.post<Object?>('/assignments/$assignmentId/layout');
    return LayoutVersion.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<LayoutVersion>> layouts(int assignmentId, {int? version}) async {
    final res = await _dio.get<Object?>(
      '/assignments/$assignmentId/layouts',
      queryParameters: {'version': ?version},
    );
    final body = res.data;
    // A single version comes back as one object, all versions as a list.
    if (body is Map<String, dynamic> && body['version'] != null) {
      return [LayoutVersion.fromJson(body)];
    }
    final inner = body is Map<String, dynamic> ? body['data'] : body;
    if (inner is Map<String, dynamic> && inner['version'] != null) {
      return [LayoutVersion.fromJson(inner)];
    }
    return unwrapList(body).map(LayoutVersion.fromJson).toList();
  }

  @override
  Future<PrintJob> requestWorksheets(int assignmentId) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/worksheets',
    );
    return PrintJob.fromJson(unwrapJson(res.data));
  }

  @override
  Future<PrintJob> worksheetPrint(PrintJob job) async {
    final path = job.pollUrl != null
        ? resolveApiPath(job.pollUrl!)
        : '/worksheet-prints/${job.id}';
    final res = await _dio.get<Object?>(path);
    return PrintJob.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<Skill>> searchSkills({
    int? subjectId,
    int? grade,
    String? q,
    String? level,
  }) async {
    final rows = await fetchAllPages(
      _dio,
      '/skills',
      query: {
        'subject': ?subjectId,
        'grade': ?grade,
        if (q != null && q.isNotEmpty) 'q': q,
        if (level != null && level.isNotEmpty) 'level': level,
      },
      maxPages: 5,
    );
    return rows.map(Skill.fromJson).toList();
  }

  @override
  Future<List<Subject>> subjects() async {
    final rows = await fetchAllPages(_dio, '/subjects');
    return rows.map(Subject.fromJson).toList();
  }
}

final assignmentsRepositoryProvider = Provider<AssignmentsRepository>(
  (ref) => ApiAssignmentsRepository(ref.watch(dioProvider)),
);
