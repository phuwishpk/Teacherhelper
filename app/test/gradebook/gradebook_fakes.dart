import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/gradebook/gradebook_models.dart';
import 'package:eduvision/features/gradebook/gradebook_providers.dart';
import 'package:eduvision/features/gradebook/gradebook_repository.dart';
import 'package:flutter_riverpod/misc.dart';

DioException gradebookError(
  int status,
  String message, {
  String? code,
  Map<String, Object?> errors = const {},
}) {
  final options = RequestOptions(path: '/gradebook');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response(
      requestOptions: options,
      statusCode: status,
      data: {'message': message, 'code': ?code, 'errors': errors},
    ),
  );
}

/// The categories of the §23.5 template "การบ้าน 30, กลางภาค 20,
/// ปลายภาค 30, จิตพิสัย 20".
List<Map<String, dynamic>> fourCategories({int itemCount = 0}) => [
  {
    'id': 10,
    'position': 1,
    'name': 'การบ้าน',
    'weight': 30.0,
    'drop_lowest': 1,
    'is_homework_default': true,
    'item_count': itemCount,
  },
  {
    'id': 11,
    'position': 2,
    'name': 'กลางภาค',
    'weight': 20.0,
    'drop_lowest': 0,
    'is_homework_default': false,
    'item_count': 0,
  },
  {
    'id': 12,
    'position': 3,
    'name': 'ปลายภาค',
    'weight': 30.0,
    'drop_lowest': 0,
    'is_homework_default': false,
    'item_count': 0,
  },
  {
    'id': 13,
    'position': 4,
    'name': 'จิตพิสัย',
    'weight': 20.0,
    'drop_lowest': 0,
    'is_homework_default': false,
    'item_count': 2,
  },
];

Map<String, dynamic> settingsJson({
  bool configured = true,
  List<Map<String, dynamic>>? categories,
  List<int> cutoffs = kDefaultCutoffs,
  int uncategorised = 0,
}) => {
  'configured': configured,
  'template': configured ? 'hw_mid_final_affective' : null,
  'categories': configured ? (categories ?? fourCategories()) : const [],
  'cutoffs': cutoffs,
  'default_cutoffs': kDefaultCutoffs,
  'uncategorised_count': uncategorised,
};

const templatesJson = [
  {
    'key': 'collect_final',
    'name': 'คะแนนเก็บ 70 : ปลายภาค 30',
    'categories': [
      {'name': 'คะแนนเก็บ', 'weight': 70.0, 'is_homework_default': true},
      {'name': 'ปลายภาค', 'weight': 30.0, 'is_homework_default': false},
    ],
  },
  {
    'key': 'hw_mid_final_affective',
    'name': 'การบ้าน 30, กลางภาค 20, ปลายภาค 30, จิตพิสัย 20',
    'categories': [
      {'name': 'การบ้าน', 'weight': 30.0, 'is_homework_default': true},
      {'name': 'กลางภาค', 'weight': 20.0, 'is_homework_default': false},
      {'name': 'ปลายภาค', 'weight': 30.0, 'is_homework_default': false},
      {'name': 'จิตพิสัย', 'weight': 20.0, 'is_homework_default': false},
    ],
  },
];

Map<String, dynamic> cellJson(
  String state, {
  double? score,
  double? percent,
  bool dropped = false,
  int? submissionId,
}) => {
  'score': score,
  'percent': percent,
  'state': state,
  'dropped': dropped,
  'submission_id': ?submissionId,
};

/// A classroom grid in the shape of §23.11: homework (app), a practice
/// sheet ("ไม่นับเกรด"), a mid-term exam (app), a manual final exam, an
/// item and an attendance item; two students.
Map<String, dynamic> gridJson({
  bool complete = true,
  Map<String, dynamic>? publication,
  bool uncategorisedColumn = false,
  List<Map<String, dynamic>>? rows,
}) => {
  'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
  'classroom': {'id': 7, 'name': 'ป.5/1'},
  'configured': true,
  'complete': complete,
  'missing_categories': complete ? const [] : const ['ปลายภาค'],
  'counted_weight': complete ? 100.0 : 70.0,
  'categories': [
    {
      'id': 10,
      'name': 'การบ้าน',
      'weight': 30.0,
      'drop_lowest': 1,
      'has_items': true,
    },
    {
      'id': 11,
      'name': 'กลางภาค',
      'weight': 20.0,
      'drop_lowest': 0,
      'has_items': true,
    },
    {
      'id': 12,
      'name': 'ปลายภาค',
      'weight': 30.0,
      'drop_lowest': 0,
      'has_items': complete,
    },
    {
      'id': 13,
      'name': 'จิตพิสัย',
      'weight': 20.0,
      'drop_lowest': 0,
      'has_items': true,
    },
  ],
  'columns': [
    _column('a50', 'assignment', 50, 'การบ้าน 1', 10, 10, kind: 'homework'),
    _column('a52', 'assignment', 52, 'การบ้าน 2', 10, 10, kind: 'homework'),
    _column(
      'a53',
      'assignment',
      53,
      'งานฝึก',
      10,
      5,
      kind: 'homework',
      counted: false,
      excluded: true,
    ),
    _column('a54', 'assignment', 54, 'สอบกลางภาค', 11, 40, kind: 'exam'),
    _column(
      'a51',
      'manual_exam',
      51,
      'สอบปลายภาค',
      12,
      50,
      kind: 'exam',
      counted: complete,
      editable: true,
    ),
    _column('i60', 'custom', 60, 'การแต่งกาย', 13, 10, editable: true),
    _column(
      'i61',
      'custom',
      61,
      'การเข้าเรียน',
      13,
      20,
      editable: true,
      attendance: true,
    ),
    if (uncategorisedColumn)
      _column(
        'a55',
        'assignment',
        55,
        'ใบงานเก่า',
        null,
        10,
        kind: 'homework',
        counted: false,
      ),
  ],
  'rows':
      rows ??
      [
        {
          'student_id': 101,
          'student_number': 1,
          'name': 'ด.ญ. เอ',
          'left_course': false,
          'cells': {
            'a50': cellJson('scored', score: 8, percent: 80, submissionId: 900),
            'a52': cellJson('missing', percent: 0, dropped: true),
            'a53': cellJson('not_counted', score: 4),
            'a54': cellJson('pending', submissionId: 901),
            'a51': complete
                ? cellJson('scored', score: 33, percent: 66)
                : cellJson('not_due'),
            'i60': cellJson('scored', score: 10, percent: 100),
            'i61': cellJson('scored', score: 18, percent: 90),
          },
          'categories': {
            '10': {'percent': 80.0, 'points': 24.0},
            '11': {'percent': null, 'points': null},
            '12': complete
                ? {'percent': 66.0, 'points': 19.8}
                : {'percent': null, 'points': null},
            '13': {'percent': 95.0, 'points': 19.0},
          },
          'total': complete ? 73.8 : 77.14,
          'total_rounded': complete ? 74 : null,
          'grade': complete ? 3.0 : null,
          'special': null,
          'special_note': null,
          'attendance_warning': false,
          'in_progress': !complete,
          'counted_weight': complete ? 80.0 : 50.0,
        },
        {
          'student_id': 102,
          'student_number': 2,
          'name': 'ด.ช. บี',
          'left_course': false,
          'cells': {
            'a50': cellJson('excused'),
            'a52': cellJson('not_due'),
            'a53': cellJson('not_counted'),
            'a54': cellJson(
              'scored',
              score: 30,
              percent: 75,
              submissionId: 902,
            ),
            'a51': complete
                ? cellJson('missing', percent: 0)
                : cellJson('not_due'),
            'i60': cellJson('missing', percent: 0),
            'i61': cellJson('scored', score: 15, percent: 75),
          },
          'categories': {
            '10': {'percent': null, 'points': null},
            '11': {'percent': 75.0, 'points': 15.0},
            '12': complete
                ? {'percent': 0.0, 'points': 0.0}
                : {'percent': null, 'points': null},
            '13': {'percent': 37.5, 'points': 7.5},
          },
          'total': complete ? 31.43 : 32.5,
          'total_rounded': complete ? 31 : null,
          'grade': null,
          'special': 'ms',
          'special_note': 'ขาดเรียนเกิน',
          'attendance_warning': true,
          'in_progress': !complete,
          'counted_weight': 70.0,
        },
      ],
  'publication': publication,
};

Map<String, dynamic> _column(
  String key,
  String type,
  int id,
  String name,
  int? categoryId,
  double full, {
  String? kind,
  bool counted = true,
  bool excluded = false,
  bool editable = false,
  bool attendance = false,
}) => {
  'key': key,
  'type': type,
  'id': id,
  'name': name,
  'kind': kind,
  'category_id': categoryId,
  'full_marks': full,
  'counted': counted,
  'due_at': '2026-09-01T16:59:00+00:00',
  'excluded_from_grade': excluded,
  'is_attendance': attendance,
  'editable': editable,
};

Map<String, dynamic> myGradeJson() => {
  'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
  'classroom_id': 7,
  'classroom': {
    'id': 7,
    'name': 'ป.5/1',
    'academic_year': 2569,
    'closed': false,
  },
  'published_at': '2026-09-30T03:00:00+00:00',
  'grade': 3.0,
  'special': null,
  'total': 73.8,
  'total_rounded': 74,
  'breakdown': [
    {
      'category_id': 10,
      'name': 'การบ้าน',
      'weight': 30.0,
      'percent': 73.3333,
      'points': 22.0,
      'items': [
        {
          'name': 'การบ้าน 1',
          'score': 8.0,
          'max': 10.0,
          'percent': 80.0,
          'state': 'scored',
          'dropped': false,
        },
        {
          'name': 'การบ้าน 4',
          'score': null,
          'max': 10.0,
          'percent': 0.0,
          'state': 'missing',
          'dropped': true,
        },
        {
          'name': 'การบ้าน 5',
          'score': null,
          'max': 10.0,
          'percent': null,
          'state': 'excused',
          'dropped': false,
        },
      ],
    },
    {
      'category_id': 12,
      'name': 'ปลายภาค',
      'weight': 30.0,
      'percent': null,
      'points': null,
      'items': const [],
    },
  ],
};

Map<String, dynamic> overviewRoomJson(
  int id,
  String name,
  String status, {
  int students = 30,
  List<String> empty = const [],
  String? publishedAt,
  int atRisk = 0,
  int r = 0,
  int ms = 0,
}) => {
  'id': id,
  'name': name,
  'student_count': students,
  'status': status,
  'empty_categories': empty,
  'published_at': publishedAt,
  'stale': status == 'published_stale',
  'at_risk_ms_count': atRisk,
  'special_counts': {'ร': r, 'มส': ms},
};

Map<String, dynamic> overviewCourseJson({
  int id = 4,
  String code = 'ค15101',
  String name = 'คณิตศาสตร์ 5',
  int semester = 1,
  int year = 2569,
  bool configured = true,
  List<Map<String, dynamic>> classrooms = const [],
}) => {
  'id': id,
  'code': code,
  'name': name,
  'grade_level': 5,
  'semester': semester,
  'academic_year': year,
  'configured': configured,
  'category_count': configured ? 4 : 0,
  'classrooms': classrooms,
};

/// `GET /gradebook/overview` of a teacher with every classroom status:
/// two courses in 2569 (semesters 1 and 2), one in 2568.
List<Map<String, dynamic>> overviewJson() => [
  overviewCourseJson(
    classrooms: [
      overviewRoomJson(
        7,
        'ป.5/1',
        'missing_scores',
        empty: ['กลางภาค', 'ปลายภาค'],
        atRisk: 2,
        r: 1,
        ms: 1,
      ),
      overviewRoomJson(8, 'ป.5/2', 'ready', students: 28),
      overviewRoomJson(
        9,
        'ป.5/3',
        'published',
        publishedAt: '2026-09-30T03:00:00+00:00',
      ),
      overviewRoomJson(
        10,
        'ป.5/4',
        'published_stale',
        publishedAt: '2026-09-29T03:00:00+00:00',
      ),
    ],
  ),
  overviewCourseJson(
    id: 5,
    code: 'ว15101',
    name: 'วิทยาศาสตร์ 5',
    semester: 2,
    configured: false,
    classrooms: [overviewRoomJson(7, 'ป.5/1', 'not_configured')],
  ),
  overviewCourseJson(
    id: 6,
    code: 'ค14101',
    name: 'คณิตศาสตร์ 4',
    year: 2568,
    classrooms: [overviewRoomJson(11, 'ป.4/1', 'ready')],
  ),
];

/// In-memory [GradebookRepository]: records every call as (name, args).
class FakeGradebookRepository implements GradebookRepository {
  FakeGradebookRepository({
    Map<String, dynamic>? settings,
    Map<String, dynamic>? grid,
  }) : settingsBody = settings ?? settingsJson(configured: false),
       gridBody = grid ?? gridJson();

  Map<String, dynamic> settingsBody;
  Map<String, dynamic> gridBody;
  List<Map<String, dynamic>> myGradesBody = [
    {
      'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
      'classroom_id': 7,
      'classroom': {
        'id': 7,
        'name': 'ป.5/1',
        'academic_year': 2569,
        'closed': false,
      },
      'published_at': '2026-09-30T03:00:00+00:00',
      'grade': 3.0,
      'special': null,
      'total_rounded': 74,
    },
    {
      'course': {'id': 5, 'code': 'ว15101', 'name': 'วิทยาศาสตร์ 5'},
      'classroom_id': 7,
      'published_at': '2026-09-29T03:00:00+00:00',
      'grade': null,
      'special': 'r',
      'total_rounded': 58,
    },
  ];
  Map<String, dynamic>? myGradeBody = myGradeJson();
  List<Map<String, dynamic>> overviewBody = overviewJson();

  /// Thrown by the next `overview()` instead of answering.
  Object? failOverview;
  CsvExport csv = CsvExport(
    fileName: 'gradebook-ค15101-ป.5/1.csv',
    bytes: Uint8List.fromList(utf8.encode('﻿เลขที่,ชื่อ\r\n')),
  );

  final calls = <(String, Object?)>[];

  /// The `classroom_id` of each `myCourseGrade` call.
  final gradeClassroomIds = <int?>[];

  /// Thrown by the next mutating call instead of answering.
  Object? failNext;

  List<Object?> args(String name) => [
    for (final (n, a) in calls)
      if (n == name) a,
  ];

  void _check() {
    final f = failNext;
    if (f != null) {
      failNext = null;
      throw f;
    }
  }

  @override
  Future<List<GradebookTemplate>> templates() async =>
      templatesJson.map(GradebookTemplate.fromJson).toList();

  @override
  Future<List<GradebookOverviewCourse>> overview({
    int? academicYear,
    int? semester,
  }) async {
    calls.add(('overview', (academicYear, semester)));
    final f = failOverview;
    if (f != null) {
      failOverview = null;
      throw f;
    }
    return overviewBody.map(GradebookOverviewCourse.fromJson).toList();
  }

  @override
  Future<GradebookSettings> settings(int courseId) async {
    calls.add(('settings', courseId));
    return GradebookSettings.fromJson(settingsBody);
  }

  @override
  Future<GradebookSettings> applyTemplate(int courseId, String template) async {
    calls.add(('applyTemplate', template));
    _check();
    settingsBody = settingsJson();
    return GradebookSettings.fromJson(settingsBody);
  }

  @override
  Future<GradebookSettings> saveCategories(
    int courseId,
    List<CategoryDraft> categories,
  ) async {
    calls.add(('saveCategories', [for (final c in categories) c.toJson()]));
    _check();
    return GradebookSettings.fromJson(settingsBody);
  }

  @override
  Future<GradebookSettings> saveCutoffs(
    int courseId,
    List<int>? cutoffs,
  ) async {
    calls.add(('saveCutoffs', cutoffs));
    _check();
    return GradebookSettings.fromJson(settingsBody);
  }

  @override
  Future<GradebookGrid> grid(int courseId, int classroomId) async {
    calls.add(('grid', (courseId, classroomId)));
    return GradebookGrid.fromJson(gridBody);
  }

  @override
  Future<List<GradebookItem>> addItem(
    int courseId,
    GradebookItemDraft draft,
  ) async {
    calls.add(('addItem', draft.toCreateJson()));
    _check();
    return [
      for (final id in draft.classroomIds)
        GradebookItem(
          id: 70 + id,
          classroomId: id,
          name: draft.name,
          maxPoints: draft.maxPoints,
          categoryId: draft.categoryId,
        ),
    ];
  }

  @override
  Future<GradebookItem> updateItem(int itemId, GradebookItemDraft draft) async {
    calls.add(('updateItem', {'id': itemId, ...draft.toUpdateJson()}));
    _check();
    return GradebookItem(
      id: itemId,
      classroomId: 7,
      name: draft.name,
      maxPoints: draft.maxPoints,
    );
  }

  @override
  Future<void> deleteItem(int itemId) async {
    calls.add(('deleteItem', itemId));
    _check();
  }

  @override
  Future<void> saveScores(
    GradebookColumn column,
    List<ScoreChange> changes,
  ) async {
    calls.add((
      'saveScores',
      {
        'key': column.key,
        'scores': [for (final c in changes) c.toJson()],
      },
    ));
    _check();
  }

  @override
  Future<int> fillFull(GradebookColumn column) async {
    calls.add(('fillFull', column.key));
    _check();
    return 2;
  }

  @override
  Future<void> setSpecialGrade(
    int courseId, {
    required int classroomId,
    required int studentId,
    String? special,
    String? note,
  }) async {
    calls.add((
      'setSpecialGrade',
      {
        'classroom_id': classroomId,
        'student_id': studentId,
        'special': special,
        'note': note,
      },
    ));
    _check();
  }

  @override
  Future<PublishResult> publish(int courseId, int classroomId) async {
    calls.add(('publish', classroomId));
    _check();
    return const PublishResult(publicationId: 3, studentCount: 2);
  }

  @override
  Future<void> withdraw(int courseId, int classroomId) async {
    calls.add(('withdraw', classroomId));
    _check();
  }

  @override
  Future<CsvExport> exportCsv(int courseId, int classroomId) async {
    calls.add(('exportCsv', classroomId));
    _check();
    return csv;
  }

  @override
  Future<List<StudentGradeSummary>> myGrades() async =>
      myGradesBody.map(StudentGradeSummary.fromJson).toList();

  @override
  Future<StudentGradeDetail> myCourseGrade(
    int courseId, {
    int? classroomId,
  }) async {
    calls.add(('myCourseGrade', courseId));
    gradeClassroomIds.add(classroomId);
    final body = myGradeBody;
    if (body == null) throw gradebookError(404, 'ไม่พบ', code: 'not_found');
    return StudentGradeDetail.fromJson(body);
  }
}

/// Records what the share sheet would have been given.
class FakeFileSharer extends GradebookFileSharer {
  FakeFileSharer();

  final shared = <(CsvExport, String)>[];

  @override
  Future<void> share(CsvExport file, {required String subject}) async {
    shared.add((file, subject));
  }
}

List<Override> gradebookOverrides(
  FakeGradebookRepository repo, {
  FakeFileSharer? sharer,
}) => [
  gradebookRepositoryProvider.overrideWithValue(repo),
  gradebookFileSharerProvider.overrideWithValue(sharer ?? FakeFileSharer()),
];
