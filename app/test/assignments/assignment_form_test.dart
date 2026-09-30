import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_form_screen.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
    ),
    Classroom(
      id: 8,
      name: 'ป.5/3',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC124',
    ),
  ];
}

class _FakeAssignments extends Fake implements AssignmentsRepository {
  final created = <Map<String, Object?>>[];

  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];

  @override
  Future<List<Subject>> subjects() async => const [
    Subject(id: 1, code: 'ค', name: 'คณิตศาสตร์'),
  ];

  @override
  Future<Assignment> create({
    required int classroomId,
    required int courseId,
    int? lessonPlanId,
    required String title,
    Strictness strictness = Strictness.normal,
    DateTime? dueAt,
    AssignmentMode mode = AssignmentMode.worksheet,
    bool acceptLate = true,
    bool scoreOnly = false,
  }) async {
    created.add({
      'classroom_id': classroomId,
      'course_id': courseId,
      'lesson_plan_id': lessonPlanId,
      'title': title,
      'strictness': strictness.apiValue,
      'due_at': dueAt,
      'mode': mode.apiValue,
      'accept_late': acceptLate,
      'score_only': scoreOnly,
    });
    return Assignment(
      id: 55,
      classroomId: classroomId,
      subjectId: 1,
      courseId: courseId,
      title: title,
      mode: mode,
    );
  }

  final updates = <Map<String, Object?>>[];

  @override
  Future<Assignment> get(int id) async => _draft;

  @override
  Future<Assignment> update(
    int id, {
    String? title,
    Strictness? strictness,
    DateTime? dueAt,
    bool clearDueAt = false,
    String? status,
    AssignmentMode? mode,
    bool? acceptLate,
    bool? scoreOnly,
    int? courseId,
    int? lessonPlanId,
    bool clearLessonPlan = false,
  }) async {
    updates.add({
      'title': title,
      'mode': mode?.apiValue,
      'accept_late': acceptLate,
      'score_only': scoreOnly,
      'course_id': courseId,
      'lesson_plan_id': lessonPlanId,
      'clear_lesson_plan': clearLessonPlan,
    });
    return _draft;
  }

  static const _draft = Assignment(
    id: 56,
    classroomId: 7,
    subjectId: 1,
    courseId: 4,
    lessonPlanId: 31,
    title: 'ทบทวน',
  );
}

FakeCoursesRepository _courses() => FakeCoursesRepository([
  course(
    id: 4,
    units: [unitJson(21, title: 'เศษส่วน')],
    plans: [
      planJson(31, unitId: 21, title: 'การบวกเศษส่วน'),
      planJson(32, position: 2, title: 'ทบทวน'),
    ],
  ),
  course(
    id: 6,
    code: 'ค15102',
    classrooms: const [
      {'id': 8, 'name': 'ป.5/3'},
    ],
  ),
]);

Future<void> _pickCourse(WidgetTester tester, String label) async {
  await tester.tap(
    find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('creates an assignment with a course and a lesson plan and '
      'opens its detail page', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final assignments = _FakeAssignments();
    final courses = _courses();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
        coursesRepositoryProvider.overrideWithValue(courses),
      ],
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id',
          builder: (_, state) => Text('detail ${state.pathParameters['id']}'),
        ),
      ],
    );

    await tester.enterText(
      find.widgetWithText(TextFormField, 'ชื่อการบ้าน'),
      'เศษส่วน ชุดที่ 3',
    );
    expect(
      find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
      findsNothing,
      reason: 'the course list follows the classroom',
    );
    await tester.tap(
      find.widgetWithText(DropdownButtonFormField<int>, 'ห้องเรียน'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ป.5/2 (ป.5)').last);
    await tester.pumpAndSettle();
    expect(courses.calls, contains('list:7'));

    await tester.tap(
      find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
    );
    await tester.pumpAndSettle();
    expect(find.text('ค15102 คณิตศาสตร์ 5'), findsNothing);
    await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
    await tester.pumpAndSettle();

    await tester.tap(
      find.widgetWithText(
        DropdownButtonFormField<int?>,
        'แผนการสอน (ไม่บังคับ)',
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('ไม่ผูกแผน'), findsWidgets);
    await tester.tap(find.text('หน่วย 1 · แผนที่ 1 การบวกเศษส่วน').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('เข้มงวด'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.widgetWithText(FilledButton, 'สร้างการบ้าน'),
    );
    await tester.tap(find.widgetWithText(FilledButton, 'สร้างการบ้าน'));
    await tester.pumpAndSettle();

    expect(assignments.created, [
      {
        'classroom_id': 7,
        'course_id': 4,
        'lesson_plan_id': 31,
        'title': 'เศษส่วน ชุดที่ 3',
        'strictness': 'strict',
        'due_at': null,
        'mode': 'worksheet',
        'accept_late': true,
        'score_only': false,
      },
    ]);
    expect(find.text('detail 55'), findsOneWidget);
  });

  testWidgets('creates a freeform, score-only assignment without late work', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(initialClassroomId: 7),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
        coursesRepositoryProvider.overrideWithValue(_courses()),
      ],
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id',
          builder: (_, state) => Text('detail ${state.pathParameters['id']}'),
        ),
      ],
    );

    await tester.enterText(
      find.widgetWithText(TextFormField, 'ชื่อการบ้าน'),
      'เรียงความ',
    );
    await _pickCourse(tester, 'ค15101 คณิตศาสตร์ 5');
    await tester.tap(find.text('ไม่ใช้ใบงานของแอป'));
    await tester.pumpAndSettle();
    expect(find.textContaining('ตรวจจากรูปทั้งหน้า'), findsOneWidget);
    await tester.tap(find.widgetWithText(SwitchListTile, 'รับงานส่งช้า'));
    await tester.tap(find.widgetWithText(SwitchListTile, 'เฉพาะคะแนน'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.widgetWithText(FilledButton, 'สร้างการบ้าน'),
    );
    await tester.tap(find.widgetWithText(FilledButton, 'สร้างการบ้าน'));
    await tester.pumpAndSettle();

    expect(assignments.created.single, {
      'classroom_id': 7,
      'course_id': 4,
      'lesson_plan_id': null,
      'title': 'เรียงความ',
      'strictness': 'normal',
      'due_at': null,
      'mode': 'freeform',
      'accept_late': false,
      'score_only': true,
    });
    expect(find.text('detail 55'), findsOneWidget);
  });

  testWidgets('a classroom without a course offers to create one and picks '
      'it', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final courses = FakeCoursesRepository();
    String? opened;
    await pumpScreen(
      tester,
      const AssignmentFormScreen(initialClassroomId: 7),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(_FakeAssignments()),
        coursesRepositoryProvider.overrideWithValue(courses),
      ],
      extraRoutes: [
        GoRoute(
          path: '/courses/new',
          builder: (context, state) {
            opened = state.uri.toString();
            return Scaffold(
              body: FilledButton(
                onPressed: () {
                  final created = course(id: 9, code: 'ค15109');
                  courses.courses.add(created);
                  context.pop<Course>(created);
                },
                child: const Text('stub-create'),
              ),
            );
          },
        ),
      ],
    );

    expect(
      find.text('ห้องนี้ยังไม่มีรายวิชา สร้างรายวิชาและผูกกับห้องนี้ก่อน'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('course_create_for_classroom')));
    await tester.pumpAndSettle();
    expect(opened, '/courses/new?classroom=7&pick=1');
    await tester.tap(find.text('stub-create'));
    await tester.pumpAndSettle();

    expect(find.text('ค15109 คณิตศาสตร์ 5'), findsOneWidget);
  });

  testWidgets('edit sends only the changed mode, toggles and lesson plan', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(existing: _FakeAssignments._draft),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
        coursesRepositoryProvider.overrideWithValue(_courses()),
      ],
    );
    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    await tester.tap(
      find.widgetWithText(
        DropdownButtonFormField<int?>,
        'แผนการสอน (ไม่บังคับ)',
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ไม่ผูกแผน').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('ไม่ใช้ใบงานของแอป'));
    await tester.tap(find.widgetWithText(SwitchListTile, 'เฉพาะคะแนน'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.widgetWithText(FilledButton, 'บันทึก'));
    await tester.tap(find.widgetWithText(FilledButton, 'บันทึก'));
    await tester.pumpAndSettle();
    expect(assignments.updates.single, {
      'title': 'ทบทวน',
      'mode': 'freeform',
      'accept_late': null,
      'score_only': true,
      'course_id': null,
      'lesson_plan_id': null,
      'clear_lesson_plan': true,
    });
  });

  testWidgets('the mode is locked once a layout exists', (tester) async {
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(
        existing: Assignment(
          id: 57,
          classroomId: 7,
          subjectId: 1,
          title: 'พิมพ์แล้ว',
          status: 'ready',
          currentLayoutVersion: 2,
        ),
      ),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
        coursesRepositoryProvider.overrideWithValue(_courses()),
      ],
    );
    expect(
      find.text('เปลี่ยนรูปแบบไม่ได้หลังสร้าง layout หรือมีงานส่งแล้ว'),
      findsOneWidget,
    );
    final button = tester.widget<SegmentedButton<AssignmentMode>>(
      find.byType(SegmentedButton<AssignmentMode>),
    );
    expect(button.onSelectionChanged, isNull);
  });

  testWidgets('requires a title and a course', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(initialClassroomId: 7),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
        coursesRepositoryProvider.overrideWithValue(_courses()),
      ],
    );
    await tester.ensureVisible(
      find.widgetWithText(FilledButton, 'สร้างการบ้าน'),
    );
    await tester.tap(find.widgetWithText(FilledButton, 'สร้างการบ้าน'));
    await tester.pump();
    expect(find.text('กรอกชื่อการบ้าน'), findsOneWidget);
    expect(find.text('เลือกรายวิชา'), findsOneWidget);
    expect(assignments.created, isEmpty);
  });
}
