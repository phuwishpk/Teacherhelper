import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../gradebook/gradebook_models.dart' show gradeLabel;
import 'student_labels.dart';

/// `subject: {id, code, name}` of an overview group.
class SubjectRef {
  const SubjectRef({required this.id, this.code = '', this.name = ''});

  final int id;
  final String code;
  final String name;

  static SubjectRef? fromJson(Object? json) {
    if (json is! Map || json['id'] is! num) return null;
    return SubjectRef(
      id: (json['id'] as num).toInt(),
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
    );
  }
}

/// One card of "วิชาของฉัน" (DESIGN §24.11, §24.26): a course in one of the
/// student's classrooms, or older work of a subject without a course.
class SubjectGroup {
  const SubjectGroup({
    required this.classroom,
    this.course,
    this.subject,
    this.teacherName,
    this.todoCount = 0,
    this.resultsCount = 0,
    this.latestPublishedAt,
    this.grade,
    this.special,
  });

  final CourseRef? course;
  final SubjectRef? subject;
  final ClassroomLabel classroom;

  /// The course's teacher; the homeroom teacher for work without a course.
  final String? teacherName;

  /// Homework still to hand in (always 0 in a closed classroom).
  final int todoCount;

  /// Published results.
  final int resultsCount;
  final DateTime? latestPublishedAt;

  /// The current published grade (§23.12), or null.
  final num? grade;
  final String? special;

  bool get hasGrade => grade != null || special != null;
  String get gradeText => gradeLabel(grade, special);

  SubjectTag get tag => SubjectTag(
    course: course,
    subjectName: subject?.name,
    classroom: classroom,
  );

  static SubjectGroup? fromJson(Map<String, dynamic> json) {
    final classroom = ClassroomLabel.fromJson(json['classroom']);
    if (classroom == null) return null;
    final grade = json['grade'];
    final published = json['latest_published_at'];
    return SubjectGroup(
      course: CourseRef.fromJson(json['course']),
      subject: SubjectRef.fromJson(json['subject']),
      classroom: classroom,
      teacherName: json['teacher_name'] as String?,
      todoCount: (json['todo_count'] as num?)?.toInt() ?? 0,
      resultsCount: (json['results_count'] as num?)?.toInt() ?? 0,
      latestPublishedAt: published is String
          ? DateTime.tryParse(published)
          : null,
      grade: grade is Map ? _num(grade['grade']) : null,
      special: grade is Map ? grade['special'] as String? : null,
    );
  }
}

/// `GET /student/overview`: every classroom of the student and one group
/// per (course, classroom), open classrooms first (DESIGN §24.11).
class StudentOverview {
  const StudentOverview({this.classrooms = const [], this.groups = const []});

  final List<ClassroomLabel> classrooms;
  final List<SubjectGroup> groups;

  List<SubjectGroup> get openGroups => [
    for (final g in groups)
      if (!g.classroom.closed) g,
  ];

  /// "ห้องเก่า": read-only, folded away.
  List<SubjectGroup> get closedGroups => [
    for (final g in groups)
      if (g.classroom.closed) g,
  ];

  SubjectGroup? groupOf(String key) =>
      groups.where((g) => g.tag.key == key).firstOrNull;

  int get todoCount => groups.fold(0, (sum, g) => sum + g.todoCount);

  factory StudentOverview.fromJson(Map<String, dynamic> json) =>
      StudentOverview(
        classrooms: ClassroomLabel.listFromJson(json['classrooms']),
        groups: [
          for (final g in (json['groups'] as List?) ?? const [])
            if (g is Map) ?SubjectGroup.fromJson(g.cast<String, dynamic>()),
        ],
      );
}

/// The student's combined view across classrooms (DESIGN §24.11).
abstract class StudentOverviewRepository {
  /// `GET /student/overview`.
  Future<StudentOverview> overview();
}

class ApiStudentOverviewRepository implements StudentOverviewRepository {
  ApiStudentOverviewRepository(this._dio);

  final Dio _dio;

  @override
  Future<StudentOverview> overview() async {
    final res = await _dio.get<Object?>('/student/overview');
    return StudentOverview.fromJson(unwrapJson(res.data));
  }
}

final studentOverviewRepositoryProvider = Provider<StudentOverviewRepository>(
  (ref) => ApiStudentOverviewRepository(ref.watch(dioProvider)),
);

final studentOverviewProvider = FutureProvider.autoDispose<StudentOverview>((
  ref,
) {
  watchSignedInUser(ref);
  return ref.watch(studentOverviewRepositoryProvider).overview();
}, retry: apiRetry);

num? _num(Object? v) => switch (v) {
  num n => n,
  String s => num.tryParse(s),
  _ => null,
};
