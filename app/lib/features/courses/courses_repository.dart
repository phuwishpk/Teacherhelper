import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/teacher_guidance.dart';
import '../../core/auth/auth_repository.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/question.dart';
import 'course_models.dart';

/// The confirmed result of a read document (`POST /courses/import`,
/// DESIGN §20.1): a new [course] or more units and plans for [courseId].
class CourseImport {
  const CourseImport({
    this.extractionId,
    this.course,
    this.courseId,
    this.classroomIds = const [],
    this.indicators = const [],
    this.units = const [],
    this.lessonPlans = const [],
  }) : assert((course == null) != (courseId == null));

  final int? extractionId;
  final CourseDraft? course;
  final int? courseId;
  final List<int> classroomIds;
  final List<Skill> indicators;
  final List<UnitDraft> units;
  final List<PlanDraft> lessonPlans;

  Map<String, dynamic> toJson() => {
    'extraction_id': ?extractionId,
    if (course != null) 'course': course!.toJson(),
    'course_id': ?courseId,
    'classroom_ids': classroomIds,
    'skill_ids': [for (final s in indicators) s.id],
    'units': [for (final u in units) u.toJson()],
    'lesson_plans': [for (final p in lessonPlans) p.toImportJson()],
  };
}

/// Courses, units, lesson plans and their documents (DESIGN §20.7), plus
/// the indicators a teacher adds for the school (§20.2).
abstract class CoursesRepository {
  /// `GET /courses?classroom_id=` (not paginated).
  Future<List<Course>> list({int? classroomId});

  /// `GET /classrooms/{id}/courses` (DESIGN §24.12 B): the courses taught
  /// in a classroom with their teachers; a subject teacher gets only their
  /// own.
  Future<List<ClassroomCourse>> taughtIn(int classroomId);

  /// `DELETE /classrooms/{id}/courses/{course_id}` (DESIGN §24.7 step 5);
  /// 409 `course_in_use` while the room has assignments of the course.
  Future<void> unbind(int classroomId, int courseId);

  /// `GET /courses/{id}` with indicators, units and lesson plans.
  Future<Course> get(int id);

  Future<Course> create(
    CourseDraft draft, {
    List<int> classroomIds = const [],
    List<int> skillIds = const [],
  });

  Future<Course> update(int id, CourseDraft draft);

  /// 409 `course_in_use` while assignments use it.
  Future<void> delete(int id);

  /// `PUT /courses/{id}/classrooms`; 409 `course_in_use` for a classroom
  /// whose assignments use the course.
  Future<Course> setClassrooms(int id, List<int> classroomIds);

  /// `PUT /courses/{id}/indicators` (replaces the set).
  Future<Course> setIndicators(int id, List<int> skillIds);

  Future<CourseUnit> addUnit(int courseId, UnitDraft draft);
  Future<CourseUnit> updateUnit(int unitId, UnitDraft draft);
  Future<void> deleteUnit(int unitId);

  Future<LessonPlan> addPlan(int courseId, PlanDraft draft);
  Future<LessonPlan> updatePlan(int planId, PlanDraft draft);
  Future<void> deletePlan(int planId);

  /// `POST /courses/extract/estimate`: free, queues nothing. `cached` is
  /// for exactly this [guidance] ("คำแนะนำถึง AI", DESIGN §21.12).
  Future<KeyEstimate> estimate({
    required CourseDocumentPurpose purpose,
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  });

  /// `POST /courses/extract`: cached (done at once) or queued; [guidance]
  /// is echoed as `extraction.guidance`.
  Future<CourseExtraction> extract({
    required CourseDocumentPurpose purpose,
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  });

  /// `GET /document-extractions/{id}`, polled until done or failed.
  Future<CourseExtraction> extraction(int id);

  /// `POST /courses/import`.
  Future<Course> import(CourseImport data);

  /// `POST /skills`: a missing indicator under a standard, or a
  /// sub-indicator under an indicator (`source = teacher`, §20.2).
  Future<Skill> addIndicator({
    required int parentId,
    required String name,
    String? code,
  });
}

class ApiCoursesRepository implements CoursesRepository {
  ApiCoursesRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<Course>> list({int? classroomId}) async {
    final res = await _dio.get<Object?>(
      '/courses',
      queryParameters: {'classroom_id': ?classroomId},
    );
    return unwrapList(res.data).map(Course.fromJson).toList();
  }

  @override
  Future<List<ClassroomCourse>> taughtIn(int classroomId) async {
    final res = await _dio.get<Object?>('/classrooms/$classroomId/courses');
    return unwrapList(res.data).map(ClassroomCourse.fromJson).toList();
  }

  @override
  Future<void> unbind(int classroomId, int courseId) async {
    await _dio.delete<Object?>('/classrooms/$classroomId/courses/$courseId');
  }

  @override
  Future<Course> get(int id) async =>
      _course(await _dio.get<Object?>('/courses/$id'));

  @override
  Future<Course> create(
    CourseDraft draft, {
    List<int> classroomIds = const [],
    List<int> skillIds = const [],
  }) async => _course(
    await _dio.post<Object?>(
      '/courses',
      data: {
        ...draft.toJson(),
        'classroom_ids': classroomIds,
        'skill_ids': skillIds,
      },
    ),
  );

  @override
  Future<Course> update(int id, CourseDraft draft) async =>
      _course(await _dio.patch<Object?>('/courses/$id', data: draft.toJson()));

  @override
  Future<void> delete(int id) async {
    await _dio.delete<Object?>('/courses/$id');
  }

  @override
  Future<Course> setClassrooms(int id, List<int> classroomIds) async => _course(
    await _dio.put<Object?>(
      '/courses/$id/classrooms',
      data: {'classroom_ids': classroomIds},
    ),
  );

  @override
  Future<Course> setIndicators(int id, List<int> skillIds) async => _course(
    await _dio.put<Object?>(
      '/courses/$id/indicators',
      data: {'skill_ids': skillIds},
    ),
  );

  @override
  Future<CourseUnit> addUnit(int courseId, UnitDraft draft) async {
    final res = await _dio.post<Object?>(
      '/courses/$courseId/units',
      data: draft.toJson(),
    );
    return CourseUnit.fromJson(unwrapJson(res.data));
  }

  @override
  Future<CourseUnit> updateUnit(int unitId, UnitDraft draft) async {
    final res = await _dio.patch<Object?>(
      '/units/$unitId',
      data: draft.toJson(),
    );
    return CourseUnit.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteUnit(int unitId) async {
    await _dio.delete<Object?>('/units/$unitId');
  }

  @override
  Future<LessonPlan> addPlan(int courseId, PlanDraft draft) async {
    final res = await _dio.post<Object?>(
      '/courses/$courseId/lesson-plans',
      data: draft.toJson(),
    );
    return LessonPlan.fromJson(unwrapJson(res.data));
  }

  @override
  Future<LessonPlan> updatePlan(int planId, PlanDraft draft) async {
    final res = await _dio.patch<Object?>(
      '/lesson-plans/$planId',
      data: draft.toJson(),
    );
    return LessonPlan.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deletePlan(int planId) async {
    await _dio.delete<Object?>('/lesson-plans/$planId');
  }

  @override
  Future<KeyEstimate> estimate({
    required CourseDocumentPurpose purpose,
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    final res = await _dio.post<Object?>(
      '/courses/extract/estimate',
      data: _selection(purpose, documentIds, pageFrom, pageTo, guidance),
    );
    return KeyEstimate.fromJson(unwrapJson(res.data));
  }

  @override
  Future<CourseExtraction> extract({
    required CourseDocumentPurpose purpose,
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    final res = await _dio.post<Object?>(
      '/courses/extract',
      data: _selection(purpose, documentIds, pageFrom, pageTo, guidance),
    );
    return CourseExtraction.fromJson(unwrapJson(res.data));
  }

  @override
  Future<CourseExtraction> extraction(int id) async {
    final res = await _dio.get<Object?>('/document-extractions/$id');
    return CourseExtraction.fromJson(unwrapJson(res.data));
  }

  @override
  Future<Course> import(CourseImport data) async =>
      _course(await _dio.post<Object?>('/courses/import', data: data.toJson()));

  @override
  Future<Skill> addIndicator({
    required int parentId,
    required String name,
    String? code,
  }) async {
    final res = await _dio.post<Object?>(
      '/skills',
      data: {
        'parent_id': parentId,
        'name': name,
        if (code != null && code.trim().isNotEmpty) 'code': code.trim(),
      },
    );
    return Skill.fromJson(unwrapJson(res.data));
  }

  static Course _course(Response<Object?> res) =>
      Course.fromJson(unwrapJson(res.data));

  static Map<String, dynamic> _selection(
    CourseDocumentPurpose purpose,
    List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  ) => {
    'purpose': purpose.apiValue,
    'document_ids': documentIds,
    if (pageFrom != null && pageTo != null) ...{
      'page_from': pageFrom,
      'page_to': pageTo,
    },
    'guidance': ?normalizeGuidance(guidance),
  };
}

final coursesRepositoryProvider = Provider<CoursesRepository>(
  (ref) => ApiCoursesRepository(ref.watch(dioProvider)),
);
