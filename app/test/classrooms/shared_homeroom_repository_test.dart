import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/push/push_routes.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/course_requests.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/home/teacher_attention.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'classroom_fakes.dart';

/// Request/response shapes of the build 2 endpoints of DESIGN §24.12 B
/// (shared homerooms), as the backend's controllers answer them (§24.20).
void main() {
  Map<String, Object?> requestJson({
    int id = 31,
    String status = 'pending',
    int roomGrade = 5,
    int courseGrade = 4,
  }) => {
    'id': id,
    'classroom': {
      'id': 7,
      'name': 'ป.5/2',
      'grade_level': roomGrade,
      'academic_year': 2569,
      'closed': false,
      'homeroom_teacher': {'id': 3, 'name': 'ครูมาลี'},
    },
    'course': {
      'id': 40,
      'code': 'ว15101',
      'name': 'วิทยาศาสตร์ 5',
      'grade_level': courseGrade,
      'semester': 1,
      'academic_year': 2569,
    },
    'requester': {'id': 9, 'name': 'ครูสมศักดิ์'},
    'origin': 'teacher',
    'status': status,
    'message': 'ขอสอนวิทย์ค่ะ',
    'google_course_name': null,
    'decided_at': status == 'pending' ? null : '2026-10-01T04:00:00+00:00',
    'decline_reason': status == 'declined' ? 'มีครูสอนแล้ว' : null,
    'created_at': '2026-10-01T03:00:00+00:00',
  };

  test('the directory, the request and its answers (DESIGN §24.20)', () async {
    final adapter = FakeHttpAdapter((options) async {
      final path = options.uri.path;
      if (path.endsWith('/classrooms/directory')) {
        return jsonResponse(200, {
          'data': [
            {
              'id': 7,
              'name': 'ป.5/2',
              'grade_level': 5,
              'academic_year': 2569,
              'homeroom_teacher': {'id': 3, 'name': 'ครูมาลี'},
              'students_count': 31,
              'my_role': null,
            },
            {
              'id': 8,
              'name': 'ป.5/3',
              'grade_level': 5,
              'academic_year': 2569,
              'homeroom_teacher': {'id': 9, 'name': 'ครูสมศักดิ์'},
              'students_count': 0,
              'my_role': 'homeroom',
            },
          ],
        });
      }
      if (path.endsWith('/classrooms/8/course-requests')) {
        return jsonResponse(200, {
          'data': {'bound': true, 'classroom_id': 8, 'course_id': 40},
        });
      }
      if (path.endsWith('/course-requests') && options.method == 'POST') {
        return jsonResponse(201, {'data': requestJson()});
      }
      if (path.endsWith('/course-requests') && options.method == 'GET') {
        return jsonResponse(200, {
          'data': [requestJson(), requestJson(id: 30, status: 'declined')],
        });
      }
      if (path.endsWith('/approve')) {
        return jsonResponse(200, {'data': requestJson(status: 'approved')});
      }
      if (path.endsWith('/decline')) {
        return jsonResponse(200, {'data': requestJson(status: 'declined')});
      }
      return jsonResponse(204, null);
    });
    final repo = ApiCourseRequestsRepository(fakeDio(adapter));

    final rooms = await repo.directory(q: ' ป.5 ');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/directory');
    expect(adapter.requests.last.uri.queryParameters, {'q': 'ป.5'});
    expect(rooms.first.homeroomTeacher?.name, 'ครูมาลี');
    expect(rooms.first.studentCount, 31);
    expect(rooms.first.myRole, isNull);
    expect(rooms.last.isMine, isTrue);

    await repo.directory();
    expect(adapter.requests.last.uri.queryParameters, isEmpty);

    final sent = await repo.request(7, courseId: 40, message: '  ขอสอน ');
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/classrooms/7/course-requests',
    );
    expect(adapter.requests.last.data, {'course_id': 40, 'message': 'ขอสอน'});
    expect(sent.bound, isFalse);
    final r = sent.request!;
    expect(r.isPending, isTrue);
    expect(r.course?.title, 'ว15101 วิทยาศาสตร์ 5');
    expect(r.requester?.name, 'ครูสมศักดิ์');
    expect(r.classroom?.homeroomTeacher?.name, 'ครูมาลี');
    expect(r.message, 'ขอสอนวิทย์ค่ะ');
    expect(r.createdAt, DateTime.utc(2026, 10, 1, 3));
    expect(r.gradeMismatch, isTrue);

    final bound = await repo.request(8, courseId: 40, message: ' ');
    expect(adapter.requests.last.data, {'course_id': 40});
    expect(bound.bound, isTrue);

    final incoming = await repo.list(CourseRequestBox.incoming);
    expect(adapter.requests.last.uri.queryParameters, {'box': 'incoming'});
    expect(incoming.last.status, CourseRequestStatus.declined);
    expect(incoming.last.declineReason, 'มีครูสอนแล้ว');
    await repo.list(CourseRequestBox.outgoing);
    expect(adapter.requests.last.uri.queryParameters, {'box': 'outgoing'});

    final approved = await repo.approve(31);
    expect(adapter.requests.last.method, 'POST');
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/course-requests/31/approve',
    );
    expect(approved.status, CourseRequestStatus.approved);
    expect(approved.decidedAt, isNotNull);

    await repo.decline(31, reason: ' เต็มแล้ว ');
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/course-requests/31/decline',
    );
    expect(adapter.requests.last.data, {'reason': 'เต็มแล้ว'});
    await repo.decline(31);
    expect(adapter.requests.last.data, isEmpty);

    await repo.cancel(31);
    expect(adapter.requests.last.method, 'DELETE');
    expect(adapter.requests.last.uri.path, '/api/v1/course-requests/31');
  });

  test('courses taught in a room and unbinding (DESIGN §24.12 B)', () async {
    final adapter = FakeHttpAdapter((options) async {
      if (options.method == 'DELETE') return jsonResponse(204, null);
      return jsonResponse(200, {
        'data': [
          {
            'course': {
              'id': 4,
              'code': 'ค15101',
              'name': 'คณิตศาสตร์ 5',
              'subject': {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
              'grade_level': 5,
              'semester': 1,
              'academic_year': 2569,
            },
            'teacher': {'id': 1, 'name': 'ครูมาลี'},
            'is_mine': true,
          },
          {
            'course': {
              'id': 40,
              'code': 'ว15101',
              'name': 'วิทยาศาสตร์ 5',
              'subject': null,
              'grade_level': 5,
              'semester': 0,
              'academic_year': 2569,
            },
            'teacher': {'id': 9, 'name': 'ครูสมศักดิ์'},
            'is_mine': false,
          },
        ],
      });
    });
    final repo = ApiCoursesRepository(fakeDio(adapter));
    final courses = await repo.taughtIn(7);
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/courses');
    expect(courses.first.isMine, isTrue);
    expect(courses.first.subjectName, 'คณิตศาสตร์');
    expect(courses.first.termLabel, 'ป.5 · ภาคเรียนที่ 1 · 2569');
    expect(courses.last.isMine, isFalse);
    expect(courses.last.teacher?.name, 'ครูสมศักดิ์');
    expect(courses.last.title, 'ว15101 วิทยาศาสตร์ 5');

    await repo.unbind(7, 40);
    expect(adapter.requests.last.method, 'DELETE');
    expect(adapter.requests.last.uri.path, '/api/v1/classrooms/7/courses/40');
  });

  test('a subject teacher asks for a student\'s mastery of a course', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {'data': []}),
    );
    final repo = ApiMasteryRepository(fakeDio(adapter));
    await repo.student(55, courseId: 40);
    expect(adapter.requests.last.uri.path, '/api/v1/students/55/mastery');
    expect(adapter.requests.last.uri.queryParameters, {'course_id': '40'});
    await repo.student(55);
    expect(adapter.requests.last.uri.queryParameters, isEmpty);
  });

  test('homeroom_teacher on a shared classroom', () {
    final c = Classroom.fromJson({
      'id': 8,
      'name': 'ม.1/1',
      'grade_level': 7,
      'academic_year': 2569,
      'class_code': 'X1Y2Z3',
      'my_role': 'subject',
      'homeroom_teacher': {'id': 3, 'name': 'ครูมาลี'},
    });
    expect(c.isSubject, isTrue);
    expect(c.isHomeroom, isFalse);
    expect(c.homeroomTeacher?.name, 'ครูมาลี');
    expect(c.withGoogleLink(null).homeroomTeacher?.id, 3);
    expect(TeacherRef.maybe(null), isNull);
    expect(TeacherRef.maybe('x'), isNull);

    final room = DirectoryClassroom.of(c);
    expect(room.myRole, ClassroomRole.subject);
    expect(room.isMine, isFalse);
  });

  test('course_requests_pending on the attention card', () {
    final a = TeacherAttention.fromJson({'course_requests_pending': 2});
    expect(a.courseRequestsPending, 2);
    expect(a.isEmpty, isFalse);
    expect(TeacherAttention.fromJson({}).isEmpty, isTrue);
  });

  test('can_manage of another teacher\'s work (DESIGN §24.20)', () {
    Map<String, Object?> json(Object? canManage) => {
      'id': 12,
      'classroom_id': 7,
      'title': 'ใบงาน 1',
      'can_manage': ?canManage,
    };
    expect(Assignment.fromJson(json(false)).canManage, isFalse);
    expect(
      Assignment.fromJson(json(false)).withGoogleLink(null).canManage,
      isFalse,
    );
    expect(Assignment.fromJson(json(true)).canManage, isTrue);
    expect(Assignment.fromJson(json(null)).canManage, isTrue);
  });

  test('course request pushes open the requests (DESIGN §24.20)', () {
    const teacher = User(id: 1, name: 'ครู', role: 'teacher');
    const student = User(id: 2, name: 'นักเรียน', role: 'student');
    expect(
      routeForPush({'type': 'course_request', 'request_id': '31'}, teacher),
      '/course-requests',
    );
    expect(
      routeForPush({'type': 'course_request_decided'}, teacher),
      '/course-requests?box=outgoing',
    );
    expect(routeForPush({'type': 'course_request'}, student), isNull);
  });

  test('Thai messages for the request error codes', () {
    String text(String code) => courseRequestErrorText(
      dioError(409, {'message': 'x', 'errors': {}, 'code': code}),
    );
    expect(
      text('course_already_in_classroom'),
      contains('ผูกกับห้องนี้อยู่แล้ว'),
    );
    expect(text('request_pending'), contains('รออนุมัติอยู่แล้ว'));
    expect(text('classroom_closed'), contains('ห้องเก่า'));
    expect(text('request_closed'), contains('ตัดสินหรือยกเลิก'));
    expect(text('request_busy'), contains('ลองใหม่'));
    expect(text('course_in_use'), contains('ปิดห้อง'));
    expect(text('other'), 'x');
    expect(gradeMismatchText(courseGrade: 5, roomGrade: 5), isNull);
    expect(gradeMismatchText(courseGrade: 4, roomGrade: 5), contains('(ป.4)'));
    expect(CourseRequestStatus.fromApi('declined').label, 'ไม่อนุมัติ');
    expect(CourseRequestStatus.fromApi('?'), CourseRequestStatus.pending);
  });
}
