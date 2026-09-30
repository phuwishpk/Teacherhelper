import 'package:dio/dio.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_page.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/google_classroom/classroom_import_screen.dart';
import 'package:eduvision/features/google_classroom/course_picker_screen.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'google_fakes.dart';

/// Importing a classroom from Google Classroom and syncing its roster
/// (DESIGN §19.2, §19.11) against fake repositories.

const _link = ClassroomGoogleLink(courseId: '6210', courseName: 'คณิต ป.5/1');

class _Classrooms extends Fake implements ClassroomsRepository {
  _Classrooms({this.link, List<RosterStudent> rows = const []})
    : rows = List.of(rows);

  final ClassroomGoogleLink? link;
  List<RosterStudent> rows;

  @override
  Future<List<Classroom>> list() async => [
    Classroom(
      id: 7,
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
      googleLink: link,
    ),
  ];

  @override
  Future<List<RosterStudent>> roster(int id) async => rows;
}

ClassroomImportPreview _preview({
  int? guess = 5,
  List<ImportPreviewStudent>? students,
}) => ClassroomImportPreview(
  courseId: '6210',
  name: 'คณิต',
  section: 'ป.5/1',
  suggestedName: 'คณิต ป.5/1',
  gradeLevelGuess: guess,
  academicYear: 2569,
  students:
      students ??
      const [
        ImportPreviewStudent(
          googleUserId: 'g1',
          name: 'ด.ช. กร ดี',
          proposedNumber: 1,
        ),
        ImportPreviewStudent(
          googleUserId: 'g2',
          name: 'ด.ญ. ขวัญ ใจ',
          email: 'kwan@school.ac.th',
          proposedNumber: 2,
        ),
        ImportPreviewStudent(
          googleUserId: 'g3',
          name: 'Test Account',
          proposedNumber: 3,
        ),
      ],
);

DioException _apiError(int status, String code, String message) => DioException(
  requestOptions: RequestOptions(path: '/x'),
  response: Response(
    requestOptions: RequestOptions(path: '/x'),
    statusCode: status,
    data: {'message': message, 'errors': <String, Object>{}, 'code': code},
  ),
);

void _tallView(WidgetTester tester) {
  tester.view.physicalSize = const Size(1000, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Finder _numberField(String googleUserId) =>
    find.byKey(ValueKey('import_number_$googleUserId'));

String _numberOf(WidgetTester tester, String googleUserId) =>
    tester.widget<TextField>(_numberField(googleUserId)).controller!.text;

bool _createEnabled(WidgetTester tester) => tester
    .widget<ButtonStyleButton>(find.byKey(const ValueKey('import_create')))
    .enabled;

Future<void> _pumpImport(
  WidgetTester tester,
  FakeGoogleRepository google,
) async {
  _tallView(tester);
  await pumpScreen(
    tester,
    const ClassroomImportScreen(courseId: '6210'),
    overrides: [
      googleClassroomEnabledProvider.overrideWithValue(true),
      googleClassroomRepositoryProvider.overrideWithValue(google),
      classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
    ],
  );
}

void main() {
  group('classrooms tab button', () {
    Future<void> pumpFab(WidgetTester tester, {required bool enabled}) async {
      final router = GoRouter(
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => const Scaffold(
              body: SizedBox.expand(),
              floatingActionButton: ClassroomsFab(),
            ),
          ),
          GoRoute(
            path: '/classrooms/import-google',
            builder: (_, _) => const Text('import-picker'),
          ),
        ],
      );
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            googleClassroomEnabledProvider.overrideWithValue(enabled),
          ],
          child: MaterialApp.router(routerConfig: router),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('import sits next to create when Google is set up', (
      tester,
    ) async {
      await pumpFab(tester, enabled: true);
      expect(find.text('สร้างห้องเรียน'), findsOneWidget);
      await tester.tap(find.text('นำเข้าจาก Google Classroom'));
      await tester.pumpAndSettle();
      expect(find.text('import-picker'), findsOneWidget);
    });

    testWidgets('only create without Google Classroom', (tester) async {
      await pumpFab(tester, enabled: false);
      expect(find.text('สร้างห้องเรียน'), findsOneWidget);
      expect(find.text('นำเข้าจาก Google Classroom'), findsNothing);
    });
  });

  group('course picker', () {
    const courses = [
      GoogleCourse(
        courseId: '6210',
        name: 'คณิต ป.5/1',
        linkedClassroom: LinkedClassroom(id: 7, name: 'ป.5/1'),
      ),
      GoogleCourse(
        courseId: '6211',
        name: 'คณิต ป.5/2',
        linkedClassroom: LinkedClassroom(
          id: null,
          name: LinkedClassroom.otherTeacher,
        ),
      ),
      GoogleCourse(courseId: '6212', name: 'คณิต ป.5/3', section: 'เทอม 1'),
    ];

    bool tileEnabled(WidgetTester tester, String courseId) => tester
        .widget<ListTile>(find.byKey(ValueKey('google_course_$courseId')))
        .enabled;

    testWidgets('import: linked courses are faded; a pick opens the preview '
        'and then the new room', (tester) async {
      final google = FakeGoogleRepository(courseRows: courses);
      await pumpScreen(
        tester,
        const GoogleCoursePickerScreen.forImport(),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(google),
          classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        ],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/import-google/:courseId',
            builder: (context, state) => Scaffold(
              body: TextButton(
                onPressed: () => context.pop(70),
                child: Text('preview-${state.pathParameters['courseId']}'),
              ),
            ),
          ),
          GoRoute(
            path: '/classrooms/:id',
            builder: (_, state) =>
                Text('classroom-${state.pathParameters['id']}'),
          ),
        ],
      );
      expect(find.text('นำเข้าจาก Google Classroom'), findsOneWidget);
      expect(find.text('ผูกกับ ป.5/1 แล้ว'), findsOneWidget);
      expect(find.text('ผูกกับ ห้องเรียนของครูท่านอื่น แล้ว'), findsOneWidget);
      expect(tileEnabled(tester, '6210'), isFalse);
      expect(tileEnabled(tester, '6211'), isFalse);
      expect(tileEnabled(tester, '6212'), isTrue);

      await tester.tap(find.text('คณิต ป.5/1'));
      await tester.pumpAndSettle();
      expect(find.text('preview-6210'), findsNothing, reason: 'linked');

      await tester.tap(find.text('คณิต ป.5/3'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('preview-6212'));
      await tester.pumpAndSettle();
      expect(find.text('classroom-70'), findsOneWidget);
      expect(google.linked, isEmpty, reason: 'import does not link by hand');
    });

    testWidgets('link: a course linked elsewhere cannot be picked', (
      tester,
    ) async {
      final google = FakeGoogleRepository(courseRows: courses);
      await pumpScreen(
        tester,
        const GoogleCoursePickerScreen(classroomId: 8),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(google),
          classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        ],
      );
      await tester.tap(find.text('คณิต ป.5/2'));
      await tester.pumpAndSettle();
      expect(google.linked, isEmpty);
    });
  });

  group('import preview', () {
    testWidgets('renumber, remove, then create shows the PINs once', (
      tester,
    ) async {
      final google = FakeGoogleRepository()..previews['6210'] = _preview();
      await _pumpImport(tester, google);

      expect(google.previewCalls, ['6210']);
      expect(find.text('คณิต · ป.5/1'), findsOneWidget);
      expect(find.widgetWithText(TextFormField, 'คณิต ป.5/1'), findsOneWidget);
      expect(find.text('ป.5'), findsOneWidget, reason: 'grade guessed');
      expect(find.widgetWithText(TextFormField, '2569'), findsOneWidget);
      expect(find.text('นักเรียน 3 คน'), findsOneWidget);
      expect(find.text('kwan@school.ac.th'), findsOneWidget);

      // Take the test account out: it moves to the removed list.
      await tester.tap(find.byKey(const ValueKey('import_remove_g3')));
      await tester.pumpAndSettle();
      expect(find.text('นักเรียน 2 คน'), findsOneWidget);
      expect(find.text('เอาออกแล้ว 1 บัญชี'), findsOneWidget);
      expect(_numberField('g3'), findsNothing);

      // A duplicate number blocks "สร้างห้อง".
      await tester.enterText(_numberField('g2'), '1');
      await tester.pumpAndSettle();
      expect(find.text('เลขที่ 1 ซ้ำกับคนอื่น'), findsNWidgets(2));
      expect(_createEnabled(tester), isFalse);

      // So does an empty one.
      await tester.enterText(_numberField('g2'), '');
      await tester.pumpAndSettle();
      expect(find.text('เลขที่ต้องเป็น 1–255'), findsOneWidget);
      expect(_createEnabled(tester), isFalse);

      // "เรียงเลขที่ใหม่" keeps the order and closes gaps.
      await tester.enterText(_numberField('g2'), '9');
      await tester.enterText(_numberField('g1'), '4');
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('import_renumber')));
      await tester.pumpAndSettle();
      expect(_numberOf(tester, 'g1'), '1');
      expect(_numberOf(tester, 'g2'), '2');
      expect(_createEnabled(tester), isTrue);

      await tester.enterText(
        find.byKey(const ValueKey('import_name')),
        'ป.5/1',
      );
      await tester.tap(find.byKey(const ValueKey('import_create')));
      await tester.pumpAndSettle();

      final request = google.imports.single;
      expect(request.courseId, '6210');
      expect(request.name, 'ป.5/1');
      expect(request.gradeLevel, 5);
      expect(request.academicYear, 2569);
      expect(request.numbers, {'g1': 1, 'g2': 2});
      expect(request.removed, ['g3']);
      expect(request.toJson()['students'], [
        {'google_user_id': 'g1', 'student_number': 1},
        {'google_user_id': 'g2', 'student_number': 2},
      ]);

      // One-time PINs; leaving asks first.
      expect(find.text('สร้างห้อง ป.5/1 แล้ว'), findsOneWidget);
      expect(find.text('123400'), findsOneWidget);
      expect(find.text('123401'), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('ออกจากหน้านี้?'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(find.text('123400'), findsOneWidget);

      await tester.tap(find.text('จด PIN แล้ว เสร็จสิ้น'));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('a removed account can be brought back', (tester) async {
      final google = FakeGoogleRepository()..previews['6210'] = _preview();
      await _pumpImport(tester, google);

      await tester.tap(find.byKey(const ValueKey('import_remove_g1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('import_restore_g1')));
      await tester.pumpAndSettle();
      expect(find.text('นักเรียน 3 คน'), findsOneWidget);
      expect(find.text('เอาออกแล้ว 1 บัญชี'), findsNothing);
      expect(_numberOf(tester, 'g1'), '1');
    });

    testWidgets('no grade guess: the teacher must pick one; an empty course '
        'creates the room straight away', (tester) async {
      final google = FakeGoogleRepository()
        ..previews['6210'] = _preview(guess: null, students: const []);
      await _pumpImport(tester, google);

      expect(
        find.text('เดาระดับชั้นจากชื่อคอร์สไม่ได้ กรุณาเลือก'),
        findsOneWidget,
      );
      expect(find.textContaining('ไม่มีนักเรียนที่จะเพิ่ม'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('import_create')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกระดับชั้น'), findsWidgets);
      expect(google.imports, isEmpty);

      await tester.tap(find.byKey(const ValueKey('import_grade')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ป.3').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('import_create')));
      await tester.pumpAndSettle();

      expect(google.imports.single.gradeLevel, 3);
      expect(google.imports.single.numbers, isEmpty);
      expect(find.text('stub-home'), findsOneWidget, reason: 'no PINs to show');
      expect(find.text('สร้างห้อง คณิต ป.5/1 แล้ว'), findsOneWidget);
    });

    testWidgets('409 course_already_linked on create is explained', (
      tester,
    ) async {
      final google = FakeGoogleRepository()..previews['6210'] = _preview();
      await _pumpImport(tester, google);
      google.error = _apiError(409, 'course_already_linked', 'คอร์สนี้ผูกแล้ว');
      await tester.tap(find.byKey(const ValueKey('import_create')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ผูกกับห้องเรียนในแอปแล้ว'), findsOneWidget);
      expect(find.text('นักเรียน 3 คน'), findsOneWidget, reason: 'form kept');
    });

    testWidgets('a course linked meanwhile cannot be previewed', (
      tester,
    ) async {
      final google = FakeGoogleRepository()
        ..error = _apiError(409, 'course_already_linked', 'คอร์สนี้ผูกแล้ว');
      await _pumpImport(tester, google);
      expect(find.textContaining('ผูกกับห้องเรียนในแอปแล้ว'), findsOneWidget);
      expect(find.text('ลองใหม่'), findsNothing);
    });
  });

  group('roster sync on the classroom', () {
    const before = [
      RosterStudent(studentId: 12, studentNumber: 1, name: 'ด.ช. ย้าย ไป'),
    ];
    final after = [
      RosterStudent(
        studentId: 12,
        studentNumber: 1,
        name: 'ด.ช. ย้าย ไป',
        leftCourseAt: DateTime.utc(2026, 9, 29),
      ),
      const RosterStudent(
        studentId: 88,
        studentNumber: 2,
        name: 'ด.ญ. ใหม่ มาก',
      ),
    ];

    Future<(FakeGoogleRepository, _Classrooms)> pumpDetail(
      WidgetTester tester, {
      RosterSyncResult result = const RosterSyncResult(),
    }) async {
      _tallView(tester);
      final google = FakeGoogleRepository()..syncResult = result;
      final classrooms = _Classrooms(link: _link, rows: before);
      await pumpScreen(
        tester,
        const ClassroomDetailScreen(classroomId: 7),
        overrides: [
          googleClassroomEnabledProvider.overrideWithValue(true),
          googleClassroomRepositoryProvider.overrideWithValue(google),
          classroomsRepositoryProvider.overrideWithValue(classrooms),
          coursesRepositoryProvider.overrideWithValue(FakeCoursesRepository()),
        ],
      );
      return (google, classrooms);
    }

    testWidgets('new students come with their PINs; leavers get a label', (
      tester,
    ) async {
      final (google, classrooms) = await pumpDetail(
        tester,
        result: const RosterSyncResult(
          added: [
            EnrolledStudent(
              studentId: 88,
              studentNumber: 2,
              name: 'ด.ญ. ใหม่ มาก',
              pin: '771100',
            ),
          ],
          left: [
            RosterStudent(
              studentId: 12,
              studentNumber: 1,
              name: 'ด.ช. ย้าย ไป',
            ),
          ],
        ),
      );
      expect(find.text('ไม่อยู่ใน Classroom แล้ว'), findsNothing);

      classrooms.rows = after;
      await tester.tap(find.byKey(const ValueKey('google_roster_sync')));
      await tester.pumpAndSettle();
      expect(google.syncs, [7]);
      expect(find.text('เพิ่มนักเรียนใหม่ 1 คน'), findsOneWidget);
      expect(find.text('771100'), findsOneWidget);
      expect(find.text('ไม่อยู่ใน Classroom แล้ว 1 คน'), findsOneWidget);

      // PINs are shown once: tapping outside does not close the dialog.
      await tester.tapAt(const Offset(4, 4));
      await tester.pumpAndSettle();
      expect(find.text('771100'), findsOneWidget);
      // Nor does the Android back button without asking.
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('ออกจากหน้านี้?'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(find.text('771100'), findsOneWidget);
      await tester.tap(find.text('จด PIN แล้ว'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('left_course_12')), findsOneWidget);
      expect(find.text('ด.ญ. ใหม่ มาก'), findsOneWidget);
      expect(find.byKey(const ValueKey('left_course_88')), findsNothing);
    });

    testWidgets('nothing to change says so', (tester) async {
      await pumpDetail(tester);
      await tester.tap(find.byKey(const ValueKey('google_roster_sync')));
      await tester.pumpAndSettle();
      expect(find.text('รายชื่อตรงกับ Google Classroom แล้ว'), findsOneWidget);
    });

    testWidgets('rematched accounts are listed', (tester) async {
      await pumpDetail(
        tester,
        result: const RosterSyncResult(
          rematched: [
            RosterStudent(
              studentId: 12,
              studentNumber: 1,
              name: 'ด.ช. ย้าย ไป',
            ),
          ],
        ),
      );
      await tester.tap(find.byKey(const ValueKey('google_roster_sync')));
      await tester.pumpAndSettle();
      expect(find.text('จับคู่บัญชี Google แล้ว 1 คน'), findsOneWidget);
      await tester.tap(find.text('ปิด'));
      await tester.pumpAndSettle();
      expect(find.text('ซิงก์รายชื่อแล้ว'), findsNothing);
    });

    testWidgets('an error is explained', (tester) async {
      final (google, _) = await pumpDetail(tester);
      google.error = _apiError(422, 'classroom_not_linked', 'x');
      await tester.tap(find.byKey(const ValueKey('google_roster_sync')));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('ยังไม่ได้ผูกกับ Google Classroom'),
        findsOneWidget,
      );
    });
  });
}
