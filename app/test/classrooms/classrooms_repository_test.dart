import 'dart:convert';

import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

/// Request/response shapes of the classroom endpoints (DESIGN §9.2 plus the
/// backend's actual resources; see app/README.md "ข้อตกลงกับ backend").
void main() {
  test('addStudents posts {students: [{student_number, name}]}', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'data': [
          {
            'student_id': 4567,
            'student_number': 1,
            'name': 'ด.ช. สมชาย ใจดี',
            'status': 'active',
            'pin': '123456',
          },
          {
            'student_id': 4568,
            'student_number': 2,
            'name': 'ด.ญ. สมหญิง รักเรียน',
            'status': 'active',
            'pin': '654321',
          },
        ],
      }),
    );
    final repo = ApiClassroomsRepository(fakeDio(adapter));

    final rows = await repo.addStudents(7, const [
      NewStudent(studentNumber: 1, name: 'ด.ช. สมชาย ใจดี'),
      NewStudent(studentNumber: 2, name: 'ด.ญ. สมหญิง รักเรียน'),
    ]);

    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/classrooms/7/students');
    expect(req.data, {
      'students': [
        {'student_number': 1, 'name': 'ด.ช. สมชาย ใจดี'},
        {'student_number': 2, 'name': 'ด.ญ. สมหญิง รักเรียน'},
      ],
    });
    expect(rows.map((r) => r.studentId), [4567, 4568]);
    expect(rows.first.studentNumber, 1);
    expect(
      rows.map((r) => r.pin),
      ['123456', '654321'],
      reason: 'the one-time PINs of the 201 answer reach the screen',
    );
  });

  test('roster follows cursor pagination and reads student_id', () async {
    final adapter = FakeHttpAdapter((options) async {
      if (options.uri.queryParameters['cursor'] == 'c2') {
        return jsonResponse(200, {
          'data': [
            {'student_id': 2, 'student_number': 2, 'name': 'B'},
          ],
          'meta': {'next_cursor': null},
        });
      }
      return jsonResponse(200, {
        'data': [
          {'student_id': 1, 'student_number': 1, 'name': 'A'},
        ],
        'meta': {'next_cursor': 'c2'},
      });
    });
    final repo = ApiClassroomsRepository(fakeDio(adapter));
    final roster = await repo.roster(7);
    expect(roster.map((s) => s.studentId), [1, 2]);
    expect(adapter.requests.map((r) => r.uri.path).toSet(), {
      '/api/v1/classrooms/7/roster',
    });
  });

  test('create / update send snake_case bodies', () async {
    final adapter = FakeHttpAdapter(
      (options) async => jsonResponse(options.method == 'POST' ? 201 : 200, {
        'data': {
          'id': 7,
          'name': 'ป.5/2',
          'grade_level': 5,
          'academic_year': 2569,
          'class_code': 'K7Q3M2',
          'students_count': 0,
        },
      }),
    );
    final repo = ApiClassroomsRepository(fakeDio(adapter));

    final created = await repo.create(
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
    );
    expect(created.classCode, 'K7Q3M2');
    expect(created.studentCount, 0);
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms');
    expect(adapter.requests.last.data, {
      'name': 'ป.5/2',
      'grade_level': 5,
      'academic_year': 2569,
    });

    await repo.update(7, name: 'ป.5/3');
    expect(adapter.requests.last.method, 'PATCH');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7');
    expect(adapter.requests.last.data, {'name': 'ป.5/3'});
  });

  test('login-cards returns a print job polled at login-card-prints', () async {
    final adapter = FakeHttpAdapter((options) async {
      if (options.uri.path.endsWith('/login-cards')) {
        return jsonResponse(202, {
          'data': {
            'id': 31,
            'status': 'queued',
            'classroom_id': 7,
            'student_id': null,
            'download_url': null,
            'status_url': '/api/v1/login-card-prints/31',
            'error': null,
          },
        });
      }
      return jsonResponse(200, {
        'data': {
          'id': 31,
          'status': 'ready',
          'classroom_id': 7,
          'student_id': null,
          'download_url': '/api/v1/login-card-prints/31/file',
          'status_url': '/api/v1/login-card-prints/31',
          'error': null,
        },
      });
    });
    final repo = ApiClassroomsRepository(fakeDio(adapter));

    final job = await repo.requestLoginCards(7);
    expect(adapter.requests.last.method, 'POST');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/login-cards');
    expect(job.id, 31);
    expect(job.isPending, isTrue);
    expect(job.pollUrl, '/api/v1/login-card-prints/31');

    final ready = await repo.loginCardPrint(job);
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/login-card-prints/31',
      reason: 'status_url is relative to the API prefix',
    );
    expect(ready.isReady, isTrue);
    expect(ready.downloadUrl, '/api/v1/login-card-prints/31/file');
  });

  test('loginCardPrint falls back to /login-card-prints/{id}', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {'id': 5, 'status': 'rendering'}),
    );
    final repo = ApiClassroomsRepository(fakeDio(adapter));
    final job = await repo.reissueLoginCard(4567);
    expect(adapter.requests.last.uri.path, '/api/v1/students/4567/login-card');
    await repo.loginCardPrint(job);
    expect(adapter.requests.last.uri.path, '/api/v1/login-card-prints/5');
  });

  test(
    'resetPin posts to /students/{id}/pin and returns the PIN once',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(200, {'student_id': 4567, 'pin': '048213'}),
      );
      final repo = ApiClassroomsRepository(fakeDio(adapter));
      final reset = await repo.resetPin(4567);
      expect(adapter.requests.single.method, 'POST');
      expect(adapter.requests.single.uri.path, '/api/v1/students/4567/pin');
      expect(reset.pin, '048213', reason: 'leading zero must survive');
      expect(jsonEncode(adapter.requests.single.data ?? {}), '{}');
    },
  );
}
