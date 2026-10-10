import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/school_students.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/google_classroom/classroom_import_screen.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../classrooms/classroom_fakes.dart';
import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'google_fakes.dart';

/// The import preview of build 4 (DESIGN §24.10, §24.13): accounts matched
/// to students the school has, and the suggested existing room.

const _p41 = StudentClassroom(
  id: 3,
  name: 'ป.4/1',
  academicYear: 2568,
  studentNumber: 4,
  closed: true,
);

const _students = [
  ImportPreviewStudent(
    googleUserId: 'g1',
    name: 'ด.ช. กร ดี',
    proposedNumber: 1,
    match: ImportMatch(
      studentId: 501,
      name: 'ด.ช. กร ดี',
      matchedBy: ImportMatchKind.classroomUser,
      classes: [_p41],
    ),
  ),
  ImportPreviewStudent(
    googleUserId: 'g2',
    name: 'ด.ญ. ขวัญ ใจ',
    proposedNumber: 2,
  ),
  ImportPreviewStudent(
    googleUserId: 'g3',
    name: 'ด.ช. ชาย กล้า',
    proposedNumber: 3,
    match: ImportMatch(
      studentId: 503,
      name: 'ด.ช. ชาย กล้า',
      matchedBy: ImportMatchKind.name,
    ),
  ),
];

ClassroomImportPreview _preview({SuggestedClassroom? room}) =>
    ClassroomImportPreview(
      courseId: '6210',
      name: 'คณิต',
      section: 'ป.5/1',
      suggestedName: 'คณิต ป.5/1',
      gradeLevelGuess: 5,
      academicYear: 2569,
      students: _students,
      suggestedClassroom: room,
    );

SuggestedClassroom _room({bool mine = false}) => SuggestedClassroom(
  id: 7,
  name: 'ป.5/1',
  academicYear: 2569,
  coverage: 0.9,
  matched: 27,
  ownedByMe: mine,
  homeroomTeacher: const TeacherRef(id: 2, name: 'ครูมาลี'),
);

Classroom _linkedRoom() => const Classroom(
  id: 7,
  name: 'ป.5/1',
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'ABC123',
);

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1000, 2800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<void> _pump(
  WidgetTester tester,
  FakeGoogleRepository google, {
  FakeCoursesRepository? courses,
  List<GoRoute> extraRoutes = const [],
}) async {
  _tall(tester);
  await pumpScreen(
    tester,
    const ClassroomImportScreen(courseId: '6210'),
    overrides: [
      googleClassroomEnabledProvider.overrideWithValue(true),
      googleClassroomRepositoryProvider.overrideWithValue(google),
      classroomsRepositoryProvider.overrideWithValue(
        FakeSchoolClassrooms(open: [room(id: 7, name: 'ป.5/1')]),
      ),
      coursesRepositoryProvider.overrideWithValue(
        courses ?? FakeCoursesRepository(),
      ),
    ],
    extraRoutes: extraRoutes,
  );
}

FakeGoogleRepository _google({SuggestedClassroom? room}) =>
    FakeGoogleRepository()..previews = {'6210': _preview(room: room)};

void main() {
  group('สร้างห้องใหม่ with existing accounts', () {
    testWidgets('matched accounts are ticked; unticking creates a new one; '
        'only new accounts show a PIN', (tester) async {
      final google = _google()
        ..importResult = ClassroomImportResult(
          classroom: _linkedRoom(),
          students: const [
            EnrolledStudent(
              studentId: 501,
              studentNumber: 1,
              name: 'ด.ช. กร ดี',
              pin: '',
              existing: true,
            ),
            EnrolledStudent(
              studentId: 610,
              studentNumber: 2,
              name: 'ด.ญ. ขวัญ ใจ',
              pin: '111111',
            ),
            EnrolledStudent(
              studentId: 611,
              studentNumber: 3,
              name: 'ด.ช. ชาย กล้า',
              pin: '222222',
            ),
          ],
        );
      await _pump(tester, google);

      // No suggestion: straight to the create form.
      expect(find.byKey(const ValueKey('import_choice_link')), findsNothing);
      expect(find.text('นักเรียน 3 คน · ใช้บัญชีเดิม 2 คน'), findsOneWidget);
      expect(find.text('ใช้บัญชีเดิม: ด.ช. กร ดี'), findsOneWidget);
      expect(find.text(ImportMatchKind.classroomUser.label), findsOneWidget);
      expect(find.text('ป.4/1 ปี 2568 เลขที่ 4 (ห้องเก่า)'), findsOneWidget);
      expect(find.text(ImportMatchKind.name.label), findsOneWidget);
      expect(find.text('ยังไม่อยู่ในห้องใด'), findsOneWidget);
      expect(find.text('บัญชีใหม่ (ได้รหัสผ่านใหม่)'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_existing_g3')));
      await tester.pumpAndSettle();
      expect(find.text('นักเรียน 3 คน · ใช้บัญชีเดิม 1 คน'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_create')));
      await tester.pumpAndSettle();
      final request = google.imports.single;
      expect(request.existing, {'g1': 501});
      expect(request.toJson()['students'], [
        {'google_user_id': 'g1', 'student_number': 1, 'student_id': 501},
        {'google_user_id': 'g2', 'student_number': 2},
        {'google_user_id': 'g3', 'student_number': 3},
      ]);
      // The PIN list leaves out the student who keeps their PIN.
      expect(find.text('111111'), findsOneWidget);
      expect(find.text('222222'), findsOneWidget);
      expect(find.text('ด.ช. กร ดี'), findsNothing);
    });

    testWidgets('a removed account is not sent as existing', (tester) async {
      final google = _google()
        ..importResult = ClassroomImportResult(
          classroom: _linkedRoom(),
          students: const [
            EnrolledStudent(
              studentId: 503,
              studentNumber: 3,
              name: 'ด.ช. ชาย กล้า',
              pin: '',
              existing: true,
            ),
          ],
        );
      await _pump(tester, google);
      await tester.tap(find.byKey(const ValueKey('import_remove_g1')));
      await tester.tap(find.byKey(const ValueKey('import_remove_g2')));
      await tester.pumpAndSettle();
      expect(find.text('นักเรียน 1 คน · ใช้บัญชีเดิม 1 คน'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_create')));
      await tester.pumpAndSettle();
      expect(google.imports.single.existing, {'g3': 503});
      expect(google.imports.single.removed, ['g1', 'g2']);
      // Nobody got a PIN: back with a note instead of the PIN list.
      expect(find.text('stub-home'), findsOneWidget);
      expect(
        find.text(
          'สร้างห้อง ป.5/1 แล้ว นักเรียน 1 คนใช้รหัสผ่านและบัตร QR เดิมได้',
        ),
        findsOneWidget,
      );
    });
  });

  group('ผูกกับห้องที่มีอยู่', () {
    testWidgets('own room: first choice, links at once and shows the sync', (
      tester,
    ) async {
      final google = _google(room: _room(mine: true))
        ..linkExistingResult = LinkedExisting(
          classroom: _linkedRoom(),
          roster: const RosterSyncResult(
            added: [
              EnrolledStudent(
                studentId: 612,
                studentNumber: 31,
                name: 'ด.ญ. ขวัญ ใจ',
                pin: '333333',
              ),
            ],
            enrolled: [
              EnrolledStudent(
                studentId: 503,
                studentNumber: 32,
                name: 'ด.ช. ชาย กล้า',
                pin: '',
              ),
            ],
          ),
        );
      await _pump(tester, google);

      expect(find.text('ผูกคอร์สนี้กับห้อง ป.5/1 ที่มีอยู่'), findsOneWidget);
      expect(
        find.text(
          'ปี 2569 · ห้องของคุณ · นักเรียนในคอร์ส 27 คนอยู่ในห้องนี้ (90%)',
        ),
        findsOneWidget,
      );
      expect(find.text('สร้างห้องใหม่'), findsOneWidget);
      // The create form waits behind the second choice.
      expect(find.byKey(const ValueKey('import_name')), findsNothing);
      expect(find.text('ไม่ระบุรายวิชา'), findsOneWidget);
      expect(find.text('ผูกกับห้อง ป.5/1'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(google.linkExistingCalls, [('6210', 7, null)]);
      expect(find.text('ซิงก์รายชื่อแล้ว'), findsOneWidget);
      expect(find.text('333333'), findsOneWidget);
      expect(find.text('นักเรียนเดิมของโรงเรียนเข้าห้อง 1 คน'), findsOneWidget);
      await tester.tap(find.text('จดรหัสผ่านแล้ว'));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('own room: a failed sync after linking says so', (
      tester,
    ) async {
      final google = _google(room: _room(mine: true))
        ..linkExistingResult = LinkedExisting(
          classroom: _linkedRoom(),
          rosterError: 'ต้องเชื่อมบัญชี Google ใหม่',
        );
      await _pump(tester, google);
      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsOneWidget);
      expect(
        find.textContaining(
          'ซิงก์รายชื่อไม่สำเร็จ: ต้องเชื่อมบัญชี Google ใหม่',
        ),
        findsOneWidget,
      );
    });

    testWidgets("another teacher's room needs a course and sends a request", (
      tester,
    ) async {
      final google = _google(room: _room())
        ..linkExistingResult = const LinkRequested(requestId: 41);
      final courses = FakeCoursesRepository([
        course(id: 4, classrooms: const []),
      ]);
      await _pump(tester, google, courses: courses);

      expect(
        find.text(
          'ปี 2569 · ครูประจำชั้น ครูมาลี · นักเรียนในคอร์ส 27 คนอยู่ในห้องนี้ (90%)',
        ),
        findsOneWidget,
      );
      expect(find.text('ส่งคำขอผูกกับห้อง ป.5/1'), findsOneWidget);
      expect(find.textContaining('ระบบจะส่งคำขอผูกรายวิชา'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(google.linkExistingCalls, isEmpty);
      expect(find.text('เลือกรายวิชาของคุณที่จะสอนห้อง ป.5/1'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_app_course_1')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(google.linkExistingCalls, [('6210', 7, 4)]);
      expect(find.text('stub-home'), findsOneWidget);
      expect(
        find.textContaining('ส่งคำขอผูกรายวิชากับห้อง ป.5/1 แล้ว'),
        findsOneWidget,
      );
    });

    testWidgets('a course already teaching the room links at once', (
      tester,
    ) async {
      final google = _google(room: _room())
        ..linkExistingResult = LinkedExisting(
          classroom: _linkedRoom(),
          roster: const RosterSyncResult(),
        );
      final courses = FakeCoursesRepository([course(id: 4)]);
      await _pump(tester, google, courses: courses);

      expect(find.text('ผูกกับห้อง ป.5/1'), findsOneWidget);
      expect(
        find.text('ค15101 คณิตศาสตร์ 5 (สอนห้องนี้อยู่แล้ว)'),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(google.linkExistingCalls, [('6210', 7, 4)]);
      expect(find.text('ผูกคอร์สกับห้อง ป.5/1 แล้ว'), findsOneWidget);
    });

    testWidgets('a new course made here is picked for the link', (
      tester,
    ) async {
      final google = _google(room: _room())
        ..linkExistingResult = const LinkRequested();
      final courses = FakeCoursesRepository();
      await _pump(
        tester,
        google,
        courses: courses,
        extraRoutes: [
          GoRoute(
            path: '/courses/new',
            builder: (context, state) => Scaffold(
              body: TextButton(
                onPressed: () {
                  final made = course(id: 9, code: 'ค15201', classrooms: []);
                  courses.courses.add(made);
                  context.pop<Course>(made);
                },
                child: Text('new-course ${state.uri.query}'),
              ),
            ),
          ),
        ],
      );
      await tester.tap(find.byKey(const ValueKey('import_create_course')));
      await tester.pumpAndSettle();
      // Not ticked for a room of another teacher (only homerooms can be).
      expect(find.text('new-course pick=1'), findsOneWidget);
      await tester.tap(find.text('new-course pick=1'));
      await tester.pumpAndSettle();
      expect(find.text('ค15201 คณิตศาสตร์ 5'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(google.linkExistingCalls, [('6210', 7, 9)]);
    });

    testWidgets('an error stays on the screen; "สร้างห้องใหม่" switches', (
      tester,
    ) async {
      final google = _google(room: _room(mine: true));
      await _pump(tester, google);

      google.error = dioError(409, {
        'message': 'pending',
        'errors': <String, Object>{},
        'code': 'request_pending',
      });
      await tester.tap(find.byKey(const ValueKey('import_link_existing')));
      await tester.pumpAndSettle();
      expect(find.textContaining('รออนุมัติอยู่แล้ว'), findsOneWidget);
      expect(find.text('stub-home'), findsNothing);

      await tester.tap(find.byKey(const ValueKey('import_choice_create')));
      await tester.pumpAndSettle();
      expect(find.textContaining('รออนุมัติอยู่แล้ว'), findsNothing);
      expect(find.byKey(const ValueKey('import_name')), findsOneWidget);
      expect(find.byKey(const ValueKey('import_link_existing')), findsNothing);
      expect(find.text('นักเรียน 3 คน · ใช้บัญชีเดิม 2 คน'), findsOneWidget);
    });
  });
}
