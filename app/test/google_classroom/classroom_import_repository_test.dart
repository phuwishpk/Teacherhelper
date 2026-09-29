import 'package:dio/dio.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

/// Request/response shapes of importing a classroom from Google Classroom
/// and syncing its roster (DESIGN §19.2, §19.9) with a fake Dio.
void main() {
  ApiGoogleClassroomRepository repoWith(FakeHttpAdapter adapter) =>
      ApiGoogleClassroomRepository(fakeDio(adapter));

  test('courses read linked_classroom (own, another teacher, none)', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {
            'course_id': '6210',
            'name': 'คณิต ป.5/1',
            'linked_classroom_id': 7,
            'linked_classroom': {'id': 7, 'name': 'ป.5/1'},
          },
          {
            'course_id': '6211',
            'name': 'คณิต ป.5/2',
            'linked_classroom_id': null,
            'linked_classroom': {'id': null, 'name': 'ห้องเรียนของครูท่านอื่น'},
          },
          {
            'course_id': '6212',
            'name': 'คณิต ป.5/3',
            'linked_classroom_id': null,
            'linked_classroom': null,
          },
          // An older server: only the id.
          {'course_id': '6213', 'name': 'วิทย์', 'linked_classroom_id': 9},
        ],
      }),
    );
    final courses = await repoWith(adapter).courses();
    expect(courses[0].linkedClassroom?.id, 7);
    expect(courses[0].linkedClassroom?.name, 'ป.5/1');
    expect(courses[1].isLinked, isTrue);
    expect(courses[1].linkedClassroom?.id, isNull);
    expect(courses[1].linkedClassroom?.name, LinkedClassroom.otherTeacher);
    expect(courses[2].isLinked, isFalse);
    expect(courses[3].linkedClassroom?.id, 9);
  });

  test('importPreview reads the proposal sorted by proposed_number', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'course_id': '6210',
          'name': 'คณิต',
          'section': 'ป.5/1',
          'suggested_name': 'คณิต ป.5/1',
          'grade_level_guess': 5,
          'academic_year': 2569,
          'students': [
            {
              'google_user_id': 'g2',
              'name': 'ด.ญ. ขวัญ ใจ',
              'email': 'k@school.ac.th',
              'proposed_number': 2,
            },
            {
              'google_user_id': 'g1',
              'name': 'ด.ช. กร ดี',
              'email': null,
              'proposed_number': 1,
            },
          ],
        },
      }),
    );
    final preview = await repoWith(adapter).importPreview('6210');
    final req = adapter.requests.single;
    expect(req.method, 'GET');
    expect(req.uri.path, '/api/v1/google/courses/6210/import-preview');
    expect(req.receiveTimeout, const Duration(seconds: 60));
    expect(preview.suggestedName, 'คณิต ป.5/1');
    expect(preview.section, 'ป.5/1');
    expect(preview.gradeLevelGuess, 5);
    expect(preview.academicYear, 2569);
    expect(preview.students.map((s) => s.googleUserId), ['g1', 'g2']);
    expect(preview.students.first.email, isNull);
    expect(preview.students.last.email, 'k@school.ac.th');
  });

  test('importPreview: no guess, no section, no suggested name', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'course_id': '6210',
          'name': 'ชุมนุมหุ่นยนต์',
          'section': null,
          'grade_level_guess': null,
          'academic_year': 2569,
          'students': <Object>[],
        },
      }),
    );
    final preview = await repoWith(adapter).importPreview('6210');
    expect(preview.gradeLevelGuess, isNull);
    expect(preview.section, isNull);
    expect(preview.suggestedName, 'ชุมนุมหุ่นยนต์');
    expect(preview.students, isEmpty);
  });

  test('importPreview surfaces 409 course_already_linked', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(409, {
        'message': 'คอร์สนี้ผูกกับห้องเรียนแล้ว',
        'errors': <String, Object>{},
        'code': 'course_already_linked',
      }),
    );
    Object? error;
    try {
      await repoWith(adapter).importPreview('6210');
    } catch (e) {
      error = e;
    }
    expect(error, isA<DioException>());
    expect(googleErrorMessage(error!), contains('ผูกกับห้องเรียนในแอปแล้ว'));
  });

  test('importClassroom posts ids and numbers only, reads PINs', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'data': {
          'classroom': {
            'id': 70,
            'name': 'คณิต ป.5/1',
            'grade_level': 5,
            'academic_year': 2569,
            'class_code': 'GCL001',
            'students_count': 1,
            'google_link': {
              'course_id': '6210',
              'course_name': 'คณิต',
              'linked_at': '2026-09-30T02:00:00+00:00',
            },
          },
          'students': [
            {
              'student_id': 501,
              'student_number': 1,
              'name': 'ด.ช. กร ดี',
              'pin': '004821',
            },
          ],
        },
      }),
    );
    final result = await repoWith(adapter).importClassroom(
      const ClassroomImportRequest(
        courseId: '6210',
        name: 'คณิต ป.5/1',
        gradeLevel: 5,
        academicYear: 2569,
        numbers: {'g1': 1},
        removed: ['g9'],
      ),
    );
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/classrooms/import-google');
    expect(req.data, {
      'course_id': '6210',
      'name': 'คณิต ป.5/1',
      'grade_level': 5,
      'academic_year': 2569,
      'students': [
        {'google_user_id': 'g1', 'student_number': 1},
      ],
      'removed': ['g9'],
    });
    expect(result.classroom.id, 70);
    expect(result.classroom.googleLink?.courseId, '6210');
    expect(result.students.single.pin, '004821');
  });

  test('syncRoster reads added (with PIN), left and rematched', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'added': [
            {
              'student_id': 88,
              'student_number': 31,
              'name': 'ด.ญ. ใหม่ มาก',
              'pin': '771100',
            },
          ],
          'left': [
            {'student_id': 12, 'student_number': 4, 'name': 'ด.ช. ย้าย ไป'},
          ],
          'rematched': <Object>[],
        },
      }),
    );
    final result = await repoWith(adapter).syncRoster(7);
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/classrooms/7/google-roster/sync');
    expect(result.unchanged, isFalse);
    expect(result.added.single.pin, '771100');
    expect(result.left.single.studentNumber, 4);
    expect(result.rematched, isEmpty);
  });

  test('an empty sync is unchanged', () {
    expect(
      RosterSyncResult.fromJson({
        'added': <Object>[],
        'left': <Object>[],
        'rematched': <Object>[],
      }).unchanged,
      isTrue,
    );
    expect(RosterSyncResult.fromJson(const {}).unchanged, isTrue);
  });

  test('roster rows read left_course_at', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {
            'student_id': 12,
            'student_number': 4,
            'name': 'ด.ช. ย้าย ไป',
            'status': 'active',
            'left_course_at': '2026-09-29T03:00:00+00:00',
          },
          {
            'student_id': 13,
            'student_number': 5,
            'name': 'ด.ญ. อยู่ ต่อ',
            'status': 'active',
            'left_course_at': null,
          },
        ],
        'meta': {'current_page': 1, 'last_page': 1},
      }),
    );
    final roster = await ApiClassroomsRepository(fakeDio(adapter)).roster(7);
    expect(roster.first.leftCourse, isTrue);
    expect(roster.first.leftCourseAt, DateTime.utc(2026, 9, 29, 3));
    expect(roster.last.leftCourse, isFalse);
  });

  test('RosterStudent without left_course_at stays in the course', () {
    final s = RosterStudent.fromJson({
      'student_id': 1,
      'student_number': 1,
      'name': 'ก',
    });
    expect(s.leftCourse, isFalse);
  });
}
