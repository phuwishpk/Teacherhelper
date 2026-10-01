import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../classrooms/classroom_fakes.dart';
import '../helpers/fake_http_adapter.dart';

/// Request/response shapes of build 4 (DESIGN §24.10, §24.12 D, §24.24):
/// the import preview with matches and a suggested room, link-existing,
/// import with existing accounts, the new roster sync rows and
/// "นำนักเรียนจากห้องเดิม".
void main() {
  ApiGoogleClassroomRepository repoWith(FakeHttpAdapter adapter) =>
      ApiGoogleClassroomRepository(fakeDio(adapter));

  const classroomJson = {
    'id': 7,
    'name': 'ป.5/1',
    'grade_level': 5,
    'academic_year': 2569,
    'class_code': 'ABC123',
    'students_count': 30,
    'my_role': 'homeroom',
    'google_link': {'course_id': '6210', 'course_name': 'คณิต'},
  };

  test('importPreview reads match and suggested_classroom', () async {
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
              'google_user_id': 'g1',
              'name': 'ด.ช. กร ดี',
              'email': 'korn@school.ac.th',
              'proposed_number': 1,
              'match': {
                'student_id': 501,
                'name': 'ด.ช. กร ดีมาก',
                'matched_by': 'classroom_user',
                'classes': [
                  {
                    'id': 3,
                    'name': 'ป.4/1',
                    'academic_year': 2568,
                    'student_number': 4,
                    'closed': true,
                  },
                ],
              },
            },
            {
              'google_user_id': 'g2',
              'name': 'ด.ญ. ขวัญ ใจ',
              'email': null,
              'proposed_number': 2,
              'match': null,
            },
            {
              'google_user_id': 'g3',
              'name': 'ด.ช. ใหม่',
              'proposed_number': 3,
              'match': {
                'student_id': 502,
                'name': 'ด.ช. ใหม่',
                'matched_by': 'something_new',
                'classes': <Object>[],
              },
            },
          ],
          'suggested_classroom': {
            'id': 7,
            'name': 'ป.5/1',
            'academic_year': 2569,
            'homeroom_teacher': {'id': 2, 'name': 'ครูมาลี'},
            'coverage': 0.8571,
            'matched': 24,
            'owned_by_me': false,
          },
        },
      }),
    );
    final preview = await repoWith(adapter).importPreview('6210');
    final korn = preview.students.first;
    expect(korn.match?.studentId, 501);
    expect(korn.match?.matchedBy, ImportMatchKind.classroomUser);
    expect(korn.match?.classesLabel, 'ป.4/1 ปี 2568 เลขที่ 4 (ห้องเก่า)');
    expect(preview.students[1].match, isNull);
    // An unknown reason counts as the weakest one.
    expect(preview.students[2].match?.matchedBy, ImportMatchKind.name);
    expect(preview.students[2].match?.classesLabel, 'ยังไม่อยู่ในห้องใด');

    final room = preview.suggestedClassroom!;
    expect(room.id, 7);
    expect(room.homeroomTeacher?.name, 'ครูมาลี');
    expect(room.coverageLabel, '86%');
    expect(room.matched, 24);
    expect(room.ownedByMe, isFalse);
  });

  test('a preview without the build 4 fields has no suggestion', () {
    final preview = ClassroomImportPreview.fromJson({
      'course_id': '6210',
      'name': 'คณิต',
      'academic_year': 2569,
      'students': [
        {'google_user_id': 'g1', 'name': 'ก', 'proposed_number': 1},
      ],
    });
    expect(preview.suggestedClassroom, isNull);
    expect(preview.students.single.match, isNull);
  });

  test('importClassroom sends student_id for the existing accounts', () {
    const request = ClassroomImportRequest(
      courseId: '6210',
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      numbers: {'g1': 1, 'g2': 2},
      existing: {'g1': 501},
    );
    expect(request.toJson()['students'], [
      {'google_user_id': 'g1', 'student_number': 1, 'student_id': 501},
      {'google_user_id': 'g2', 'student_number': 2},
    ]);
  });

  test('linkExisting 200: the room, its sync and no error', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'status': 'linked',
          'classroom': classroomJson,
          'google_link': {'id': 3, 'course_id': '6210'},
          'roster': {
            'added': <Object>[],
            'enrolled': [
              {
                'student_id': 501,
                'student_number': 31,
                'name': 'ด.ช. กร ดี',
                'pin': null,
              },
            ],
            'left': <Object>[],
            'rematched': <Object>[],
            'not_in_classroom': <Object>[],
          },
          'roster_error': null,
        },
      }),
    );
    final result = await repoWith(
      adapter,
    ).linkExisting('6210', classroomId: 7, appCourseId: 4);
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/google/courses/6210/link-existing');
    expect(req.data, {'classroom_id': 7, 'app_course_id': 4});
    expect(req.receiveTimeout, const Duration(seconds: 60));
    expect(result, isA<LinkedExisting>());
    final linked = result as LinkedExisting;
    expect(linked.classroom.id, 7);
    expect(linked.rosterError, isNull);
    expect(linked.roster?.enrolled.single.existing, isFalse);
    expect(linked.roster?.enrolled.single.hasPin, isFalse);
    expect(linked.roster?.withPins, isEmpty);
    expect(linked.roster?.unchanged, isFalse);
  });

  test('linkExisting 200 with a failed sync keeps the message', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'status': 'linked',
          'classroom': classroomJson,
          'roster': null,
          'roster_error': {
            'code': 'google_reconnect_required',
            'message': 'ต้องเชื่อมบัญชี Google ใหม่',
          },
        },
      }),
    );
    final result =
        await repoWith(adapter).linkExisting('6210', classroomId: 7)
            as LinkedExisting;
    expect(adapter.requests.single.data, {'classroom_id': 7});
    expect(result.roster, isNull);
    expect(result.rosterError, 'ต้องเชื่อมบัญชี Google ใหม่');
  });

  test('linkExisting 202 is a course request', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(202, {
        'data': {'status': 'requested', 'request_id': 41},
      }),
    );
    final result = await repoWith(
      adapter,
    ).linkExisting('6210', classroomId: 7, appCourseId: 4);
    expect(result, isA<LinkRequested>());
    expect((result as LinkRequested).requestId, 41);
  });

  test('build 4 error codes read in Thai', () {
    for (final (code, text) in [
      ('request_pending', 'รออนุมัติอยู่แล้ว'),
      ('classroom_closed', 'ห้องเก่า'),
      ('course_link_busy', 'รอสักครู่'),
      ('student_not_in_school', 'ไม่พบนักเรียนบางคน'),
      ('course_already_in_classroom', 'ผูกกับห้องนี้อยู่แล้ว'),
    ]) {
      final error = dioError(409, {'message': 'x', 'code': code});
      expect(googleErrorMessage(error), contains(text), reason: code);
    }
  });

  test('sync rows: enrolled with a first PIN and not_in_classroom', () {
    final result = RosterSyncResult.fromJson({
      'added': [
        {'student_id': 88, 'student_number': 3, 'name': 'ใหม่', 'pin': '1'},
      ],
      'enrolled': [
        {'student_id': 90, 'student_number': 4, 'name': 'เดิม', 'pin': null},
        {'student_id': 91, 'student_number': 5, 'name': 'ไม่เคย', 'pin': '2'},
      ],
      'not_in_classroom': [
        {'google_user_id': 'g9', 'name': 'นอกห้อง', 'email': 'x@s.ac.th'},
      ],
    });
    expect(result.withPins.map((s) => s.studentId), [88, 91]);
    expect(result.notInClassroom.single.email, 'x@s.ac.th');
    expect(
      RosterSyncResult.fromJson({
        'not_in_classroom': [
          {'google_user_id': 'g9', 'name': 'นอกห้อง'},
        ],
      }).unchanged,
      isFalse,
    );
  });

  test(
    'copyStudents posts the choice and reads enrolled and skipped',
    () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(201, {
          'data': {
            'enrolled': [
              {
                'student_id': 501,
                'student_number': 1,
                'name': 'ด.ช. กร ดี',
                'student_code': null,
                'status': 'active',
                'pin': '004821',
                'existing': true,
              },
            ],
            'skipped': [
              {'student_id': 502, 'name': 'ด.ญ. ขวัญ', 'reason': 'not_active'},
            ],
          },
        }),
      );
      final result = await ApiClassroomsRepository(fakeDio(adapter))
          .copyStudents(
            9,
            sourceClassroomId: 3,
            studentIds: [501, 502],
            numbering: CopyNumbering.sorted,
            newPins: true,
          );
      final req = adapter.requests.single;
      expect(req.method, 'POST');
      expect(req.uri.path, '/api/v1/classrooms/9/students/from-classroom');
      expect(req.data, {
        'source_classroom_id': 3,
        'student_ids': [501, 502],
        'numbering': 'sorted',
        'pin': 'new',
      });
      expect(result.withPins.single.pin, '004821');
      expect(result.skipped.single.reasonLabel, contains('รวมกับบัญชีอื่น'));
      expect(
        const SkippedStudent(
          studentId: 1,
          name: 'x',
          reason: 'already_enrolled',
        ).reasonLabel,
        'อยู่ในห้องนี้แล้ว',
      );
    },
  );
}
