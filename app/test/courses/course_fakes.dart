import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/courses/indicator_widgets.dart';
import 'package:eduvision/features/gradebook/gradebook_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../gradebook/gradebook_fakes.dart';

const fraction = Skill(
  id: 1,
  code: 'ค 1.1 ป.5/1',
  name: 'บวกลบเศษส่วน',
  subjectId: 1,
  gradeLevel: 5,
  level: 'indicator',
);
const decimal = Skill(
  id: 2,
  code: 'ค 1.1 ป.5/2',
  name: 'ทศนิยม',
  subjectId: 1,
  gradeLevel: 5,
  level: 'indicator',
);
const schoolSkill = Skill(
  id: 90,
  code: 'ค 1.1 ป.5/ค1',
  name: 'เศษส่วนในชีวิตประจำวัน',
  subjectId: 1,
  gradeLevel: 5,
  level: 'indicator',
  sourceLabel: 'ครูเพิ่มเอง',
);

/// A course as `GET /courses/{id}` answers it.
Map<String, dynamic> courseJson({
  int id = 4,
  String code = 'ค15101',
  String name = 'คณิตศาสตร์ 5',
  List<Map<String, dynamic>> classrooms = const [
    {'id': 7, 'name': 'ป.5/1'},
  ],
  List<Map<String, dynamic>> indicators = const [],
  List<Map<String, dynamic>> units = const [],
  List<Map<String, dynamic>> plans = const [],
  int assignmentCount = 0,
}) => {
  'id': id,
  'code': code,
  'name': name,
  'subject_id': 1,
  'subject': {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
  'grade_level': 5,
  'semester': 1,
  'academic_year': 2569,
  'hours': 160,
  'description': null,
  'classroom_ids': [for (final c in classrooms) c['id']],
  'classrooms': classrooms,
  'indicator_count': indicators.length,
  'unit_count': units.length,
  'lesson_plan_count': plans.length,
  'assignment_count': assignmentCount,
  'indicators': indicators,
  'units': units,
  'lesson_plans': plans,
};

Map<String, dynamic> skillJson(Skill s) => {
  'id': s.id,
  'code': s.code,
  'name': s.name,
  'subject_id': s.subjectId,
  'grade_level': s.gradeLevel,
  'level': s.level ?? 'indicator',
  'source_label': s.sourceLabel,
};

Map<String, dynamic> unitJson(
  int id, {
  int position = 1,
  String title = 'เศษส่วน',
  List<Skill> indicators = const [],
}) => {
  'id': id,
  'course_id': 4,
  'position': position,
  'title': title,
  'hours': 12,
  'description': null,
  'indicators': [for (final s in indicators) skillJson(s)],
};

Map<String, dynamic> planJson(
  int id, {
  int? unitId,
  int position = 1,
  String title = 'การบวกเศษส่วน',
  String? taughtOn,
  List<Skill> indicators = const [],
}) => {
  'id': id,
  'course_id': 4,
  'unit_id': unitId,
  'position': position,
  'title': title,
  'hours': 2,
  'objectives': 'บวกเศษส่วนได้',
  'content': null,
  'activities': null,
  'assessment': null,
  'taught_on': taughtOn,
  'indicators': [for (final s in indicators) skillJson(s)],
};

Course course({
  int id = 4,
  List<Map<String, dynamic>> classrooms = const [
    {'id': 7, 'name': 'ป.5/1'},
  ],
  List<Skill> indicators = const [],
  List<Map<String, dynamic>> units = const [],
  List<Map<String, dynamic>> plans = const [],
  int assignmentCount = 0,
  String code = 'ค15101',
}) => Course.fromJson(
  courseJson(
    id: id,
    code: code,
    classrooms: classrooms,
    indicators: [for (final s in indicators) skillJson(s)],
    units: units,
    plans: plans,
    assignmentCount: assignmentCount,
  ),
);

/// In-memory [CoursesRepository] that records what the app sent.
class FakeCoursesRepository extends Fake implements CoursesRepository {
  FakeCoursesRepository([List<Course> courses = const []])
    : courses = [...courses];

  final List<Course> courses;
  final calls = <String>[];
  final List<CourseDraft> createdDrafts = [];
  final List<List<int>> createdClassrooms = [];
  final List<List<int>> createdSkills = [];
  final List<(int, List<int>)> indicatorSets = [];
  final List<(int, List<int>)> classroomSets = [];
  final List<(int?, UnitDraft)> unitSaves = [];
  final List<(int?, PlanDraft)> planSaves = [];
  final List<CourseImport> imports = [];
  final List<Map<String, Object?>> addedIndicators = [];
  final List<Map<String, Object?>> estimates = [];
  final List<Map<String, Object?>> extracts = [];
  Object? deleteError;

  /// Answers of `extraction(id)`, in order (the last one repeats).
  List<CourseExtraction> polls = [];
  CourseExtraction? extractResult;
  KeyEstimate estimateResult = const KeyEstimate(
    pages: 3,
    cached: false,
    estimate: CostEstimate(inputTokens: 1680, outputTokens: 16384, thb: 0.52),
  );
  int _nextId = 500;

  @override
  Future<List<Course>> list({int? classroomId}) async {
    calls.add('list:$classroomId');
    return [
      for (final c in courses)
        if (classroomId == null || c.classroomIds.contains(classroomId)) c,
    ];
  }

  /// Courses of other teachers taught in a room (DESIGN §24.12 B), by room.
  Map<int, List<ClassroomCourse>> otherTeachers = {};
  final List<(int, int)> unbound = [];
  Object? unbindError;

  @override
  Future<List<ClassroomCourse>> taughtIn(int classroomId) async {
    calls.add('taughtIn:$classroomId');
    return [
      for (final c in courses)
        if (c.classroomIds.contains(classroomId)) ClassroomCourse.own(c),
      ...?otherTeachers[classroomId],
    ];
  }

  @override
  Future<void> unbind(int classroomId, int courseId) async {
    calls.add('unbind:$classroomId:$courseId');
    if (unbindError case final e?) throw e;
    unbound.add((classroomId, courseId));
    otherTeachers[classroomId]?.removeWhere((c) => c.id == courseId);
    final i = courses.indexWhere((c) => c.id == courseId);
    if (i >= 0) {
      final c = courses[i];
      courses[i] = Course.fromJson({
        'id': c.id,
        'code': c.code,
        'name': c.name,
        'subject_id': c.subjectId,
        'grade_level': c.gradeLevel,
        'semester': c.semester,
        'academic_year': c.academicYear,
        'classrooms': [
          for (final r in c.classrooms)
            if (r.id != classroomId) {'id': r.id, 'name': r.name},
        ],
      });
    }
  }

  @override
  Future<Course> get(int id) async {
    calls.add('get:$id');
    return courses.firstWhere((c) => c.id == id);
  }

  @override
  Future<Course> create(
    CourseDraft draft, {
    List<int> classroomIds = const [],
    List<int> skillIds = const [],
  }) async {
    calls.add('create');
    createdDrafts.add(draft);
    createdClassrooms.add(classroomIds);
    createdSkills.add(skillIds);
    final created = Course(
      id: _nextId++,
      code: draft.code,
      name: draft.name,
      subjectId: draft.subjectId,
      gradeLevel: draft.gradeLevel,
      semester: draft.semester,
      academicYear: draft.academicYear,
      classrooms: [
        for (final id in classroomIds)
          CourseClassroom(id: id, name: 'ห้อง $id'),
      ],
    );
    courses.add(created);
    return created;
  }

  @override
  Future<Course> update(int id, CourseDraft draft) async {
    calls.add('update:$id');
    createdDrafts.add(draft);
    return courses.firstWhere((c) => c.id == id);
  }

  @override
  Future<void> delete(int id) async {
    calls.add('delete:$id');
    if (deleteError case final e?) throw e;
    courses.removeWhere((c) => c.id == id);
  }

  @override
  Future<Course> setClassrooms(int id, List<int> classroomIds) async {
    classroomSets.add((id, classroomIds));
    return courses.firstWhere((c) => c.id == id);
  }

  @override
  Future<Course> setIndicators(int id, List<int> skillIds) async {
    indicatorSets.add((id, skillIds));
    return courses.firstWhere((c) => c.id == id);
  }

  @override
  Future<CourseUnit> addUnit(int courseId, UnitDraft draft) async {
    unitSaves.add((null, draft));
    return CourseUnit(
      id: _nextId++,
      courseId: courseId,
      position: 1,
      title: draft.title,
    );
  }

  @override
  Future<CourseUnit> updateUnit(int unitId, UnitDraft draft) async {
    unitSaves.add((unitId, draft));
    return CourseUnit(id: unitId, courseId: 4, position: 1, title: draft.title);
  }

  @override
  Future<void> deleteUnit(int unitId) async => calls.add('deleteUnit:$unitId');

  @override
  Future<LessonPlan> addPlan(int courseId, PlanDraft draft) async {
    planSaves.add((null, draft));
    return LessonPlan(
      id: _nextId++,
      courseId: courseId,
      position: 1,
      title: draft.title,
    );
  }

  @override
  Future<LessonPlan> updatePlan(int planId, PlanDraft draft) async {
    planSaves.add((planId, draft));
    return LessonPlan(id: planId, courseId: 4, position: 1, title: draft.title);
  }

  @override
  Future<void> deletePlan(int planId) async => calls.add('deletePlan:$planId');

  @override
  Future<KeyEstimate> estimate({
    required CourseDocumentPurpose purpose,
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    estimates.add({
      'purpose': purpose.apiValue,
      'document_ids': documentIds,
      'page_from': pageFrom,
      'page_to': pageTo,
      'guidance': guidance,
    });
    return estimateResult;
  }

  @override
  Future<CourseExtraction> extract({
    required CourseDocumentPurpose purpose,
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    extracts.add({
      'purpose': purpose.apiValue,
      'document_ids': documentIds,
      'guidance': guidance,
    });
    return extractResult!;
  }

  @override
  Future<CourseExtraction> extraction(int id) async {
    calls.add('poll:$id');
    return polls.length > 1 ? polls.removeAt(0) : polls.single;
  }

  @override
  Future<Course> import(CourseImport data) async {
    imports.add(data);
    final saved = data.courseId == null
        ? Course(
            id: _nextId++,
            code: data.course!.code,
            name: data.course!.name,
            subjectId: data.course!.subjectId,
            gradeLevel: data.course!.gradeLevel,
            academicYear: data.course!.academicYear,
          )
        : courses.firstWhere((c) => c.id == data.courseId);
    if (data.courseId == null) courses.add(saved);
    return saved;
  }

  @override
  Future<Skill> addIndicator({
    required int parentId,
    required String name,
    String? code,
  }) async {
    addedIndicators.add({'parent_id': parentId, 'name': name, 'code': code});
    return Skill(
      id: 90,
      code: code ?? 'ค 1.1 ป.5/ค1',
      name: name,
      subjectId: 1,
      gradeLevel: 5,
      level: 'indicator',
      sourceLabel: 'ครูเพิ่มเอง',
    );
  }
}

class FakeClassrooms extends Fake implements ClassroomsRepository {
  FakeClassrooms({this.students = const []});

  /// The roster of every classroom.
  final List<RosterStudent> students;

  @override
  Future<List<RosterStudent>> roster(int id) async => students;

  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'AAA111',
    ),
    Classroom(
      id: 8,
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'BBB222',
    ),
  ];
}

/// Subjects and the skill search behind the pickers.
class FakeSkills extends Fake implements AssignmentsRepository {
  final searches = <Map<String, Object?>>[];

  /// Set to const [] for an installation without subject groups.
  List<Subject> subjectList = const [
    Subject(id: 1, code: 'ค', name: 'คณิตศาสตร์'),
    Subject(id: 2, code: 'ว', name: 'วิทยาศาสตร์'),
  ];

  @override
  Future<List<Subject>> subjects() async => subjectList;

  @override
  Future<Subject> createSubject(String name) async {
    final subject = Subject(id: 90, code: 'T1-1', name: name, isOwn: true);
    subjectList = [...subjectList, subject];
    return subject;
  }

  @override
  Future<List<Skill>> searchSkills({
    int? subjectId,
    int? grade,
    String? q,
    String? level,
  }) async {
    searches.add({
      'subject': subjectId,
      'grade': grade,
      'q': q,
      'level': level,
    });
    if (level == kParentLevels) {
      return const [
        Skill(id: 11, code: 'ค 1.1', name: 'มาตรฐาน ค 1.1', level: 'standard'),
      ];
    }
    return const [fraction, decimal, schoolSkill];
  }
}

List<Override> overrides(
  FakeCoursesRepository courses, {
  FakeSkills? skills,
  FakeGradebookRepository? gradebook,
  FakeClassrooms? classrooms,
}) => [
  coursesRepositoryProvider.overrideWithValue(courses),
  assignmentsRepositoryProvider.overrideWithValue(skills ?? FakeSkills()),
  classroomsRepositoryProvider.overrideWithValue(
    classrooms ?? FakeClassrooms(),
  ),
  gradebookRepositoryProvider.overrideWithValue(
    gradebook ?? FakeGradebookRepository(),
  ),
];

void tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

GoRoute stubRoute(String path, String label) => GoRoute(
  path: path,
  builder: (_, state) =>
      Scaffold(appBar: AppBar(), body: Text('$label ${state.uri}')),
);
