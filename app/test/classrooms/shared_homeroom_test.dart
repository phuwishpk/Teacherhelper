import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_detail_screen.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_page.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/course_requests.dart';
import 'package:eduvision/features/classrooms/course_requests_screen.dart';
import 'package:eduvision/features/classrooms/request_classroom_screen.dart';
import 'package:eduvision/features/courses/course_form_screen.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/gradebook/gradebook_repository.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/home/teacher_attention.dart';
import 'package:eduvision/features/mastery/classroom_mastery_screen.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:eduvision/features/mastery/student_mastery_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../gradebook/gradebook_fakes.dart';
import '../google_classroom/google_fakes.dart';
import '../helpers/home_fakes.dart';
import '../helpers/pump_screen.dart';
import 'classroom_fakes.dart';

const _malee = TeacherRef(id: 3, name: 'ครูมาลี');
const _somsak = TeacherRef(id: 9, name: 'ครูสมศักดิ์');

/// Room 7 as a subject teacher sees it: ครูมาลี's homeroom.
const _shared = Classroom(
  id: 7,
  name: 'ป.5/2',
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'K7Q3M2',
  studentCount: 2,
  myRole: ClassroomRole.subject,
  homeroomTeacher: _malee,
);

CourseRequest _request(
  int id, {
  CourseRequestStatus status = CourseRequestStatus.pending,
  int courseGrade = 5,
  String? message,
}) => CourseRequest(
  id: id,
  status: status,
  classroom: const RequestClassroom(
    id: 7,
    name: 'ป.5/2',
    gradeLevel: 5,
    academicYear: 2569,
    homeroomTeacher: _malee,
  ),
  course: RequestCourse(
    id: 40 + id,
    code: 'ว15101',
    name: 'วิทยาศาสตร์ 5',
    gradeLevel: courseGrade,
  ),
  requester: _somsak,
  message: message,
  createdAt: DateTime.utc(2026, 10, 1, 3),
  decidedAt: status == CourseRequestStatus.pending
      ? null
      : DateTime.utc(2026, 10, 1, 4),
  declineReason: status == CourseRequestStatus.declined ? 'มีครูสอนแล้ว' : null,
);

/// The request endpoints of DESIGN §24.12 B in memory, recording calls.
class FakeCourseRequests implements CourseRequestsRepository {
  FakeCourseRequests({
    List<DirectoryClassroom>? rooms,
    Map<CourseRequestBox, List<CourseRequest>>? boxes,
  }) : rooms = rooms ?? const [],
       boxes = boxes ?? {};

  List<DirectoryClassroom> rooms;
  Map<CourseRequestBox, List<CourseRequest>> boxes;
  final calls = <String>[];
  Object? requestError;
  Object? cancelError;
  CourseRequestResult? requestResult;

  @override
  Future<List<DirectoryClassroom>> directory({
    String? q,
    int? academicYear,
  }) async {
    calls.add('directory:${q ?? ''}');
    return [
      for (final r in rooms)
        if (q == null || q.isEmpty || r.name.contains(q)) r,
    ];
  }

  @override
  Future<CourseRequestResult> request(
    int classroomId, {
    required int courseId,
    String? message,
  }) async {
    calls.add('request:$classroomId:$courseId:${message ?? ''}');
    if (requestError case final e?) throw e;
    return requestResult ??
        CourseRequestResult.sent(_request(99, message: message));
  }

  @override
  Future<List<CourseRequest>> list(CourseRequestBox box) async =>
      boxes[box] ?? const [];

  void _decide(int id, CourseRequestStatus status) {
    for (final box in boxes.keys) {
      boxes[box] = [
        for (final r in boxes[box]!)
          r.id == id ? _request(id, status: status) : r,
      ];
    }
  }

  @override
  Future<CourseRequest> approve(int id) async {
    calls.add('approve:$id');
    _decide(id, CourseRequestStatus.approved);
    return _request(id, status: CourseRequestStatus.approved);
  }

  @override
  Future<CourseRequest> decline(int id, {String? reason}) async {
    calls.add('decline:$id:${reason ?? ''}');
    _decide(id, CourseRequestStatus.declined);
    return _request(id, status: CourseRequestStatus.declined);
  }

  @override
  Future<void> cancel(int id) async {
    calls.add('cancel:$id');
    if (cancelError case final e?) throw e;
    _decide(id, CourseRequestStatus.cancelled);
  }
}

class _Mastery implements MasteryRepository {
  final classroomCalls = <(int, int?, int?)>[];
  final studentCalls = <(int, int?)>[];

  @override
  Future<ClassroomMastery> classroom(
    int classroomId, {
    int? courseId,
    int? unitId,
  }) async {
    classroomCalls.add((classroomId, courseId, unitId));
    return ClassroomMastery.fromJson(const {});
  }

  @override
  Future<MasteryList> mine() async => const MasteryList(rows: []);

  @override
  Future<List<SkillMastery>> student(int studentId, {int? courseId}) async {
    studentCalls.add((studentId, courseId));
    return const [];
  }
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.assignment);

  final Assignment assignment;

  @override
  Future<Assignment> get(int id) async => assignment;
}

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Finder _inDialog(String text) =>
    find.descendant(of: find.byType(AlertDialog), matching: find.text(text));

List<Override> _base({
  FakeCourseRequests? requests,
  ClassroomsRepository? classrooms,
  CoursesRepository? courses,
  TeacherAttention attention = const TeacherAttention(),
}) => [
  courseRequestsRepositoryProvider.overrideWithValue(
    requests ?? FakeCourseRequests(),
  ),
  classroomsRepositoryProvider.overrideWithValue(
    classrooms ?? FakeSchoolClassrooms(),
  ),
  coursesRepositoryProvider.overrideWithValue(
    courses ?? FakeCoursesRepository([course(id: 4)]),
  ),
  teacherAttentionRepositoryProvider.overrideWithValue(
    FakeTeacherAttentionRepository(attention),
  ),
];

void main() {
  group('คำขอผูกรายวิชา (DESIGN §24.7)', () {
    testWidgets('the homeroom teacher approves and declines', (tester) async {
      _tall(tester);
      final requests = FakeCourseRequests(
        boxes: {
          CourseRequestBox.incoming: [
            _request(31, courseGrade: 4, message: 'ขอสอนวิทย์ค่ะ'),
            _request(32),
            _request(30, status: CourseRequestStatus.declined),
          ],
        },
      );
      await pumpScreen(
        tester,
        const CourseRequestsScreen(),
        overrides: _base(requests: requests),
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id',
            builder: (_, state) => Text('room ${state.pathParameters['id']}'),
          ),
        ],
      );

      expect(find.text('รอดำเนินการ (2)'), findsOneWidget);
      expect(find.text('ตัดสินแล้ว'), findsOneWidget);
      expect(find.textContaining('ครูผู้สอน ครูสมศักดิ์'), findsNWidgets(3));
      expect(find.text('"ขอสอนวิทย์ค่ะ"'), findsOneWidget);
      // The course is ป.4, the room ป.5: a warning, not a refusal.
      expect(find.byKey(const ValueKey('request_mismatch_31')), findsOneWidget);
      expect(find.byKey(const ValueKey('request_mismatch_32')), findsNothing);
      expect(find.text('เหตุผลที่ไม่อนุมัติ: มีครูสอนแล้ว'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('approve_31')));
      await tester.pumpAndSettle();
      expect(find.text('อนุมัติ ว15101 วิทยาศาสตร์ 5?'), findsOneWidget);
      await tester.tap(_inDialog('อนุมัติ'));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('approve:31'));
      expect(find.text('อนุมัติแล้ว'), findsWidgets);
      expect(find.text('รอดำเนินการ (1)'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('decline_32')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('decline_reason')),
        'มีครูสอนแล้ว',
      );
      await tester.tap(find.byKey(const ValueKey('decline_confirm')));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('decline:32:มีครูสอนแล้ว'));
      expect(find.textContaining('รอดำเนินการ ('), findsNothing);

      await tester.ensureVisible(find.byKey(const ValueKey('open_room_31')));
      await tester.tap(find.byKey(const ValueKey('open_room_31')));
      await tester.pumpAndSettle();
      expect(find.text('room 7'), findsOneWidget);
    });

    testWidgets('the requester cancels a pending request', (tester) async {
      _tall(tester);
      final requests = FakeCourseRequests(
        boxes: {
          CourseRequestBox.outgoing: [_request(41), _request(42)],
        },
      );
      await pumpScreen(
        tester,
        const CourseRequestsScreen(initialBox: CourseRequestBox.outgoing),
        overrides: _base(requests: requests),
        extraRoutes: [
          GoRoute(
            path: '/course-requests/new',
            builder: (_, _) => const Text('new request'),
          ),
        ],
      );
      expect(find.textContaining('ครูประจำชั้น ครูมาลี'), findsNWidgets(2));

      await tester.tap(find.byKey(const ValueKey('cancel_41')));
      await tester.pumpAndSettle();
      await tester.tap(_inDialog('ยกเลิกคำขอ'));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('cancel:41'));
      expect(find.text('ยกเลิกแล้ว'), findsOneWidget);

      // Decided elsewhere meanwhile: 409 request_closed.
      requests.cancelError = dioError(409, {
        'message': 'closed',
        'errors': {},
        'code': 'request_closed',
      });
      await tester.tap(find.byKey(const ValueKey('cancel_42')));
      await tester.pumpAndSettle();
      await tester.tap(_inDialog('ยกเลิกคำขอ'));
      await tester.pumpAndSettle();
      expect(find.text('คำขอนี้ถูกตัดสินหรือยกเลิกไปแล้ว'), findsOneWidget);

      // The incoming tab is empty.
      await tester.tap(find.byKey(const ValueKey('tab_incoming')));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่มีคำขอ'), findsOneWidget);

      await tester.tap(find.text('ขอสอนห้องของครูท่านอื่น'));
      await tester.pumpAndSettle();
      expect(find.text('new request'), findsOneWidget);
    });
  });

  group('ขอสอนห้องของครูท่านอื่น (DESIGN §24.7 step 1)', () {
    const room7 = DirectoryClassroom(
      id: 7,
      name: 'ป.6/1',
      gradeLevel: 6,
      academicYear: 2569,
      homeroomTeacher: _malee,
      studentCount: 30,
    );
    const room8 = DirectoryClassroom(
      id: 8,
      name: 'ป.5/3',
      gradeLevel: 5,
      academicYear: 2569,
      homeroomTeacher: _somsak,
      myRole: ClassroomRole.homeroom,
    );
    FakeCoursesRepository courses() => FakeCoursesRepository([
      course(id: 4, classrooms: const []),
      course(
        id: 5,
        code: 'ค15102',
        classrooms: const [
          {'id': 7, 'name': 'ป.6/1'},
        ],
      ),
    ]);

    testWidgets('pick a room, the course is preselected, send', (tester) async {
      _tall(tester);
      final requests = FakeCourseRequests(rooms: [room7, room8]);
      await pumpScreen(
        tester,
        const RequestClassroomScreen(courseId: 4),
        overrides: _base(requests: requests, courses: courses()),
      );
      expect(
        find.textContaining('ครูประจำชั้น ครูมาลี · นักเรียน 30 คน'),
        findsOneWidget,
      );
      expect(find.text('ห้องของคุณ'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('directory_query')),
        'ป.6',
      );
      await tester.tap(find.byKey(const ValueKey('directory_search')));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('directory:ป.6'));
      expect(find.byKey(const ValueKey('directory_room_8')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('directory_room_7')));
      await tester.pumpAndSettle();
      expect(find.text('ขอสอนห้อง ป.6/1'), findsOneWidget);
      // ป.5 course, ป.6 room.
      expect(
        find.byKey(const ValueKey('request_grade_warning')),
        findsOneWidget,
      );
      await tester.enterText(
        find.byKey(const ValueKey('request_message')),
        'ขอสอนคณิต',
      );
      await tester.tap(find.byKey(const ValueKey('request_send')));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('request:7:4:ขอสอนคณิต'));
      expect(find.text('stub-home'), findsOneWidget);
      expect(
        find.text('ส่งคำขอถึงครูประจำชั้นของห้อง ป.6/1 แล้ว'),
        findsOneWidget,
      );
    });

    testWidgets('a bound course is disabled; errors stay in the dialog; '
        'one\'s own room binds at once', (tester) async {
      _tall(tester);
      final requests = FakeCourseRequests(rooms: [room7, room8])
        ..requestError = dioError(409, {
          'message': 'pending',
          'errors': {},
          'code': 'request_pending',
        });
      await pumpScreen(
        tester,
        const RequestClassroomScreen(),
        overrides: _base(requests: requests, courses: courses()),
      );

      await tester.tap(find.byKey(const ValueKey('directory_room_7')));
      await tester.pumpAndSettle();
      final send = find.byKey(const ValueKey('request_send'));
      expect(tester.widget<FilledButton>(send).onPressed, isNull);
      await tester.tap(find.byKey(const ValueKey('request_course')));
      await tester.pumpAndSettle();
      expect(find.text('ค15102 คณิตศาสตร์ 5 (ผูกแล้ว)'), findsWidgets);
      await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
      await tester.pumpAndSettle();
      await tester.tap(send);
      await tester.pumpAndSettle();
      expect(
        find.text('มีคำขอของรายวิชานี้กับห้องนี้รออนุมัติอยู่แล้ว'),
        findsOneWidget,
      );
      expect(find.text('ขอสอนห้อง ป.6/1'), findsOneWidget, reason: 'open');
      await tester.tap(_inDialog('ยกเลิก'));
      await tester.pumpAndSettle();

      requests
        ..requestError = null
        ..requestResult = const CourseRequestResult.bound();
      await tester.tap(find.byKey(const ValueKey('directory_room_8')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('request_message')), findsNothing);
      expect(find.textContaining('ผูกกับห้องทันที'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('request_course')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
      await tester.pumpAndSettle();
      await tester.tap(_inDialog('ผูกรายวิชา'));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('request:8:4:'));
      expect(find.text('ผูกรายวิชากับห้อง ป.5/3 แล้ว'), findsOneWidget);
    });
  });

  group('ห้องประจำชั้นร่วมในแอป (DESIGN §24.13 build 2)', () {
    testWidgets('a shared room shows its homeroom teacher; the requests '
        'button carries a badge', (tester) async {
      final fake = FakeSchoolClassrooms(open: [room(), _shared]);
      await pumpScreen(
        tester,
        const Scaffold(body: ClassroomsPage()),
        overrides: _base(
          classrooms: fake,
          attention: const TeacherAttention(courseRequestsPending: 3),
        ),
        extraRoutes: [
          GoRoute(
            path: '/course-requests',
            builder: (_, _) => const Text('requests'),
          ),
        ],
      );
      expect(find.textContaining('ครูประจำชั้น ครูมาลี'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('course_requests_badge')),
          matching: find.text('3'),
        ),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('open_course_requests')));
      await tester.pumpAndSettle();
      expect(find.text('requests'), findsOneWidget);
    });

    testWidgets('the attention card leads to the requests', (tester) async {
      final opened = <AttentionTarget>[];
      await pumpScreen(
        tester,
        Scaffold(body: TeacherAttentionCard(onOpen: opened.add)),
        overrides: _base(
          attention: const TeacherAttention(courseRequestsPending: 2),
        ),
      );
      expect(find.text('คำขอผูกรายวิชารออนุมัติ 2 รายการ'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('attention_course_requests')));
      expect(opened, [AttentionTarget.courseRequests]);
    });

    testWidgets('a subject teacher reads the roster and asks for another '
        'course', (tester) async {
      _tall(tester);
      final requests = FakeCourseRequests();
      final courses = FakeCoursesRepository([
        course(id: 4),
        course(id: 5, code: 'ค15102', classrooms: const []),
      ]);
      await pumpScreen(
        tester,
        const ClassroomDetailScreen(classroomId: 7),
        overrides: [
          ..._base(
            requests: requests,
            classrooms: FakeSchoolClassrooms(open: [_shared]),
            courses: courses,
          ),
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(
            FakeGoogleRepository(),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/students/:sid/mastery',
            builder: (_, state) => Text(
              'mastery ${state.pathParameters['sid']} '
              '${state.uri.queryParameters['course']}',
            ),
          ),
        ],
      );

      expect(find.byKey(const ValueKey('subject_banner')), findsOneWidget);
      expect(find.text('ห้องของ ครูมาลี (ครูประจำชั้น)'), findsOneWidget);
      expect(find.byType(FloatingActionButton), findsNothing);
      expect(find.byTooltip('แก้ไข'), findsNothing);
      expect(find.byKey(const ValueKey('classroom_menu')), findsNothing);
      for (final label in ['พิมพ์บัตร QR', 'วิเคราะห์รายคน']) {
        expect(find.text(label), findsNothing, reason: label);
      }
      // Since build 4 a subject teacher links their own course (§24.24).
      expect(
        find.byKey(const ValueKey('classroom_google_section')),
        findsOneWidget,
      );
      expect(find.text('สร้างการบ้าน'), findsOneWidget);
      expect(find.byKey(const ValueKey('classroom_add_course')), findsNothing);
      expect(find.byKey(const ValueKey('classroom_course_4')), findsOneWidget);

      // Read-only roster: the menu only opens the student's skills.
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('roster_student_502')),
          matching: find.byTooltip('ตัวเลือก'),
        ),
      );
      await tester.pumpAndSettle();
      for (final item in [
        'วิเคราะห์รายคน (AI)',
        'แก้ชื่อ เลขประจำตัว และเลขที่',
        'รีเซ็ต PIN',
        'รวมบัญชีนักเรียน',
        'เอาออกจากห้อง',
      ]) {
        expect(find.text(item), findsNothing, reason: item);
      }
      await tester.tap(find.text('ทักษะและจุดอ่อน'));
      await tester.pumpAndSettle();
      expect(find.text('mastery 502 4'), findsOneWidget);
      Navigator.of(tester.element(find.text('mastery 502 4'))).pop();
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('classroom_request_course')));
      await tester.pumpAndSettle();
      expect(find.text('ขอสอนห้อง ป.5/2'), findsOneWidget);
      expect(find.textContaining('ครูประจำชั้น ครูมาลี'), findsWidgets);
      await tester.tap(find.byKey(const ValueKey('request_course')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ค15102 คณิตศาสตร์ 5').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('request_send')));
      await tester.pumpAndSettle();
      expect(requests.calls, contains('request:7:5:'));
    });

    testWidgets('the homeroom teacher sees every course with its teacher '
        'and unbinds one', (tester) async {
      _tall(tester);
      final courses = FakeCoursesRepository([course(id: 4)])
        ..otherTeachers = {
          7: [
            const ClassroomCourse(
              id: 40,
              code: 'ว15101',
              name: 'วิทยาศาสตร์ 5',
              gradeLevel: 5,
              academicYear: 2569,
              teacher: _somsak,
              isMine: false,
            ),
          ],
        };
      await pumpScreen(
        tester,
        const ClassroomDetailScreen(classroomId: 7),
        overrides: [
          ..._base(courses: courses),
          googleClassroomEnabledProvider.overrideWithValue(false),
        ],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/mastery',
            builder: (_, state) =>
                Text('heatmap ${state.uri.queryParameters['course']}'),
          ),
        ],
      );

      expect(
        find.text('สอนโดย ครูสมศักดิ์ · ดูผลได้อย่างเดียว'),
        findsOneWidget,
      );
      expect(find.text('ทักษะของห้องตาม ว15101'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('classroom_add_course')),
        findsOneWidget,
      );
      await tester.tap(
        find.byKey(const ValueKey('classroom_course_charts_40')),
      );
      await tester.pumpAndSettle();
      expect(find.text('heatmap 40'), findsOneWidget);
      Navigator.of(tester.element(find.text('heatmap 40'))).pop();
      await tester.pumpAndSettle();

      // In use: 409 course_in_use explains why.
      courses.unbindError = dioError(409, {
        'message': 'in use',
        'errors': {},
        'code': 'course_in_use',
      });
      await tester.tap(find.byKey(const ValueKey('classroom_course_menu_4')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('เลิกผูกกับห้องนี้'));
      await tester.pumpAndSettle();
      await tester.tap(_inDialog('เลิกผูก'));
      await tester.pumpAndSettle();
      expect(find.textContaining('เลิกผูกไม่ได้'), findsOneWidget);

      courses.unbindError = null;
      await tester.tap(find.byKey(const ValueKey('classroom_course_menu_40')));
      await tester.pumpAndSettle();
      expect(find.text('เปิดรายวิชา'), findsNothing, reason: 'not mine');
      await tester.tap(find.text('เลิกผูกกับห้องนี้'));
      await tester.pumpAndSettle();
      expect(find.textContaining('ครูสมศักดิ์ จะสั่งงาน'), findsOneWidget);
      await tester.tap(_inDialog('เลิกผูก'));
      await tester.pumpAndSettle();
      expect(courses.unbound, [(7, 40)]);
      expect(find.byKey(const ValueKey('classroom_course_40')), findsNothing);
    });

    testWidgets('a subject teacher\'s heatmap and student view use their '
        'course', (tester) async {
      final mastery = _Mastery();
      await pumpScreen(
        tester,
        const ClassroomMasteryScreen(classroomId: 7),
        overrides: [
          ..._base(classrooms: FakeSchoolClassrooms(open: [_shared])),
          masteryRepositoryProvider.overrideWithValue(mastery),
        ],
      );
      expect(mastery.classroomCalls, [(7, 4, null)]);
      expect(find.text('ทุกทักษะ'), findsNothing);

      await tester.pumpWidget(const SizedBox());
      await pumpScreen(
        tester,
        const StudentMasteryScreen(classroomId: 7, studentId: 502),
        overrides: [
          ..._base(classrooms: FakeSchoolClassrooms(open: [_shared])),
          masteryRepositoryProvider.overrideWithValue(mastery),
        ],
      );
      expect(mastery.studentCalls, [(502, 4)]);
      expect(find.byTooltip('วิเคราะห์รายคน (AI)'), findsNothing);
    });

    testWidgets('a subject teacher without a course of the room', (
      tester,
    ) async {
      final mastery = _Mastery();
      await pumpScreen(
        tester,
        const ClassroomMasteryScreen(classroomId: 7),
        overrides: [
          ..._base(
            classrooms: FakeSchoolClassrooms(open: [_shared]),
            courses: FakeCoursesRepository(),
          ),
          masteryRepositoryProvider.overrideWithValue(mastery),
        ],
      );
      expect(find.byKey(const ValueKey('heatmap_no_course')), findsOneWidget);
      expect(mastery.classroomCalls, isEmpty);
    });

    testWidgets('another teacher\'s work is read-only for the homeroom '
        'teacher', (tester) async {
      _tall(tester);
      const work = Assignment(
        id: 12,
        classroomId: 7,
        subjectId: 2,
        title: 'ใบงานวิทย์ 1',
        status: 'published',
        courseLabel: 'ว15101 วิทยาศาสตร์ 5',
        canManage: false,
        currentLayoutVersion: 1,
        questions: [
          Question(
            id: 1,
            position: 1,
            type: QuestionType.short,
            promptText: 'น้ำเดือดที่กี่องศา',
            maxPoints: 1,
          ),
        ],
      );
      await pumpScreen(
        tester,
        const AssignmentDetailScreen(assignmentId: 12),
        overrides: [
          ..._base(),
          assignmentsRepositoryProvider.overrideWithValue(_Assignments(work)),
          googleClassroomEnabledProvider.overrideWithValue(false),
        ],
      );
      expect(
        find.byKey(const ValueKey('assignment_read_only')),
        findsOneWidget,
      );
      expect(find.byTooltip('แก้ไข'), findsNothing);
      expect(find.byTooltip('ลบข้อ'), findsNothing);
      expect(find.byType(FloatingActionButton), findsNothing);
      expect(find.text('พิมพ์ใบงาน'), findsNothing);
      expect(find.text('จับคู่ข้อกับตัวชี้วัด'), findsNothing);
      expect(find.text('คะแนนและคำตอบ'), findsOneWidget);
      expect(find.text('วิเคราะห์ผล'), findsOneWidget);
      expect(find.text('น้ำเดือดที่กี่องศา'), findsOneWidget);
    });

    testWidgets('the course form edits homerooms only and keeps the shared '
        'room', (tester) async {
      _tall(tester);
      final existing = course(
        id: 4,
        classrooms: const [
          {'id': 7, 'name': 'ป.5/2'},
          {'id': 8, 'name': 'ม.1/1'},
        ],
      );
      final courses = FakeCoursesRepository([existing]);
      final rooms = FakeSchoolClassrooms(
        open: [
          room(),
          const Classroom(
            id: 8,
            name: 'ม.1/1',
            gradeLevel: 7,
            academicYear: 2569,
            classCode: 'M1M1M1',
            myRole: ClassroomRole.subject,
            homeroomTeacher: _malee,
          ),
          room(id: 9, name: 'ป.5/3'),
        ],
      );
      await pumpScreen(
        tester,
        CourseEditScreen(courseId: 4, initial: existing),
        overrides: [
          ..._base(courses: courses, classrooms: rooms),
          assignmentsRepositoryProvider.overrideWithValue(FakeSkills()),
          gradebookRepositoryProvider.overrideWithValue(
            FakeGradebookRepository(),
          ),
        ],
      );
      expect(find.byKey(const ValueKey('course_classroom_7')), findsOneWidget);
      expect(find.byKey(const ValueKey('course_classroom_8')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('course_classroom_9')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const ValueKey('course_save')));
      await tester.tap(find.byKey(const ValueKey('course_save')));
      await tester.pumpAndSettle();
      expect(courses.classroomSets.single.$1, 4);
      expect(courses.classroomSets.single.$2, [7, 9]);
    });
  });
}
