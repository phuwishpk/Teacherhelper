import 'package:eduvision/features/mastery/course_mastery.dart';

import '../helpers/fake_charts.dart';

Map<String, dynamic> _indicator(
  int id,
  String code,
  double? value, {
  required bool classroom,
}) => classroom
    ? {
        'skill': chartSkill(id, code),
        'value': value,
        'assessed_students': value == null ? 0 : 2,
        'passed_students': value == null ? 0 : (value >= 0.5 ? 2 : 1),
      }
    : {
        'skill': chartSkill(id, code),
        'value': value,
        'n_obs': value == null ? 0 : 3,
        'level': null,
        'passed': value == null ? null : value >= 0.5,
      };

Map<String, dynamic> _node(
  String type,
  int? id,
  String title,
  List<Map<String, dynamic>> indicators, {
  String? code,
  int? position,
}) {
  final values = [
    for (final i in indicators)
      if (i['value'] != null) (i['value'] as num).toDouble(),
  ];
  return {
    'type': type,
    'id': id,
    'code': code,
    'title': title,
    'position': position,
    'value': values.isEmpty
        ? null
        : values.reduce((a, b) => a + b) / values.length,
    'assessed': values.length,
    'planned': indicators.length,
    'coverage': indicators.isEmpty ? null : values.length / indicators.length,
    'indicators': indicators,
  };
}

/// A roll-up of course 4: by standard 3 assessed axes (radar), by unit 2
/// (bars). Skills 1–4 assessed, 5 not.
Map<String, dynamic> summaryJson({
  MasteryAxis axis = MasteryAxis.standard,
  bool classroom = false,
}) {
  Map<String, dynamic> ind(int id, String code, double? v) =>
      _indicator(id, code, v, classroom: classroom);
  final nodes = axis == MasteryAxis.standard
      ? [
          _node('standard', 11, 'เศษส่วน', [
            ind(1, 'ค 1.1 ป.5/1', 0.72),
            ind(2, 'ค 1.1 ป.5/2', 1.0),
          ], code: 'ค 1.1'),
          _node('standard', 12, 'ทศนิยม', [
            ind(3, 'ค 1.2 ป.5/1', 0.3),
          ], code: 'ค 1.2'),
          _node('standard', 13, 'ร้อยละ', [
            ind(4, 'ค 1.3 ป.5/1', 0.5),
          ], code: 'ค 1.3'),
          _node('other', null, 'ไม่มีมาตรฐาน', [ind(5, 'ค 9 ครู', null)]),
        ]
      : [
          _node('unit', 20, 'เศษส่วน', [
            ind(1, 'ค 1.1 ป.5/1', 0.72),
            ind(2, 'ค 1.1 ป.5/2', 1.0),
          ], position: 1),
          _node('unit', 21, 'ยังไม่วางแผน', const [], position: 2),
          _node('other', null, 'ไม่อยู่ในหน่วย', [
            ind(3, 'ค 1.2 ป.5/1', 0.3),
            ind(5, 'ค 9 ครู', null),
          ]),
        ];
  return {
    'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
    'axis': axis.apiValue,
    'scope': classroom ? 'classroom' : 'student',
    'pass_threshold': 0.5,
    'classroom_id': 7,
    if (!classroom) 'student_id': 55,
    'summary': {
      'value': 0.63,
      'assessed': 4,
      'planned': 5,
      'coverage': 0.8,
      if (classroom) ...{'students_assessed': 2, 'student_count': 3},
      if (!classroom) 'passed': 3,
    },
    'nodes': nodes,
    if (classroom)
      'students': [
        {
          'id': 55,
          'name': 'เด็กชายเอ',
          'student_number': 1,
          'value': 0.63,
          'assessed': 4,
          'planned': 5,
          'coverage': 0.8,
          'passed': 3,
        },
        {
          'id': 56,
          'name': 'เด็กหญิงบี',
          'student_number': 2,
          'value': null,
          'assessed': 0,
          'planned': 5,
          'coverage': 0,
          'passed': 0,
        },
      ],
  };
}

typedef SummaryCall = ({
  int courseId,
  int? classroomId,
  int? studentId,
  MasteryAxis axis,
  bool own,
});

class FakeCourseMastery implements CourseMasteryRepository {
  final calls = <SummaryCall>[];

  @override
  Future<CourseMasterySummary> summary(
    int courseId, {
    int? classroomId,
    int? studentId,
    MasteryAxis axis = MasteryAxis.standard,
  }) async {
    calls.add((
      courseId: courseId,
      classroomId: classroomId,
      studentId: studentId,
      axis: axis,
      own: false,
    ));
    return CourseMasterySummary.fromJson(
      summaryJson(axis: axis, classroom: studentId == null),
    );
  }

  @override
  Future<List<StudentCourse>> myCourses() async => [
    StudentCourse.fromJson({
      'id': 4,
      'code': 'ค15101',
      'name': 'คณิตศาสตร์ 5',
      'subject': {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
      'classroom_ids': [7],
    }),
  ];

  @override
  Future<CourseMasterySummary> mySummary(
    int courseId, {
    MasteryAxis axis = MasteryAxis.standard,
  }) async {
    calls.add((
      courseId: courseId,
      classroomId: null,
      studentId: null,
      axis: axis,
      own: true,
    ));
    return CourseMasterySummary.fromJson(summaryJson(axis: axis));
  }
}

/// A student roll-up by standard with one node per entry of
/// [assessedPerNode]: node i is standard "ค 1.(i+1)" holding that many
/// assessed indicators plus [unassessedPerNode] not yet assessed.
Map<String, dynamic> summaryWithNodes(
  List<int> assessedPerNode, {
  int unassessedPerNode = 0,
}) {
  var id = 100;
  return {
    ...summaryJson(),
    'nodes': [
      for (final (i, n) in assessedPerNode.indexed)
        _node('standard', 10 + i, 'มาตรฐาน ${i + 1}', [
          for (var j = 0; j < n + unassessedPerNode; j++)
            _indicator(
              id++,
              'ค 1.${i + 1} ป.5/${j + 1}',
              j < n ? 0.4 + 0.05 * j : null,
              classroom: false,
            ),
        ], code: 'ค 1.${i + 1}'),
    ],
  };
}
