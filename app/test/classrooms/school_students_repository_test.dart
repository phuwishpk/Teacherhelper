import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

/// Request/response shapes of the build 1 endpoints of DESIGN §24.12 A
/// (school-wide students, merging and closed classrooms), as the backend's
/// controllers answer them.
void main() {
  Map<String, Object?> classroomJson({String? closedAt, String? role}) => {
    'id': 7,
    'name': 'ป.5/2',
    'grade_level': 5,
    'academic_year': 2569,
    'class_code': 'K7Q3M2',
    'students_count': 30,
    'closed_at': closedAt,
    'my_role': ?role,
  };

  Map<String, Object?> studentJson(int id, String name) => {
    'id': id,
    'name': name,
    'student_code': '65001',
    'has_google': true,
    'classrooms': [
      {
        'id': 3,
        'name': 'ป.4/2',
        'academic_year': 2568,
        'student_number': 4,
        'closed': true,
      },
    ],
  };

  test('closed_at and my_role of a classroom (DESIGN §24.6, §24.8)', () {
    final open = Classroom.fromJson(classroomJson(role: 'homeroom'));
    expect(open.isClosed, isFalse);
    expect(open.isHomeroom, isTrue);
    expect(open.canManageStudents, isTrue);

    final closed = Classroom.fromJson(
      classroomJson(closedAt: '2026-10-01T03:00:00+00:00'),
    );
    expect(closed.closedAt, DateTime.utc(2026, 10, 1, 3));
    expect(closed.canManageStudents, isFalse, reason: 'read-only');
    expect(closed.withGoogleLink(null).closedAt, closed.closedAt);

    final subject = Classroom.fromJson(classroomJson(role: 'subject'));
    expect(subject.myRole, ClassroomRole.subject);
    expect(subject.canManageStudents, isFalse);
    expect(subject.myRole.label, 'ครูประจำวิชา');
  });

  test(
    '"ห้องเก่า" is GET /classrooms?state=closed; close, reopen, delete',
    () async {
      final adapter = FakeHttpAdapter((options) async {
        if (options.method == 'DELETE') return jsonResponse(204, null);
        final closed =
            options.uri.path.endsWith('/close') ||
            options.uri.queryParameters['state'] == 'closed';
        final json = classroomJson(
          closedAt: closed ? '2026-10-01T03:00:00+00:00' : null,
        );
        return jsonResponse(200, {
          'data': options.method == 'GET' ? [json] : json,
          'meta': {'next_cursor': null},
        });
      });
      final repo = ApiClassroomsRepository(fakeDio(adapter));

      final old = await repo.listClosed();
      expect(adapter.requests.last.uri.path, '/api/v1/classrooms');
      expect(adapter.requests.last.uri.queryParameters['state'], 'closed');
      expect(old.single.isClosed, isTrue);

      final closed = await repo.close(7);
      expect(adapter.requests.last.method, 'POST');
      expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/close');
      expect(closed.isClosed, isTrue);

      final reopened = await repo.reopen(7);
      expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/reopen');
      expect(reopened.isClosed, isFalse);

      await repo.delete(7);
      expect(adapter.requests.last.method, 'DELETE');
      expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7');
    },
  );

  test(
    'addStudents sends new and existing rows; existing keep their PIN',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(201, {
          'data': [
            {
              'student_id': 900,
              'student_number': 3,
              'name': 'ด.ญ. ใหม่',
              'student_code': '65009',
              'status': 'active',
              'pin': '004211',
              'existing': false,
            },
            {
              'student_id': 501,
              'student_number': 4,
              'name': 'ด.ช. สมชาย ใจดี',
              'student_code': '65001',
              'status': 'active',
              'pin': null,
              'existing': true,
            },
            {
              'student_id': 502,
              'student_number': 5,
              'name': 'ด.ญ. มานี',
              'student_code': null,
              'status': 'active',
              'pin': '771100',
              'existing': true,
            },
          ],
        }),
      );
      final repo = ApiClassroomsRepository(fakeDio(adapter));
      final rows = await repo.addStudents(7, const [
        NewStudent(studentNumber: 3, name: 'ด.ญ. ใหม่', studentCode: '65009'),
        ExistingStudentEnrolment(studentId: 501, studentNumber: 4),
        ExistingStudentEnrolment(
          studentId: 502,
          studentNumber: 5,
          reissuePin: true,
        ),
      ]);

      expect(adapter.requests.single.uri.path, '/api/v1/classrooms/7/students');
      expect(adapter.requests.single.data, {
        'students': [
          {'student_number': 3, 'name': 'ด.ญ. ใหม่', 'student_code': '65009'},
          {'student_id': 501, 'student_number': 4},
          {'student_id': 502, 'student_number': 5, 'reissue_pin': true},
        ],
      });
      expect(rows.map((r) => r.hasPin), [true, false, true]);
      expect(rows.map((r) => r.existing), [false, true, true]);
      expect(rows[1].pin, '', reason: 'pin null is not the text "null"');
      expect(rows[2].pin, '771100');
    },
  );

  test('roster rows carry the student code; number and removal', () async {
    final adapter = FakeHttpAdapter((options) async {
      if (options.method == 'DELETE') return jsonResponse(204, null);
      return jsonResponse(200, {
        'data': {
          'student_id': 501,
          'student_number': 9,
          'name': 'ด.ช. สมชาย ใจดี',
          'student_code': '65001',
          'status': 'active',
          'left_course_at': null,
          'pin_pending': false,
        },
      });
    });
    final repo = ApiClassroomsRepository(fakeDio(adapter));

    final row = await repo.updateStudentNumber(7, 501, 9);
    expect(adapter.requests.last.method, 'PATCH');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/students/501');
    expect(adapter.requests.last.data, {'student_number': 9});
    expect(row.studentNumber, 9);
    expect(row.studentCode, '65001');

    await repo.removeStudent(7, 501);
    expect(adapter.requests.last.method, 'DELETE');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/students/501');
  });

  test('school search, student edit and duplicate pairs', () async {
    final adapter = FakeHttpAdapter((options) async {
      final path = options.uri.path;
      if (path.endsWith('/school-students')) {
        return jsonResponse(200, {
          'data': [studentJson(501, 'ด.ช. สมชาย ใจดี')],
        });
      }
      if (path.endsWith('/duplicate-candidates')) {
        return jsonResponse(200, {
          'data': [
            {
              'a': studentJson(501, 'ด.ช. สมชาย ใจดี'),
              'b': studentJson(777, 'สมชาย ใจดี'),
              'reasons': ['email', 'name'],
            },
          ],
        });
      }
      return jsonResponse(200, {'data': studentJson(501, 'ด.ช. สมชาย ใจดี')});
    });
    final repo = ApiClassroomsRepository(fakeDio(adapter));

    final found = await repo.searchSchoolStudents('สมชาย');
    expect(adapter.requests.last.uri.path, '/api/v1/school-students');
    expect(adapter.requests.last.uri.queryParameters, {'q': 'สมชาย'});
    expect(found.single.hasGoogle, isTrue);
    expect(found.single.isIn(3), isTrue);
    expect(
      found.single.details,
      'เลขประจำตัว 65001 · ป.4/2 ปี 2568 เลขที่ 4 (ห้องเก่า)',
    );

    await repo.updateStudent(501, name: 'ด.ช. สมชาย ใจดี');
    expect(adapter.requests.last.method, 'PATCH');
    expect(adapter.requests.last.uri.path, '/api/v1/students/501');
    expect(adapter.requests.last.data, {
      'name': 'ด.ช. สมชาย ใจดี',
      'student_code': null,
    }, reason: 'an empty code clears it');

    final pairs = await repo.duplicateCandidates();
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/students/duplicate-candidates',
    );
    expect(pairs.single.a.id, 501);
    expect(pairs.single.b.id, 777);
    expect(pairs.single.reasons, ['email', 'name']);
    expect(pairs.single.involves({777}), isTrue);
    expect(pairs.single.involves({1}), isFalse);
  });

  test('merge preview and merge (DESIGN §24.5)', () async {
    Map<String, Object?> side(int id) => {
      'id': id,
      'name': 'บัญชี $id',
      'student_code': id == 501 ? '65001' : null,
      'status': 'active',
      'classrooms': [
        {
          'id': 7,
          'name': 'ป.5/2',
          'academic_year': 2569,
          'student_number': 4,
          'closed': false,
        },
      ],
      'submissions': {'total': 5, 'published': 3},
      'gradebook_entries': 6,
      'special_grades': 1,
      'published_grades': 2,
      'practice_attempts': 7,
      'observations': 8,
      'mastery_skills': 4,
      'analyses': 1,
      'google_emails': ['a@school.ac.th'],
    };
    final adapter = FakeHttpAdapter((options) async {
      if (options.method == 'GET') {
        return jsonResponse(200, {
          'data': {
            'keep': side(501),
            'merge': side(777),
            'conflicts': [
              {
                'type': 'submissions',
                'message': 'ทั้งสองบัญชีมีงาน เศษส่วน ห้อง ป.5/2',
              },
            ],
            'can_merge': false,
          },
        });
      }
      return jsonResponse(200, {
        'data': {'merge_id': 3, 'kept_student': studentJson(501, 'บัญชี 501')},
      });
    });
    final repo = ApiClassroomsRepository(fakeDio(adapter));

    final preview = await repo.mergePreview(keepId: 501, mergeId: 777);
    expect(adapter.requests.last.uri.path, '/api/v1/students/merge-preview');
    expect(adapter.requests.last.uri.queryParameters, {
      'keep_id': '501',
      'merge_id': '777',
    });
    expect(preview.canMerge, isFalse);
    expect(preview.conflicts.single.type, 'submissions');
    expect(preview.keep.submissionsTotal, 5);
    expect(preview.keep.submissionsPublished, 3);
    expect(preview.merge.studentCode, isNull);
    expect(preview.keep.facts, contains(('งานที่ส่ง', '5 (เผยแพร่แล้ว 3)')));
    expect(preview.keep.facts, contains(('บัญชี Google', 'a@school.ac.th')));
    expect(preview.merge.facts.first, ('เลขประจำตัว', '-'));

    final kept = await repo.merge(keepId: 501, mergeId: 777);
    expect(adapter.requests.last.method, 'POST');
    expect(adapter.requests.last.uri.path, '/api/v1/students/merge');
    expect(adapter.requests.last.data, {'keep_id': 501, 'merge_id': 777});
    expect(kept.id, 501);
  });
}
