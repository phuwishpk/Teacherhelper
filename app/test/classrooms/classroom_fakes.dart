import 'package:dio/dio.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/school_students.dart';
import 'package:flutter_test/flutter_test.dart';

/// A DESIGN §9 error answer as Dio throws it.
DioException dioError(int status, Map<String, Object?> body) {
  final request = RequestOptions(path: '/x');
  return DioException(
    requestOptions: request,
    type: DioExceptionType.badResponse,
    response: Response<Object?>(
      requestOptions: request,
      statusCode: status,
      data: body,
    ),
  );
}

Classroom room({
  int id = 7,
  String name = 'ป.5/2',
  DateTime? closedAt,
  ClassroomRole role = ClassroomRole.homeroom,
  int students = 2,
}) => Classroom(
  id: id,
  name: name,
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'K7Q3M2',
  studentCount: students,
  closedAt: closedAt,
  myRole: role,
);

const somchai = SchoolStudent(
  id: 501,
  name: 'ด.ช. สมชาย ใจดี',
  studentCode: '65001',
  classrooms: [
    StudentClassroom(
      id: 3,
      name: 'ป.4/2',
      academicYear: 2568,
      studentNumber: 4,
      closed: true,
    ),
  ],
);

const manee = SchoolStudent(
  id: 502,
  name: 'ด.ญ. มานี มีนา',
  classrooms: [
    StudentClassroom(
      id: 7,
      name: 'ป.5/2',
      academicYear: 2569,
      studentNumber: 1,
    ),
  ],
);

MergeAccount account(int id, String name, {String? code, int work = 0}) =>
    MergeAccount(
      id: id,
      name: name,
      studentCode: code,
      submissionsTotal: work,
      classrooms: [
        StudentClassroom(
          id: 7,
          name: 'ป.5/2',
          academicYear: 2569,
          studentNumber: id % 100,
        ),
      ],
    );

/// Every endpoint of build 1 (DESIGN §24.12 A), in memory, recording calls.
class FakeSchoolClassrooms extends Fake implements ClassroomsRepository {
  FakeSchoolClassrooms({List<Classroom>? open, List<Classroom>? closed})
    : open = open ?? [room()],
      closed = closed ?? [];

  List<Classroom> open;
  List<Classroom> closed;
  final rosters = <int, List<RosterStudent>>{
    7: const [
      RosterStudent(studentId: 502, studentNumber: 1, name: 'ด.ญ. มานี มีนา'),
      RosterStudent(
        studentId: 503,
        studentNumber: 2,
        name: 'ด.ช. ปิติ ชูใจ',
        studentCode: '65003',
      ),
    ],
  };
  List<SchoolStudent> school = const [somchai, manee];
  List<DuplicateCandidate> duplicates = const [];
  MergePreview Function(int keep, int merge)? preview;

  Object? addError;
  Object? deleteError;
  Object? removeError;
  Object? mergeError;
  Object? updateError;

  final calls = <String>[];
  final added = <List<StudentEnrolment>>[];
  final searches = <String>[];
  final previews = <(int, int)>[];
  final merges = <(int, int)>[];
  final studentUpdates = <(int, String, String?)>[];
  final numberUpdates = <(int, int, int)>[];

  @override
  Future<List<Classroom>> list() async => open;

  @override
  Future<List<Classroom>> listClosed() async {
    calls.add('listClosed');
    return closed;
  }

  @override
  Future<Classroom> get(int id) async =>
      [...open, ...closed].firstWhere((c) => c.id == id);

  Classroom _copy(Classroom c, DateTime? closedAt) => Classroom(
    id: c.id,
    name: c.name,
    gradeLevel: c.gradeLevel,
    academicYear: c.academicYear,
    classCode: c.classCode,
    studentCount: c.studentCount,
    closedAt: closedAt,
    myRole: c.myRole,
  );

  @override
  Future<Classroom> close(int id) async {
    calls.add('close $id');
    final c = _copy(
      open.firstWhere((c) => c.id == id),
      DateTime.utc(2026, 10, 1),
    );
    open = [
      for (final o in open)
        if (o.id != id) o,
    ];
    closed = [...closed, c];
    return c;
  }

  @override
  Future<Classroom> reopen(int id) async {
    calls.add('reopen $id');
    final c = _copy(closed.firstWhere((c) => c.id == id), null);
    closed = [
      for (final o in closed)
        if (o.id != id) o,
    ];
    open = [...open, c];
    return c;
  }

  @override
  Future<void> delete(int id) async {
    calls.add('delete $id');
    if (deleteError case final e?) throw e;
    open = [
      for (final o in open)
        if (o.id != id) o,
    ];
    closed = [
      for (final o in closed)
        if (o.id != id) o,
    ];
  }

  @override
  Future<List<RosterStudent>> roster(int id) async => rosters[id] ?? const [];

  @override
  Future<List<EnrolledStudent>> addStudents(
    int id,
    List<StudentEnrolment> students,
  ) async {
    added.add(students);
    if (addError case final e?) throw e;
    final rows = <EnrolledStudent>[];
    for (final s in students) {
      switch (s) {
        case NewStudent():
          rows.add(
            EnrolledStudent(
              studentId: 900 + s.studentNumber,
              studentNumber: s.studentNumber,
              name: s.name,
              pin: '10000${s.studentNumber}',
            ),
          );
        case ExistingStudentEnrolment():
          rows.add(
            EnrolledStudent(
              studentId: s.studentId,
              studentNumber: s.studentNumber,
              name: school.firstWhere((x) => x.id == s.studentId).name,
              pin: s.reissuePin ? '20000${s.studentNumber}' : '',
              existing: true,
            ),
          );
      }
    }
    rosters[id] = [
      ...?rosters[id],
      for (final r in rows)
        RosterStudent(
          studentId: r.studentId,
          studentNumber: r.studentNumber,
          name: r.name,
        ),
    ];
    return rows;
  }

  /// Calls of `from-classroom`: (target, source, ids, numbering, new pins).
  final copies = <(int, int, List<int>, CopyNumbering, bool)>[];
  Object? copyError;

  /// Students `from-classroom` reports as skipped.
  List<SkippedStudent> copySkipped = const [];

  @override
  Future<StudentsCopyResult> copyStudents(
    int classroomId, {
    required int sourceClassroomId,
    required List<int> studentIds,
    required CopyNumbering numbering,
    required bool newPins,
  }) async {
    copies.add((
      classroomId,
      sourceClassroomId,
      studentIds,
      numbering,
      newPins,
    ));
    if (copyError case final e?) throw e;
    final source = rosters[sourceClassroomId] ?? const <RosterStudent>[];
    var next = 0;
    for (final r in rosters[classroomId] ?? const <RosterStudent>[]) {
      if (r.studentNumber > next) next = r.studentNumber;
    }
    final rows = [
      for (final s in source)
        if (studentIds.contains(s.studentId))
          EnrolledStudent(
            studentId: s.studentId,
            studentNumber: numbering == CopyNumbering.keep
                ? s.studentNumber
                : ++next,
            name: s.name,
            pin: newPins ? '30000${s.studentNumber}' : '',
            existing: true,
          ),
    ];
    rosters[classroomId] = [
      ...?rosters[classroomId],
      for (final r in rows)
        RosterStudent(
          studentId: r.studentId,
          studentNumber: r.studentNumber,
          name: r.name,
        ),
    ];
    return StudentsCopyResult(enrolled: rows, skipped: copySkipped);
  }

  @override
  Future<List<SchoolStudent>> searchSchoolStudents(String query) async {
    searches.add(query);
    return [
      for (final s in school)
        if (s.name.contains(query) || (s.studentCode ?? '').startsWith(query))
          s,
    ];
  }

  @override
  Future<SchoolStudent> updateStudent(
    int studentId, {
    required String name,
    String? studentCode,
  }) async {
    studentUpdates.add((studentId, name, studentCode));
    if (updateError case final e?) throw e;
    return SchoolStudent(id: studentId, name: name, studentCode: studentCode);
  }

  @override
  Future<RosterStudent> updateStudentNumber(
    int classroomId,
    int studentId,
    int studentNumber,
  ) async {
    numberUpdates.add((classroomId, studentId, studentNumber));
    return RosterStudent(
      studentId: studentId,
      studentNumber: studentNumber,
      name: 'x',
    );
  }

  @override
  Future<void> removeStudent(int classroomId, int studentId) async {
    calls.add('remove $classroomId $studentId');
    if (removeError case final e?) throw e;
    rosters[classroomId] = [
      for (final r in rosters[classroomId] ?? const <RosterStudent>[])
        if (r.studentId != studentId) r,
    ];
  }

  @override
  Future<List<DuplicateCandidate>> duplicateCandidates() async => duplicates;

  @override
  Future<MergePreview> mergePreview({
    required int keepId,
    required int mergeId,
  }) async {
    previews.add((keepId, mergeId));
    return (preview ?? _defaultPreview)(keepId, mergeId);
  }

  static MergePreview _defaultPreview(int keep, int merge) => MergePreview(
    keep: account(keep, 'บัญชี $keep', code: '65001', work: 3),
    merge: account(merge, 'บัญชี $merge', work: 1),
    canMerge: true,
  );

  @override
  Future<SchoolStudent> merge({
    required int keepId,
    required int mergeId,
  }) async {
    merges.add((keepId, mergeId));
    if (mergeError case final e?) throw e;
    return SchoolStudent(id: keepId, name: 'บัญชี $keepId');
  }
}
