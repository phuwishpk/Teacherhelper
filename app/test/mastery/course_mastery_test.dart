import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_api_server.dart';
import '../helpers/fake_http_adapter.dart';

Map<String, dynamic> _skill(int id, String code) => {
  'id': id,
  'code': code,
  'name': 'ตัวชี้วัด $code',
  'subject_id': 1,
  'grade_level': 5,
  'level': 'indicator',
  'source_label': null,
};

Map<String, dynamic> _node(
  String type,
  int? id,
  String title, {
  String? code,
  double? value,
  int assessed = 0,
  int planned = 1,
  List<Map<String, dynamic>> indicators = const [],
  Map<String, dynamic> extra = const {},
}) => {
  'type': type,
  'id': id,
  'code': code,
  'title': title,
  'position': null,
  'value': value,
  'assessed': assessed,
  'planned': planned,
  'coverage': planned == 0 ? null : assessed / planned,
  ...extra,
  'indicators': indicators,
};

/// `GET /student/courses/{id}/mastery-summary` (one student, by standard).
Map<String, dynamic> _studentSummary({int assessedNodes = 3}) => {
  'course': {
    'id': 4,
    'code': 'ค15101',
    'name': 'คณิตศาสตร์ 5',
    'subject_id': 1,
    'grade_level': 5,
  },
  'axis': 'standard',
  'scope': 'student',
  'pass_threshold': 0.5,
  'student_id': 55,
  'summary': {
    'value': 0.62,
    'assessed': 3,
    'planned': 5,
    'coverage': 0.6,
    'passed': 2,
  },
  'nodes': [
    for (var i = 1; i <= 4; i++)
      _node(
        'standard',
        10 + i,
        'มาตรฐาน $i',
        code: 'ค 1.$i',
        value: i <= assessedNodes ? 0.2 * i : null,
        assessed: i <= assessedNodes ? 1 : 0,
        extra: {'passed': i <= assessedNodes && i >= 3 ? 1 : 0},
        indicators: [
          {
            'skill': _skill(i, 'ค 1.$i ป.5/1'),
            'value': i <= assessedNodes ? 0.2 * i : null,
            'n_obs': i <= assessedNodes ? i : 0,
            'level': null,
            'passed': i <= assessedNodes ? i >= 3 : null,
          },
        ],
      ),
    _node('other', null, 'ไม่มีมาตรฐาน'),
  ],
};

void main() {
  group('CourseMasterySummary', () {
    test('parses a student roll-up with its drill-down', () {
      final s = CourseMasterySummary.fromJson(_studentSummary());
      expect(s.courseTitle, 'ค15101 คณิตศาสตร์ 5');
      expect(s.axis, MasteryAxis.standard);
      expect(s.isClassroom, isFalse);
      expect(s.passThreshold, 0.5);
      expect(s.studentId, 55);
      expect(s.summary.type, 'course');
      expect(s.summary.title, 'คณิตศาสตร์ 5');
      expect(s.summary.value, 0.62);
      expect(s.summary.passed, 2);
      expect(s.summary.coverageText, 'ประเมินแล้ว 3/5 ตัวชี้วัด');
      expect(s.nodes, hasLength(5));
      expect(s.nodes.first.label, 'ค 1.1');
      expect(s.nodes.last.isOther, isTrue);
      expect(s.nodes.last.label, 'ไม่มีมาตรฐาน');
      expect(s.nodes.last.value, isNull);

      final first = s.nodes.first.indicators.single;
      expect(first.skill.code, 'ค 1.1 ป.5/1');
      expect(first.value, closeTo(0.2, 1e-9));
      expect(first.assessed, isTrue);
      expect(first.level, MasteryLevel.tooLittle, reason: 'n_obs 1 < 2');
      expect(first.passed, isFalse);
      final third = s.nodes[2].indicators.single;
      expect(third.level, MasteryLevel.partial);
      expect(third.passed, isTrue);
      final fourth = s.nodes[3].indicators.single;
      expect(fourth.assessed, isFalse);
      expect(fourth.level, isNull);
      expect(fourth.passed, isNull);
    });

    test('radar needs 3 to 12 assessed axes, else bars', () {
      expect(
        CourseMasterySummary.fromJson(_studentSummary()).assessedNodes,
        hasLength(3),
      );
      expect(CourseMasterySummary.fromJson(_studentSummary()).useRadar, isTrue);
      expect(
        CourseMasterySummary.fromJson(
          _studentSummary(assessedNodes: 2),
        ).useRadar,
        isFalse,
      );
      final many = {
        ..._studentSummary(),
        'nodes': [
          for (var i = 0; i < 13; i++)
            _node('unit', i, 'หน่วย $i', value: 0.5, assessed: 1),
        ],
      };
      expect(CourseMasterySummary.fromJson(many).useRadar, isFalse);
      many['nodes'] = (many['nodes'] as List).sublist(0, 12);
      expect(CourseMasterySummary.fromJson(many).useRadar, isTrue);
    });

    test('parses a classroom roll-up with each student', () {
      final s = CourseMasterySummary.fromJson({
        'course': {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
        'axis': 'unit',
        'scope': 'classroom',
        'pass_threshold': '0.6',
        'classroom_id': 7,
        'summary': {
          'value': 0.5,
          'assessed': 1,
          'planned': 2,
          'coverage': 0.5,
          'students_assessed': 1,
          'student_count': 2,
        },
        'nodes': [
          _node(
            'unit',
            20,
            'เศษส่วน',
            value: 0.5,
            assessed: 1,
            planned: 2,
            extra: {'students_assessed': 1, 'student_count': 2},
            indicators: [
              {
                'skill': _skill(1, 'ค 1.1 ป.5/1'),
                'value': 0.5,
                'assessed_students': 1,
                'passed_students': 1,
              },
              {
                'skill': _skill(2, 'ค 1.1 ป.5/2'),
                'value': null,
                'assessed_students': 0,
                'passed_students': 0,
              },
            ],
          ),
          _node('unit', 21, 'ทศนิยม', planned: 0),
        ],
        'students': [
          {
            'id': 55,
            'name': 'เด็กชายเอ',
            'student_number': 1,
            'value': 0.5,
            'assessed': 1,
            'planned': 2,
            'coverage': 0.5,
            'passed': 1,
          },
          {
            'id': 56,
            'name': 'เด็กหญิงบี',
            'student_number': 2,
            'value': null,
            'assessed': 0,
            'planned': 2,
            'coverage': 0,
            'passed': 0,
          },
        ],
      });
      expect(s.isClassroom, isTrue);
      expect(s.axis, MasteryAxis.unit);
      expect(s.passThreshold, 0.6);
      expect(s.classroomId, 7);
      expect(s.summary.studentsAssessed, 1);
      expect(s.summary.studentCount, 2);
      final unit = s.nodes.first;
      expect(unit.label, 'เศษส่วน');
      expect(unit.indicators.first.assessedStudents, 1);
      expect(unit.indicators.first.passedStudents, 1);
      expect(unit.indicators.first.level, isNull, reason: 'classroom mean');
      expect(unit.indicators.last.assessed, isFalse);
      expect(s.nodes.last.planned, 0);
      expect(s.nodes.last.coverage, isNull);
      expect(s.useRadar, isFalse);
      expect(s.students.map((x) => x.studentNumber), [1, 2]);
      expect(s.students.last.value, isNull);
      expect(s.students.first.passed, 1);
    });
  });

  group('ApiCourseMasteryRepository', () {
    late List<(String, Map<String, String>)> sent;

    ApiCourseMasteryRepository repo() {
      sent = [];
      final adapter = FakeHttpAdapter((o) async {
        final path = FakeApiServer.apiPath(o.uri).replaceFirst('/api/v1', '');
        sent.add((path, o.uri.queryParameters));
        if (path == '/student/courses') {
          return jsonResponse(200, {
            'data': [
              {
                'id': 4,
                'code': 'ค15101',
                'name': 'คณิตศาสตร์ 5',
                'subject': {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
                'grade_level': 5,
                'semester': 1,
                'academic_year': 2569,
                'classroom_ids': [7],
              },
            ],
          });
        }
        return jsonResponse(200, {'data': _studentSummary()});
      });
      return ApiCourseMasteryRepository(fakeDio(adapter));
    }

    test('teacher summary sends the classroom, student and axis', () async {
      final r = repo();
      await r.summary(4, classroomId: 7);
      expect(sent.last.$1, '/courses/4/mastery-summary');
      expect(sent.last.$2, {'axis': 'standard', 'classroom_id': '7'});

      await r.summary(4, studentId: 55, axis: MasteryAxis.unit);
      expect(sent.last.$2, {'axis': 'unit', 'student_id': '55'});
    });

    test('student courses and own summary', () async {
      final r = repo();
      final courses = await r.myCourses();
      expect(sent.last.$1, '/student/courses');
      final c = courses.single;
      expect(c.title, 'ค15101 คณิตศาสตร์ 5');
      expect(c.subjectName, 'คณิตศาสตร์');
      expect(c.gradeLevel, 5);
      expect(c.semester, 1);
      expect(c.academicYear, 2569);
      expect(c.classroomIds, [7]);

      final s = await r.mySummary(4, axis: MasteryAxis.unit);
      expect(sent.last.$1, '/student/courses/4/mastery-summary');
      expect(sent.last.$2, {'axis': 'unit'});
      expect(s.summary.value, 0.62);
    });
  });

  group('providers', () {
    ProviderContainer container() {
      final adapter = FakeHttpAdapter((o) async {
        final path = FakeApiServer.apiPath(o.uri);
        if (path.endsWith('/student/courses')) {
          return jsonResponse(200, {'data': const []});
        }
        return jsonResponse(200, {'data': _studentSummary()});
      });
      final c = ProviderContainer(
        overrides: [
          courseMasteryRepositoryProvider.overrideWithValue(
            ApiCourseMasteryRepository(fakeDio(adapter)),
          ),
        ],
      );
      addTearDown(c.dispose);
      return c;
    }

    test('load the teacher and student roll-ups', () async {
      final c = container();
      final teacher = await c.read(
        courseMasterySummaryProvider((
          courseId: 4,
          classroomId: 7,
          studentId: null,
          axis: MasteryAxis.standard,
        )).future,
      );
      expect(teacher.courseId, 4);
      expect(await c.read(myCoursesProvider.future), isEmpty);
      final mine = await c.read(
        myCourseMasteryProvider((courseId: 4, axis: MasteryAxis.unit)).future,
      );
      expect(mine.nodes, hasLength(5));
    });
  });
}
