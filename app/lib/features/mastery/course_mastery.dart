import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../assignments/question.dart';
import '../student/student_labels.dart';
import 'mastery_models.dart';

double? _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

int? _int(Object? v) => switch (v) {
  num n => n.toInt(),
  String s => int.tryParse(s),
  _ => null,
};

/// The axis of a course roll-up (DESIGN §20.3, §20.4): one node per
/// standard, or one per unit.
enum MasteryAxis {
  standard('standard', 'ตามมาตรฐาน'),
  unit('unit', 'ตามหน่วย');

  const MasteryAxis(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static MasteryAxis fromApi(Object? value) =>
      value == 'unit' ? unit : standard;
}

/// The radar chart needs this many assessed axes; otherwise the chart falls
/// back to the assessed indicators as axes, then to bars (DESIGN §20.4).
const kRadarMinAxes = 3;
const kRadarMaxAxes = 12;

/// Whether [n] axes fit a radar (3–12).
bool radarFits(int n) => n >= kRadarMinAxes && n <= kRadarMaxAxes;

/// What the spider chart of a roll-up draws (DESIGN §20.4).
enum RollupChartMode {
  /// A radar of the assessed top-level nodes (standards or units).
  nodes,

  /// A radar of the assessed indicators of every node: fewer than 3
  /// top-level nodes are assessed, but 3–12 indicators are.
  indicators,

  /// Bars of every planned node.
  bars,
}

/// One indicator axis of the fallback radar, with the node it sits under
/// (tapping the axis opens that node's drill-down).
typedef IndicatorAxis = ({RollupNode node, RollupIndicator indicator});

/// "ประเมินแล้ว x/y ตัวชี้วัด" under every chart (DESIGN §20.3).
String coverageLabel(int assessed, int planned) =>
    'ประเมินแล้ว $assessed/$planned ตัวชี้วัด';

/// One indicator inside a node, for the drill-down. A student's has
/// [value], [nObs] and [passed]; a classroom's has [value] (the mean of the
/// students assessed), [assessedStudents] and [passedStudents].
class RollupIndicator {
  const RollupIndicator({
    required this.skill,
    this.value,
    this.nObs = 0,
    this.passed,
    this.assessedStudents,
    this.passedStudents,
  });

  final Skill skill;

  /// Null: not assessed yet ("ยังไม่ได้ประเมิน").
  final double? value;
  final int nObs;
  final bool? passed;
  final int? assessedStudents;
  final int? passedStudents;

  bool get assessed => value != null;

  /// The §11.7 level of a student's value (null for a classroom or when
  /// not assessed).
  MasteryLevel? get level => value == null || assessedStudents != null
      ? null
      : MasteryLevel.of(value!, nObs);

  factory RollupIndicator.fromJson(Map<String, dynamic> json) =>
      RollupIndicator(
        skill: Skill.fromJson((json['skill'] as Map).cast<String, dynamic>()),
        value: _double(json['value']),
        nObs: _int(json['n_obs']) ?? 0,
        passed: json['passed'] is bool ? json['passed'] as bool : null,
        assessedStudents: _int(json['assessed_students']),
        passedStudents: _int(json['passed_students']),
      );
}

/// A node of the roll-up (a standard, a unit, `other`, or the course as
/// `summary`): `value(node)` with its coverage (DESIGN §20.3).
class RollupNode {
  const RollupNode({
    required this.type,
    required this.title,
    this.id,
    this.code,
    this.position,
    this.value,
    this.assessed = 0,
    this.planned = 0,
    this.coverage,
    this.passed,
    this.studentsAssessed,
    this.studentCount,
    this.indicators = const [],
  });

  /// `standard`, `unit`, `other` or `course` (the summary).
  final String type;
  final int? id;
  final String? code;
  final String title;
  final int? position;

  /// Null: no indicator under the node is assessed yet.
  final double? value;

  /// Indicators assessed (for a classroom: by at least one student).
  final int assessed;
  final int planned;

  /// assessed / planned; null when nothing is planned under the node.
  final double? coverage;

  /// A student's indicators at or above the pass mark.
  final int? passed;

  /// A classroom's students who have a value for the node.
  final int? studentsAssessed;
  final int? studentCount;
  final List<RollupIndicator> indicators;

  bool get isOther => type == 'other';

  /// The short label of a chart axis: the standard's code, else the title.
  String get label => code ?? title;

  String get coverageText => coverageLabel(assessed, planned);

  factory RollupNode.fromJson(
    Map<String, dynamic> json, {
    String type = 'course',
    String title = '',
  }) => RollupNode(
    type: json['type'] as String? ?? type,
    id: _int(json['id']),
    code: json['code'] as String?,
    title: json['title'] as String? ?? title,
    position: _int(json['position']),
    value: _double(json['value']),
    assessed: _int(json['assessed']) ?? 0,
    planned: _int(json['planned']) ?? 0,
    coverage: _double(json['coverage']),
    passed: _int(json['passed']),
    studentsAssessed: _int(json['students_assessed']),
    studentCount: _int(json['student_count']),
    indicators: [
      for (final i in (json['indicators'] as List?) ?? const [])
        if (i is Map && i['skill'] is Map)
          RollupIndicator.fromJson(i.cast<String, dynamic>()),
    ],
  );
}

/// A student's course value in a classroom roll-up (teacher only).
class RollupStudent {
  const RollupStudent({
    required this.id,
    required this.name,
    this.studentNumber,
    this.value,
    this.assessed = 0,
    this.planned = 0,
    this.coverage,
    this.passed = 0,
  });

  final int id;
  final String name;
  final int? studentNumber;
  final double? value;
  final int assessed;
  final int planned;
  final double? coverage;
  final int passed;

  factory RollupStudent.fromJson(Map<String, dynamic> json) => RollupStudent(
    id: _int(json['id'])!,
    name: json['name'] as String? ?? '',
    studentNumber: _int(json['student_number']),
    value: _double(json['value']),
    assessed: _int(json['assessed']) ?? 0,
    planned: _int(json['planned']) ?? 0,
    coverage: _double(json['coverage']),
    passed: _int(json['passed']) ?? 0,
  );
}

/// `GET /courses/{id}/mastery-summary` (teacher) or
/// `GET /student/courses/{id}/mastery-summary` (a student's own), DESIGN
/// §20.3, §20.7.
class CourseMasterySummary {
  const CourseMasterySummary({
    required this.courseId,
    required this.courseCode,
    required this.courseName,
    required this.axis,
    required this.scope,
    required this.passThreshold,
    required this.summary,
    this.classroomId,
    this.studentId,
    this.nodes = const [],
    this.students = const [],
  });

  final int courseId;
  final String courseCode;
  final String courseName;
  final MasteryAxis axis;

  /// `student` or `classroom`.
  final String scope;

  /// MASTERY_PASS_THRESHOLD ("ผ่าน" = mastery ≥ this).
  final double passThreshold;
  final int? classroomId;
  final int? studentId;

  /// The course node.
  final RollupNode summary;
  final List<RollupNode> nodes;

  /// Classroom scope only: each student's course value.
  final List<RollupStudent> students;

  bool get isClassroom => scope == 'classroom';

  String get courseTitle =>
      [courseCode, courseName].where((s) => s.isNotEmpty).join(' ');

  /// The axes of the spider chart: nodes with at least one assessed
  /// indicator (DESIGN §20.4).
  List<RollupNode> get assessedNodes =>
      nodes.where((n) => n.assessed > 0).toList();

  /// The assessed indicators of every node in node order, each once (an
  /// indicator planned in two units is counted under the first).
  List<IndicatorAxis> get assessedIndicators {
    final seen = <int>{};
    return [
      for (final n in nodes)
        for (final i in n.indicators)
          if (i.assessed && seen.add(i.skill.id)) (node: n, indicator: i),
    ];
  }

  /// 3–12 assessed nodes: a radar of them. Fewer than 3 but 3–12 assessed
  /// indicators: a radar of the indicators. Otherwise a bar chart of every
  /// planned node (DESIGN §20.4).
  RollupChartMode get chartMode {
    final n = assessedNodes.length;
    if (radarFits(n)) return RollupChartMode.nodes;
    if (n < kRadarMinAxes && radarFits(assessedIndicators.length)) {
      return RollupChartMode.indicators;
    }
    return RollupChartMode.bars;
  }

  /// The chart is a radar (of nodes or of indicators).
  bool get useRadar => chartMode != RollupChartMode.bars;

  factory CourseMasterySummary.fromJson(Map<String, dynamic> json) {
    final course = (json['course'] as Map? ?? const {}).cast<String, dynamic>();
    final summary = json['summary'];
    return CourseMasterySummary(
      courseId: _int(course['id']) ?? 0,
      courseCode: course['code'] as String? ?? '',
      courseName: course['name'] as String? ?? '',
      axis: MasteryAxis.fromApi(json['axis']),
      scope: json['scope'] as String? ?? 'student',
      passThreshold: _double(json['pass_threshold']) ?? 0.5,
      classroomId: _int(json['classroom_id']),
      studentId: _int(json['student_id']),
      summary: summary is Map
          ? RollupNode.fromJson(
              summary.cast<String, dynamic>(),
              title: course['name'] as String? ?? '',
            )
          : const RollupNode(type: 'course', title: ''),
      nodes: [
        for (final n in (json['nodes'] as List?) ?? const [])
          if (n is Map) RollupNode.fromJson(n.cast<String, dynamic>()),
      ],
      students: [
        for (final s in (json['students'] as List?) ?? const [])
          if (s is Map) RollupStudent.fromJson(s.cast<String, dynamic>()),
      ],
    );
  }
}

/// A course of the student's own classrooms (`GET /student/courses`).
class StudentCourse {
  const StudentCourse({
    required this.id,
    required this.code,
    required this.name,
    this.subjectName,
    this.gradeLevel,
    this.semester,
    this.academicYear,
    this.classroomIds = const [],
    this.classrooms = const [],
  });

  final int id;
  final String code;
  final String name;
  final String? subjectName;
  final int? gradeLevel;
  final int? semester;
  final int? academicYear;
  final List<int> classroomIds;

  /// The student's classrooms this course is taught in (DESIGN §24.26).
  final List<ClassroomLabel> classrooms;

  String get title => [code, name].where((s) => s.isNotEmpty).join(' ');

  factory StudentCourse.fromJson(Map<String, dynamic> json) {
    final subject = json['subject'];
    return StudentCourse(
      id: _int(json['id'])!,
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      subjectName: subject is Map ? subject['name'] as String? : null,
      gradeLevel: _int(json['grade_level']),
      semester: _int(json['semester']),
      academicYear: _int(json['academic_year']),
      classroomIds: [
        for (final id in (json['classroom_ids'] as List?) ?? const [])
          ?_int(id),
      ],
      classrooms: ClassroomLabel.listFromJson(json['classrooms']),
    );
  }
}

/// Which roll-up the teacher asks for: a classroom, or one student (in
/// [classroomId] when given).
typedef CourseMasteryQuery = ({
  int courseId,
  int? classroomId,
  int? studentId,
  MasteryAxis axis,
});

/// The course roll-ups (DESIGN §20.7).
abstract class CourseMasteryRepository {
  /// Teacher: `GET /courses/{id}/mastery-summary`. Needs [classroomId] or
  /// [studentId] (422 `errors.classroom_id`).
  Future<CourseMasterySummary> summary(
    int courseId, {
    int? classroomId,
    int? studentId,
    MasteryAxis axis = MasteryAxis.standard,
  });

  /// Student: `GET /student/courses`.
  Future<List<StudentCourse>> myCourses();

  /// Student: `GET /student/courses/{id}/mastery-summary` (own values only).
  Future<CourseMasterySummary> mySummary(
    int courseId, {
    MasteryAxis axis = MasteryAxis.standard,
  });
}

class ApiCourseMasteryRepository implements CourseMasteryRepository {
  ApiCourseMasteryRepository(this._dio);

  final Dio _dio;

  @override
  Future<CourseMasterySummary> summary(
    int courseId, {
    int? classroomId,
    int? studentId,
    MasteryAxis axis = MasteryAxis.standard,
  }) async {
    final res = await _dio.get<Object?>(
      '/courses/$courseId/mastery-summary',
      queryParameters: {
        'axis': axis.apiValue,
        'classroom_id': ?classroomId,
        'student_id': ?studentId,
      },
    );
    return CourseMasterySummary.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<StudentCourse>> myCourses() async {
    final res = await _dio.get<Object?>('/student/courses');
    return unwrapList(res.data).map(StudentCourse.fromJson).toList();
  }

  @override
  Future<CourseMasterySummary> mySummary(
    int courseId, {
    MasteryAxis axis = MasteryAxis.standard,
  }) async {
    final res = await _dio.get<Object?>(
      '/student/courses/$courseId/mastery-summary',
      queryParameters: {'axis': axis.apiValue},
    );
    return CourseMasterySummary.fromJson(unwrapJson(res.data));
  }
}

final courseMasteryRepositoryProvider = Provider<CourseMasteryRepository>(
  (ref) => ApiCourseMasteryRepository(ref.watch(dioProvider)),
);

/// Teacher: the roll-up of a course for a classroom or a student.
final courseMasterySummaryProvider = FutureProvider.autoDispose
    .family<CourseMasterySummary, CourseMasteryQuery>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(courseMasteryRepositoryProvider)
          .summary(
            q.courseId,
            classroomId: q.classroomId,
            studentId: q.studentId,
            axis: q.axis,
          );
    }, retry: apiRetry);

/// Student: the courses of their classrooms.
final myCoursesProvider = FutureProvider.autoDispose<List<StudentCourse>>((
  ref,
) {
  watchSignedInUser(ref);
  return ref.watch(courseMasteryRepositoryProvider).myCourses();
}, retry: apiRetry);

/// Student: their own roll-up of one course on one axis.
final myCourseMasteryProvider = FutureProvider.autoDispose
    .family<CourseMasterySummary, ({int courseId, MasteryAxis axis})>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(courseMasteryRepositoryProvider)
          .mySummary(q.courseId, axis: q.axis);
    }, retry: apiRetry);
