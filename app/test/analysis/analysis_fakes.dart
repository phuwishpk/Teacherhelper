import 'package:dio/dio.dart';
import 'package:eduvision/features/analysis/analysis_models.dart';
import 'package:eduvision/features/analysis/analysis_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> analysisSkill(int id, String code, {String? name}) => {
  'id': id,
  'code': code,
  'name': name ?? 'ตัวชี้วัด $code',
  'subject_id': 1,
  'grade_level': 5,
  'level': 'indicator',
  'source_label': null,
};

/// The teacher payload of `GET /students/{id}/analysis` (DESIGN §20.5).
Map<String, dynamic> teacherAnalysisJson({
  int id = 90,
  String status = 'drafted',
  String? teacherText = 'เก่งเรื่องเศษส่วน ควรฝึกทศนิยมเพิ่ม',
  String? studentText = 'หนูทำเศษส่วนได้ดีมาก ลองฝึกทศนิยมอีกนิดนะ',
  String? sharedText,
  bool stale = false,
  List<int> nextSteps = const [2],
  int? approvedBy,
}) => {
  'id': id,
  'student_id': 55,
  'classroom_id': 7,
  'status': status,
  'strengths': [
    {
      'skill': analysisSkill(1, 'ค 1.1 ป.5/1', name: 'เศษส่วน'),
      'value': 0.91,
      'n_obs': 4,
      'too_little': false,
    },
  ],
  'areas': [
    {
      'skill': analysisSkill(2, 'ค 1.2 ป.5/1', name: 'ทศนิยม'),
      'value': 0.3,
      'n_obs': 3,
      'too_little': false,
    },
    {
      'skill': analysisSkill(3, 'ค 2.1 ป.5/1', name: 'การวัด'),
      'value': 0.5,
      'n_obs': 1,
      'too_little': true,
    },
  ],
  'generated_via': teacherText == null ? null : 'batch',
  'generated_at': teacherText == null ? null : '2026-09-29T18:10:00Z',
  'stale': stale,
  'shared_at': sharedText == null ? null : '2026-09-29T23:00:00Z',
  'awaiting_approval': studentText != null && studentText != sharedText,
  'updated_at': '2026-09-29T23:00:00Z',
  'teacher_text': teacherText,
  'student_text': studentText,
  'next_steps': [
    for (final s in nextSteps) {'skill': analysisSkill(s, 'ค 1.2 ป.5/1')},
  ],
  'shared_student_text': sharedText,
  'approved_by': approvedBy,
};

Map<String, dynamic> classroomAnalysesJson({bool autoShare = false}) => {
  'classroom_id': 7,
  'auto_share_analysis': autoShare,
  'students': [
    {
      'student': {'id': 55, 'name': 'ด.ญ. มะลิ', 'student_number': 1},
      'analysis': {
        'id': 90,
        'student_id': 55,
        'classroom_id': 7,
        'status': 'drafted',
        'strengths': <Object>[],
        'areas': [
          {
            'skill': analysisSkill(2, 'ค 1.2 ป.5/1'),
            'value': 0.3,
            'n_obs': 3,
            'too_little': false,
          },
        ],
        'has_text': true,
        'generated_via': 'batch',
        'generated_at': '2026-09-29T18:10:00Z',
        'stale': true,
        'shared': false,
        'shared_at': null,
        'awaiting_approval': true,
        'updated_at': '2026-09-29T18:10:00Z',
      },
    },
    {
      'student': {'id': 56, 'name': 'ด.ช. ปิติ', 'student_number': 2},
      'analysis': {
        'id': 91,
        'student_id': 56,
        'classroom_id': 7,
        'status': 'drafted',
        'strengths': <Object>[],
        'areas': <Object>[],
        'has_text': true,
        'generated_via': 'now',
        'generated_at': '2026-09-29T18:10:00Z',
        'stale': false,
        'shared': true,
        'shared_at': '2026-09-29T19:00:00Z',
        'awaiting_approval': false,
        'updated_at': '2026-09-29T19:00:00Z',
      },
    },
    {
      'student': {'id': 57, 'name': 'ด.ช. มานะ', 'student_number': 3},
      'analysis': null,
    },
    {
      'student': {'id': 58, 'name': 'ด.ญ. ชูใจ', 'student_number': 4},
      'analysis': {
        'id': 92,
        'student_id': 58,
        'classroom_id': 7,
        'status': 'queued',
        'strengths': <Object>[],
        'areas': <Object>[],
        'has_text': false,
        'generated_via': null,
        'generated_at': null,
        'stale': false,
        'shared': false,
        'shared_at': null,
        'awaiting_approval': false,
        'updated_at': null,
      },
    },
  ],
};

List<Map<String, dynamic>> myAnalysesJson() => [
  {
    'classroom': {'id': 7, 'name': 'ป.5/1'},
    'text': 'หนูทำเศษส่วนได้ดีมาก ลองฝึกทศนิยมอีกนิดนะ',
    'shared_at': '2026-09-29T23:00:00Z',
    'next_steps': [
      {'skill': analysisSkill(7, 'ค 1.1 ป.4/2', name: 'บวกลบเศษส่วน')},
    ],
  },
  {
    'classroom': {'id': 8, 'name': 'ชุมนุมคณิต'},
    'text': 'ตั้งใจดีมาก',
    'shared_at': null,
    'next_steps': <Object>[],
  },
];

DioException apiError(int status, String code, [String message = 'ผิดพลาด']) {
  final req = RequestOptions(path: '/x');
  return DioException(
    requestOptions: req,
    response: Response<Object?>(
      requestOptions: req,
      statusCode: status,
      data: {'message': message, 'errors': <String, Object>{}, 'code': code},
    ),
  );
}

/// In-memory [AnalysisRepository] that records every call.
class FakeAnalysisRepository implements AnalysisRepository {
  FakeAnalysisRepository({Map<String, dynamic>? analysis, this.noData = false})
    : analysis = analysis ?? teacherAnalysisJson();

  Map<String, dynamic> analysis;
  bool noData;
  bool autoShare = false;
  Object? runError;
  final calls = <String>[];
  final runGuidance = <String?>[];
  final edits = <({String? teacherText, String? studentText})>[];

  StudentAnalysis get _current => StudentAnalysis.fromJson(analysis);

  void _refreshFlags() {
    analysis['awaiting_approval'] =
        analysis['student_text'] != null &&
        analysis['student_text'] != analysis['shared_student_text'];
  }

  @override
  Future<ClassroomAnalyses> classroom(int classroomId) async {
    calls.add('classroom:$classroomId');
    return ClassroomAnalyses.fromJson(
      classroomAnalysesJson(autoShare: autoShare),
    );
  }

  @override
  Future<StudentAnalysis?> student(int studentId, int classroomId) async {
    calls.add('student:$studentId:$classroomId');
    return noData ? null : _current;
  }

  @override
  Future<StudentAnalysis> runNow(
    int studentId,
    int classroomId, {
    String? guidance,
  }) async {
    calls.add('run:$studentId:$classroomId');
    runGuidance.add(guidance);
    if (runError != null) throw runError!;
    analysis =
        teacherAnalysisJson(
            teacherText: 'ข้อความใหม่สำหรับครู',
            studentText: 'ข้อความใหม่ให้กำลังใจ',
            sharedText: analysis['shared_student_text'] as String?,
          )
          ..['generated_via'] = 'now'
          ..['guidance'] = guidance;
    return _current;
  }

  @override
  Future<StudentAnalysis> edit(
    int analysisId, {
    String? teacherText,
    String? studentText,
  }) async {
    calls.add('edit:$analysisId');
    edits.add((teacherText: teacherText, studentText: studentText));
    if (teacherText != null) analysis['teacher_text'] = teacherText;
    if (studentText != null) analysis['student_text'] = studentText;
    _refreshFlags();
    return _current;
  }

  @override
  Future<StudentAnalysis> approve(int analysisId) async {
    calls.add('approve:$analysisId');
    analysis['shared_student_text'] = analysis['student_text'];
    analysis['shared_at'] = '2026-09-30T01:00:00Z';
    analysis['approved_by'] = 3;
    _refreshFlags();
    return _current;
  }

  @override
  Future<void> setAutoShare(int classroomId, bool value) async {
    calls.add('autoShare:$classroomId:$value');
    autoShare = value;
  }

  @override
  Future<List<MyAnalysis>> mine() async {
    calls.add('mine');
    return myAnalysesJson().map(MyAnalysis.fromJson).toList();
  }
}

class FakeAnalysisClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'AAA111',
    ),
  ];

  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 55, studentNumber: 1, name: 'ด.ญ. มะลิ'),
  ];
}
