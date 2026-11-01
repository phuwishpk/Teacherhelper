import 'package:eduvision/features/attendance/attendance_models.dart';
import 'package:eduvision/features/attendance/attendance_repository.dart';

Map<String, dynamic> _counts({int late = 0, int sick = 0}) => {
  'present': 0,
  'late': late,
  'absent': 0,
  'personal_leave': 0,
  'sick_leave': sick,
};

Map<String, dynamic> _sessionJson({int id = 31, bool records = false}) => {
  'id': id,
  'course_id': 4,
  'classroom_id': 7,
  'held_on': '2026-10-05',
  'period_no': 2,
  'note': null,
  'counts': _counts(),
  if (records)
    'records': [
      {
        'student_id': 11,
        'student_number': 1,
        'name': 'ก้อง',
        'in_classroom': true,
        'status': 'present',
        'note': null,
      },
      {
        'student_id': 12,
        'student_number': 2,
        'name': 'ขวัญ',
        'in_classroom': true,
        'status': 'late',
        'note': 'รถติด',
      },
    ],
};

Map<String, dynamic> _overviewJson() => {
  'scores': {'present': 1, 'late': 0.5, 'absent': 0},
  'auto_item': null,
  'sessions': [_sessionJson()],
  'students': const [],
};

List<MyAttendanceCourse> _defaultMine() => [
  MyAttendanceCourse.fromJson({
    'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
    'classroom': {'id': 7, 'name': 'ป.5/1', 'academic_year': 2569},
    'counts': _counts(late: 1, sick: 1),
    'counted': 1,
    'rate': 0.5,
    'sessions': [
      {
        'id': 32,
        'held_on': '2026-10-06',
        'period_no': null,
        'status': 'sick_leave',
        'note': 'ไข้หวัด',
      },
      {'id': 31, 'held_on': '2026-10-05', 'period_no': 1, 'status': 'late'},
    ],
  }),
];

class FakeAttendance implements AttendanceRepository {
  FakeAttendance({
    Map<String, dynamic>? overview,
    List<MyAttendanceCourse>? mine,
  }) : overviewBody = overview ?? _overviewJson(),
       mineValue = mine ?? _defaultMine();

  Map<String, dynamic> overviewBody;
  final List<String> calls = [];
  AttendanceDraft? lastDraft;
  AttendanceScores? savedScores;
  Object? failWith;
  List<MyAttendanceCourse> mineValue;
  Object? mineFailure;

  @override
  Future<AttendanceOverview> overview(int courseId, int classroomId) async {
    calls.add('overview:$courseId:$classroomId');
    return AttendanceOverview.fromJson(overviewBody);
  }

  @override
  Future<AttendanceSession> session(int sessionId) async =>
      AttendanceSession.fromJson(_sessionJson(id: sessionId, records: true));

  @override
  Future<AttendanceSession> create(
    int courseId,
    int classroomId,
    AttendanceDraft draft,
  ) async {
    if (failWith case final error?) throw error;
    calls.add('create:$courseId:$classroomId');
    lastDraft = draft;
    return AttendanceSession.fromJson(_sessionJson(records: true));
  }

  @override
  Future<AttendanceSession> update(int sessionId, AttendanceDraft draft) async {
    calls.add('update:$sessionId');
    lastDraft = draft;
    return AttendanceSession.fromJson(_sessionJson(records: true));
  }

  @override
  Future<void> delete(int sessionId) async => calls.add('delete:$sessionId');

  @override
  Future<AttendanceScores> saveScores(
    int courseId,
    AttendanceScores scores,
  ) async {
    calls.add('scores:$courseId');
    savedScores = scores;
    overviewBody = {...overviewBody, 'scores': scores.toJson()};
    return scores;
  }

  @override
  Future<void> addAutoItem(
    int courseId,
    int classroomId, {
    required int categoryId,
    required double maxPoints,
  }) async {
    calls.add('auto:$courseId:$classroomId:$categoryId:$maxPoints');
    overviewBody = {
      ...overviewBody,
      'auto_item': {
        'id': 9,
        'category_id': categoryId,
        'max_points': maxPoints,
      },
    };
  }

  @override
  Future<List<MyAttendanceCourse>> mine() async {
    if (mineFailure case final error?) throw error;
    return mineValue;
  }
}
