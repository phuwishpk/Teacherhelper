import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

/// Request/response shapes of the Google Classroom endpoints (DESIGN §18.6)
/// with a fake Dio: nothing here reaches Google.
void main() {
  ApiGoogleClassroomRepository repoWith(FakeHttpAdapter adapter) =>
      ApiGoogleClassroomRepository(fakeDio(adapter));

  test('status reads {connected, email, scopes, needs_reconnect}', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'connected': true,
        'email': 'kru@school.ac.th',
        'scopes':
            'https://www.googleapis.com/auth/classroom.courses.readonly '
            'https://www.googleapis.com/auth/drive.readonly',
        'needs_reconnect': true,
      }),
    );
    final status = await repoWith(adapter).status();
    expect(adapter.requests.single.uri.path, '/api/v1/google/status');
    expect(status.connected, isTrue);
    expect(status.email, 'kru@school.ac.th');
    expect(status.scopes, hasLength(2), reason: 'space-separated scopes');
    expect(status.needsReconnect, isTrue);
    expect(status.ready, isFalse);
  });

  test('status accepts scopes as a list and a wrapped body', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'connected': false,
          'email': null,
          'scopes': <String>[],
          'needs_reconnect': false,
        },
      }),
    );
    final status = await repoWith(adapter).status();
    expect(status.connected, isFalse);
    expect(status.scopes, isEmpty);
  });

  test('connect posts {server_auth_code} and reads {email, scopes}', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'email': 'kru@school.ac.th',
        'scopes': ['a', 'b'],
      }),
    );
    final status = await repoWith(adapter).connect('4/0AbCd');
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/google/connect');
    expect(req.data, {'server_auth_code': '4/0AbCd'});
    expect(status.ready, isTrue);
    expect(status.email, 'kru@school.ac.th');
    expect(status.scopes, ['a', 'b']);
  });

  test('connect surfaces google_scope_missing with a Thai message', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(422, {
        'message': 'The given data was invalid.',
        'errors': {
          'scopes': ['missing drive.readonly'],
        },
        'code': 'google_scope_missing',
      }),
    );
    await expectLater(
      repoWith(adapter).connect('code'),
      throwsA(
        isA<DioException>().having(
          googleErrorMessage,
          'message',
          contains('ต้องติ๊กอนุญาตทุกสิทธิ์'),
        ),
      ),
    );
  });

  test('disconnect is DELETE /google/disconnect', () async {
    final adapter = FakeHttpAdapter((_) async => jsonResponse(204, null));
    await repoWith(adapter).disconnect();
    expect(adapter.requests.single.method, 'DELETE');
    expect(adapter.requests.single.uri.path, '/api/v1/google/disconnect');
  });

  test('courses reads [{course_id, name, section}]', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {'course_id': '6210', 'name': 'คณิต ป.5/1', 'section': 'เทอม 1'},
          {'course_id': 777, 'name': 'วิทย์', 'section': ''},
        ],
      }),
    );
    final courses = await repoWith(adapter).courses();
    expect(adapter.requests.single.uri.path, '/api/v1/google/courses');
    expect(courses.map((c) => c.courseId), ['6210', '777']);
    expect(courses.first.section, 'เทอม 1');
    expect(courses.last.section, isNull, reason: 'blank section is hidden');
  });

  test('link posts {course_id} and keeps the course name', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'course_id': '6210',
        'linked_at': '2026-09-25T02:00:00Z',
      }),
    );
    final link = await repoWith(
      adapter,
    ).link(7, const GoogleCourse(courseId: '6210', name: 'คณิต ป.5/1'));
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/classrooms/7/google-link');
    expect(req.data, {'course_id': '6210'});
    expect(link.courseId, '6210');
    expect(link.courseName, 'คณิต ป.5/1');
    expect(link.linkedAt, DateTime.utc(2026, 9, 25, 2));
  });

  test('link accepts a 204 and unlink is DELETE', () async {
    final adapter = FakeHttpAdapter((_) async => jsonResponse(204, null));
    final repo = repoWith(adapter);
    final link = await repo.link(
      7,
      const GoogleCourse(courseId: '6210', name: 'คณิต'),
    );
    expect(link.courseName, 'คณิต');
    await repo.unlink(7);
    expect(adapter.requests.last.method, 'DELETE');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/google-link');
  });

  test('roster reads suggested and matched pairs', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, [
        {
          'google_user_id': '1001',
          'name': 'สมหญิง รักเรียน',
          'email': 'somying@gmail.com',
          'suggested_student_id': 4567,
          'matched_student_id': null,
        },
        {
          'google_user_id': '1002',
          'name': 'Somchai',
          'email': null,
          'suggested_student_id': 4568,
          'matched_student_id': 4569,
        },
      ]),
    );
    final rows = await repoWith(adapter).roster(7);
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/classrooms/7/google-roster',
    );
    expect(rows.first.initialStudentId, 4567);
    expect(
      rows.last.initialStudentId,
      4569,
      reason: 'a saved pair wins over the suggestion',
    );
  });

  test(
    'saveRoster puts {matches: [{google_user_id, student_id|null}]}',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(200, {'ok': true}),
      );
      await repoWith(adapter).saveRoster(7, {'1001': 4567, '1002': null});
      final req = adapter.requests.single;
      expect(req.method, 'PUT');
      expect(req.uri.path, '/api/v1/classrooms/7/google-roster');
      expect(req.data, {
        'matches': [
          {'google_user_id': '1001', 'student_id': 4567},
          {'google_user_id': '1002', 'student_id': null},
        ],
      });
    },
  );

  test('post sends attach_blank_worksheet and reads the courseWork', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'course_work_id': '9001',
        'alternate_link': 'https://classroom.google.com/c/x/a/y/details',
      }),
    );
    final link = await repoWith(adapter).post(
      12,
      attachBlankWorksheet: true,
      instructions: '  ทำข้อ 1-5  ',
      dueAt: DateTime.utc(2026, 10, 1, 9),
    );
    final req = adapter.requests.single;
    expect(req.uri.path, '/api/v1/assignments/12/google-post');
    expect(req.data, {
      'attach_blank_worksheet': true,
      'instructions': 'ทำข้อ 1-5',
      'due_at': '2026-10-01T09:00:00.000Z',
    });
    expect(link.courseWorkId, '9001');
    expect(link.alternateLink, contains('classroom.google.com'));
    expect(link.hasBlankWorksheet, isTrue);
  });

  test('post leaves out empty instructions and a missing due date', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'course_work_id': '9001',
        'alternate_link': 'https://classroom.google.com/x',
        'drive_file_id': null,
      }),
    );
    final link = await repoWith(
      adapter,
    ).post(12, attachBlankWorksheet: false, instructions: '   ');
    expect(adapter.requests.single.data, {'attach_blank_worksheet': false});
    expect(link.hasBlankWorksheet, isFalse);
  });

  test('a second post is 409 already_posted', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(409, {
        'message': 'posted',
        'errors': null,
        'code': 'already_posted',
      }),
    );
    await expectLater(
      repoWith(adapter).post(12, attachBlankWorksheet: false),
      throwsA(
        isA<DioException>().having(
          googleErrorMessage,
          'message',
          'การบ้านนี้โพสต์ลง Google Classroom แล้ว',
        ),
      ),
    );
  });

  test('submissions reads rows with student, attachments and states', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {
            'id': 31,
            'google_submission_id': 'Cg4I',
            'student': {
              'id': 4567,
              'name': 'ด.ญ. สมหญิง',
              'student_number': 12,
            },
            'state': 'new',
            'attachments': [
              {
                'drive_file_id': 'f1',
                'title': 'IMG_1.HEIC',
                'mime_type': 'image/heic',
              },
              {
                'drive_file_id': 'f2',
                'title': 'งาน.pdf',
                'mime_type': 'application/pdf',
              },
            ],
            'alternate_link': 'https://classroom.google.com/s/31',
            'retake_reason': null,
          },
          {
            'id': 32,
            'google_submission_id': 'Cg4J',
            'student': null,
            'state': 'grade_failed',
            'attachments': [],
            'alternate_link': null,
            'retake_reason': '',
            'last_error': 'ProjectPermissionDenied',
          },
        ],
        'next_cursor': null,
      }),
    );
    final rows = await repoWith(adapter).submissions(12);
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/assignments/12/google-submissions',
    );
    final first = rows.first;
    expect(first.state, SubmissionImportState.newSubmission);
    expect(first.student!.label, 'ด.ญ. สมหญิง (เลขที่ 12)');
    expect(first.attachments.map((a) => a.needsRasterize), [true, true]);
    expect(first.attachments.last.isPdf, isTrue);
    final second = rows.last;
    expect(second.studentLabel, 'ยังไม่ได้จับคู่นักเรียน');
    expect(second.state, SubmissionImportState.gradeFailed);
    expect(second.retakeReason, isNull);
    expect(second.lastError, 'ProjectPermissionDenied');
  });

  test('returnForRetake posts {reason} and reads the updated row', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'id': 31,
        'google_submission_id': 'Cg4I',
        'student': null,
        'state': 'returned_for_retake',
        'attachments': [],
        'retake_reason': 'มองไม่เห็นมุมล่างขวา',
      }),
    );
    final row = await repoWith(
      adapter,
    ).returnForRetake(31, 'มองไม่เห็นมุมล่างขวา');
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/google-submissions/31/return');
    expect(req.data, {'reason': 'มองไม่เห็นมุมล่างขวา'});
    expect(row!.state, SubmissionImportState.returnedForRetake);
    expect(row.retakeReason, 'มองไม่เห็นมุมล่างขวา');
  });

  test('retryGrades posts and reads the queued count when given', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(202, {'queued': 3}),
    );
    final queued = await repoWith(adapter).retryGrades(12);
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/assignments/12/google-grades/retry',
    );
    expect(queued, 3);
  });

  test('reconnect codes are recognised', () {
    DioException error(String code) => DioException(
      requestOptions: RequestOptions(path: '/google/courses'),
      response: Response(
        requestOptions: RequestOptions(path: '/google/courses'),
        statusCode: 409,
        data: {'message': 'x', 'errors': null, 'code': code},
      ),
    );
    expect(isGoogleReconnectError(error('google_reconnect_required')), isTrue);
    expect(isGoogleReconnectError(error('google_not_connected')), isTrue);
    expect(isGoogleReconnectError(error('already_posted')), isFalse);
    expect(googleErrorMessage(error('invalid_grant')), contains('เชื่อมใหม่'));
  });

  test('classroom and assignment JSON carry the optional google_link', () {
    final c = Classroom.fromJson({
      'id': 7,
      'name': 'ป.5/1',
      'grade_level': 5,
      'academic_year': 2569,
      'class_code': 'ABC123',
      'google_link': {'course_id': '6210', 'course_name': 'คณิต ป.5/1'},
    });
    expect(c.googleLink!.courseName, 'คณิต ป.5/1');
    expect(c.withGoogleLink(null).googleLink, isNull);

    final a = Assignment.fromJson({
      'id': 12,
      'classroom_id': 7,
      'subject_id': 1,
      'title': 'เศษส่วน',
      'status': 'ready',
      'google_link': {
        'course_work_id': '9001',
        'alternate_link': 'https://classroom.google.com/x',
        'drive_file_id': 'drv1',
        'posted_at': '2026-09-25T03:00:00Z',
      },
    });
    expect(a.googleLink!.hasBlankWorksheet, isTrue);
    expect(a.googleLink!.postedAt, DateTime.utc(2026, 9, 25, 3));
    expect(
      Assignment.fromJson({
        'id': 1,
        'classroom_id': 1,
        'subject_id': 1,
        'title': 't',
      }).googleLink,
      isNull,
    );
  });
}
