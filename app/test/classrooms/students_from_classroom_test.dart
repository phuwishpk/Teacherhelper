import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classroom_form_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/students_from_classroom_screen.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/google_classroom/classroom_google_section.dart';
import 'package:eduvision/features/google_classroom/course_picker_screen.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../google_classroom/google_fakes.dart';
import '../helpers/pump_screen.dart';
import 'classroom_fakes.dart';

/// "นำนักเรียนจากห้องเดิม" (DESIGN §24.6, §24.13 build 4) and the subject
/// teacher's own Google Classroom course (§24.24).

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

/// Target room 9 (มานี is in it already), last year's closed room 3 and an
/// open room 7.
FakeSchoolClassrooms _school() {
  final fake = FakeSchoolClassrooms(
    open: [
      room(id: 9, name: 'ป.6/1', students: 1),
      room(id: 7, name: 'ป.5/2'),
    ],
    closed: [room(id: 3, name: 'ป.5/1', closedAt: DateTime.utc(2026, 3, 31))],
  );
  fake.rosters[3] = const [
    RosterStudent(studentId: 501, studentNumber: 1, name: 'ด.ช. สมชาย ใจดี'),
    RosterStudent(studentId: 502, studentNumber: 2, name: 'ด.ญ. มานี มีนา'),
    RosterStudent(
      studentId: 504,
      studentNumber: 3,
      name: 'ด.ญ. ชูใจ ใฝ่ดี',
      studentCode: '65004',
    ),
  ];
  fake.rosters[9] = const [
    RosterStudent(studentId: 502, studentNumber: 1, name: 'ด.ญ. มานี มีนา'),
  ];
  return fake;
}

Future<void> _pumpCopy(WidgetTester tester, FakeSchoolClassrooms fake) async {
  _tall(tester);
  await pumpScreen(
    tester,
    const StudentsFromClassroomScreen(classroomId: 9),
    overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
  );
}

bool _submitEnabled(WidgetTester tester) => tester
    .widget<ButtonStyleButton>(find.byKey(const ValueKey('copy_submit')))
    .enabled;

void main() {
  group('นำนักเรียนจากห้องเดิม', () {
    testWidgets('pick last year\'s room, untick one, keep the PINs', (
      tester,
    ) async {
      final fake = _school();
      await _pumpCopy(tester, fake);

      // Every room but this one; the closed one first.
      expect(find.byKey(const ValueKey('copy_source_9')), findsNothing);
      expect(
        tester.getTopLeft(find.byKey(const ValueKey('copy_source_3'))).dy,
        lessThan(
          tester.getTopLeft(find.byKey(const ValueKey('copy_source_7'))).dy,
        ),
      );
      expect(find.text('ปี 2569 · 2 คน · ห้องเก่า'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('copy_source_3')));
      await tester.pumpAndSettle();
      expect(find.text('จาก ป.5/1'), findsOneWidget);
      expect(find.text('เลือกแล้ว 2 จาก 3 คน'), findsOneWidget);
      expect(find.text('อยู่ในห้องนี้แล้ว'), findsOneWidget);
      expect(find.text('เลขประจำตัว 65004'), findsOneWidget);
      final manee = tester.widget<CheckboxListTile>(
        find.byKey(const ValueKey('copy_student_502')),
      );
      expect(manee.onChanged, isNull);

      await tester.tap(find.byKey(const ValueKey('copy_student_504')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 1 จาก 3 คน'), findsOneWidget);
      expect(find.text('นำนักเรียน 1 คนเข้าห้อง'), findsOneWidget);

      await tester.tap(find.text(CopyNumbering.sorted.label));
      await tester.pumpAndSettle();
      expect(find.textContaining('ไม่นับคำนำหน้า'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('copy_submit')));
      await tester.pumpAndSettle();
      expect(fake.copies.single.$1, 9);
      expect(fake.copies.single.$2, 3);
      expect(fake.copies.single.$3, [501]);
      expect(fake.copies.single.$4, CopyNumbering.sorted);
      expect(fake.copies.single.$5, isFalse);
      expect(find.text('stub-home'), findsOneWidget);
      expect(
        find.text('นำนักเรียน 1 คนเข้าห้องแล้ว ใช้รหัสผ่านและบัตร QR เดิมได้'),
        findsOneWidget,
      );
    });

    testWidgets('new PINs are shown once; skipped students are counted', (
      tester,
    ) async {
      final fake = _school()
        ..copySkipped = const [
          SkippedStudent(studentId: 504, name: 'ชูใจ', reason: 'not_active'),
        ];
      await _pumpCopy(tester, fake);
      await tester.tap(find.byKey(const ValueKey('copy_source_3')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('copy_pin_new')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('copy_submit')));
      await tester.pumpAndSettle();

      expect(fake.copies.single.$3, [501, 504]);
      expect(fake.copies.single.$4, CopyNumbering.keep);
      expect(fake.copies.single.$5, isTrue);
      expect(
        find.text('นำนักเรียน 2 คนเข้าห้องแล้ว (ข้าม 1 คน)'),
        findsOneWidget,
      );
      expect(find.text('300001'), findsOneWidget);
      expect(find.text('300003'), findsOneWidget);
      await tester.tap(find.text('จดรหัสผ่านแล้ว เสร็จสิ้น'));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('untick all disables the button; another room goes back', (
      tester,
    ) async {
      await _pumpCopy(tester, _school());
      await tester.tap(find.byKey(const ValueKey('copy_source_3')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('copy_toggle_all')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 0 จาก 3 คน'), findsOneWidget);
      expect(_submitEnabled(tester), isFalse);
      await tester.tap(find.text('เลือกทั้งหมด'));
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 2 จาก 3 คน'), findsOneWidget);
      expect(_submitEnabled(tester), isTrue);

      await tester.tap(find.text('เลือกห้องอื่น'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('copy_source_7')), findsOneWidget);

      // The system back button also returns to the rooms first.
      await tester.tap(find.byKey(const ValueKey('copy_source_7')));
      await tester.pumpAndSettle();
      expect(find.text('จาก ป.5/2'), findsOneWidget);
      final navigator = tester.state<NavigatorState>(
        find.byType(Navigator).last,
      );
      await navigator.maybePop();
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('copy_source_3')), findsOneWidget);
    });

    testWidgets('a refused copy shows the server message', (tester) async {
      final fake = _school()
        ..copyError = dioError(422, {
          'message': 'ข้อมูลไม่ถูกต้อง',
          'errors': {
            'student_ids.0': ['นักเรียนคนนี้ไม่อยู่ในห้องต้นทาง'],
          },
          'code': 'validation_failed',
        });
      await _pumpCopy(tester, fake);
      await tester.tap(find.byKey(const ValueKey('copy_source_3')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('copy_submit')));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsNothing);
      expect(find.textContaining('ไม่อยู่ในห้องต้นทาง'), findsWidgets);
    });

    testWidgets('no other room to copy from', (tester) async {
      await _pumpCopy(tester, FakeSchoolClassrooms(open: [room(id: 9)]));
      expect(find.text('ยังไม่มีห้องอื่นให้นำรายชื่อมา'), findsOneWidget);
    });
  });

  group('entry points', () {
    testWidgets('"สร้างแล้วนำนักเรียนจากห้องเดิม" opens the room, then the '
        'copy screen', (tester) async {
      final fake = _CreatingClassrooms();
      _tall(tester);
      await pumpScreen(
        tester,
        const ClassroomFormScreen(),
        overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id',
            builder: (_, state) => Text('room ${state.pathParameters['id']}'),
            routes: [
              GoRoute(
                path: 'students/from-classroom',
                builder: (_, state) =>
                    Text('copy into ${state.pathParameters['id']}'),
              ),
            ],
          ),
        ],
      );
      await tester.enterText(find.byType(TextFormField).first, 'ป.6/1');
      await tester.tap(find.byKey(const ValueKey('classroom_create_copy')));
      await tester.pumpAndSettle();
      expect(fake.createdNames, ['ป.6/1']);
      expect(find.text('copy into 12'), findsOneWidget);

      Navigator.of(tester.element(find.text('copy into 12'))).pop();
      await tester.pumpAndSettle();
      expect(find.text('room 12'), findsOneWidget);
    });

    testWidgets('an empty room and the room menu offer it', (tester) async {
      final fake = FakeSchoolClassrooms(open: [room(id: 9, students: 0)]);
      _tall(tester);
      await pumpScreen(
        tester,
        const ClassroomDetailScreen(classroomId: 9),
        overrides: [
          classroomsRepositoryProvider.overrideWithValue(fake),
          coursesRepositoryProvider.overrideWithValue(FakeCoursesRepository()),
        ],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/students/from-classroom',
            builder: (_, state) =>
                Text('copy into ${state.pathParameters['id']}'),
          ),
        ],
      );
      await tester.tap(
        find.byKey(const ValueKey('roster_copy_from_classroom')),
      );
      await tester.pumpAndSettle();
      expect(find.text('copy into 9'), findsOneWidget);
      Navigator.of(tester.element(find.text('copy into 9'))).pop();
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('classroom_menu')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('นำนักเรียนจากห้องเดิม').last);
      await tester.pumpAndSettle();
      expect(find.text('copy into 9'), findsOneWidget);
    });

    testWidgets('a closed room has no copy in its menu', (tester) async {
      final fake = FakeSchoolClassrooms(
        open: [],
        closed: [room(id: 9, closedAt: DateTime.utc(2026, 3, 31))],
      );
      _tall(tester);
      await pumpScreen(
        tester,
        const ClassroomDetailScreen(classroomId: 9),
        overrides: [
          classroomsRepositoryProvider.overrideWithValue(fake),
          coursesRepositoryProvider.overrideWithValue(FakeCoursesRepository()),
        ],
      );
      await tester.tap(find.byKey(const ValueKey('classroom_menu')));
      await tester.pumpAndSettle();
      expect(find.text('นำนักเรียนจากห้องเดิม'), findsNothing);
    });
  });

  group('subject teacher and Google Classroom (§24.24)', () {
    testWidgets('own course: no matching by hand; sync lists accounts that '
        'are not in the room', (tester) async {
      _tall(tester);
      final subjectRoom = Classroom(
        id: 7,
        name: 'ป.5/2',
        gradeLevel: 5,
        academicYear: 2569,
        classCode: 'K7Q3M2',
        myRole: ClassroomRole.subject,
        googleLink: const ClassroomGoogleLink(
          courseId: '6300',
          courseName: 'วิทย์ ป.5/2',
        ),
      );
      final google = FakeGoogleRepository()
        ..syncResult = const RosterSyncResult(
          rematched: [
            RosterStudent(
              studentId: 502,
              studentNumber: 1,
              name: 'ด.ญ. มานี มีนา',
            ),
          ],
          notInClassroom: [
            NotInClassroomAccount(name: 'ด.ช. นอก ห้อง', email: 'nok@s.ac.th'),
          ],
        );
      await pumpScreen(
        tester,
        Scaffold(body: ClassroomGoogleSection(classroom: subjectRoom)),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(google),
          classroomsRepositoryProvider.overrideWithValue(
            FakeSchoolClassrooms(),
          ),
        ],
      );
      expect(find.text('ผูกกับคอร์ส: วิทย์ ป.5/2'), findsOneWidget);
      expect(find.byKey(const ValueKey('google_roster_match')), findsNothing);
      expect(find.textContaining('ไม่เพิ่มหรือเอานักเรียนออก'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('google_roster_sync')));
      await tester.pumpAndSettle();
      expect(google.syncs, [7]);
      expect(find.text('ไม่อยู่ในรายชื่อห้องนี้ 1 บัญชี'), findsOneWidget);
      expect(find.text('ด.ช. นอก ห้อง (nok@s.ac.th)'), findsOneWidget);
      expect(find.text('จับคู่บัญชี Google แล้ว 1 คน'), findsOneWidget);
      await tester.tap(find.text('ปิด'));
      await tester.pumpAndSettle();
    });

    testWidgets('the homeroom teacher still matches by hand', (tester) async {
      _tall(tester);
      final homeroom = room(id: 7).withGoogleLink(
        const ClassroomGoogleLink(courseId: '6210', courseName: 'คณิต'),
      );
      await pumpScreen(
        tester,
        Scaffold(body: ClassroomGoogleSection(classroom: homeroom)),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(
            FakeGoogleRepository(),
          ),
          classroomsRepositoryProvider.overrideWithValue(
            FakeSchoolClassrooms(),
          ),
        ],
      );
      expect(find.byKey(const ValueKey('google_roster_match')), findsOneWidget);
    });

    testWidgets('linking a course as a subject teacher returns to the room', (
      tester,
    ) async {
      _tall(tester);
      final google = FakeGoogleRepository(
        courseRows: const [GoogleCourse(courseId: '6300', name: 'วิทย์')],
      );
      await pumpScreen(
        tester,
        const GoogleCoursePickerScreen(classroomId: 7),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(google),
          classroomsRepositoryProvider.overrideWithValue(
            FakeSchoolClassrooms(
              open: [room(id: 7, role: ClassroomRole.subject)],
            ),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/google-roster',
            builder: (_, _) => const Text('matching'),
          ),
        ],
      );
      await tester.tap(find.byKey(const ValueKey('google_course_6300')));
      await tester.pumpAndSettle();
      expect(google.linked, [(7, '6300')]);
      expect(find.text('matching'), findsNothing);
      expect(find.text('stub-home'), findsOneWidget);
      expect(find.textContaining('กด "ซิงก์รายชื่อ"'), findsOneWidget);
    });
  });
}

/// Creates room 12 for the form.
class _CreatingClassrooms extends FakeSchoolClassrooms {
  _CreatingClassrooms() : super(open: []);

  final createdNames = <String>[];

  @override
  Future<Classroom> create({
    required String name,
    required int gradeLevel,
    required int academicYear,
  }) async {
    createdNames.add(name);
    final made = room(id: 12, name: name, students: 0);
    open = [...open, made];
    return made;
  }
}
