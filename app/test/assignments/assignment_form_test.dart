import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_form_screen.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

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
    required int subjectId,
    required String title,
    Strictness strictness = Strictness.normal,
    DateTime? dueAt,
    AssignmentMode mode = AssignmentMode.worksheet,
    bool acceptLate = true,
    bool scoreOnly = false,
  }) async {
    created.add({
      'classroom_id': classroomId,
      'subject_id': subjectId,
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
      subjectId: subjectId,
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
  }) async {
    updates.add({
      'title': title,
      'mode': mode?.apiValue,
      'accept_late': acceptLate,
      'score_only': scoreOnly,
    });
    return _draft;
  }

  static const _draft = Assignment(
    id: 56,
    classroomId: 7,
    subjectId: 1,
    title: 'ทบทวน',
  );
}

void main() {
  testWidgets('creates an assignment and opens its detail page', (
    tester,
  ) async {
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
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
    await tester.tap(
      find.widgetWithText(DropdownButtonFormField<int>, 'ห้องเรียน'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ป.5/2 (ป.5)').last);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(DropdownButtonFormField<int>, 'วิชา'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('คณิตศาสตร์').last);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('เข้มงวด'));
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
        'subject_id': 1,
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
    await tester.tap(find.widgetWithText(DropdownButtonFormField<int>, 'วิชา'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('คณิตศาสตร์').last);
    await tester.pumpAndSettle();
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
      'subject_id': 1,
      'title': 'เรียงความ',
      'strictness': 'normal',
      'due_at': null,
      'mode': 'freeform',
      'accept_late': false,
      'score_only': true,
    });
    expect(find.text('detail 55'), findsOneWidget);
  });

  testWidgets('edit sends only the changed mode and toggles', (tester) async {
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
      ],
    );
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

  testWidgets('requires a title and a subject', (tester) async {
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(initialClassroomId: 7),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(assignments),
      ],
    );
    await tester.ensureVisible(
      find.widgetWithText(FilledButton, 'สร้างการบ้าน'),
    );
    await tester.tap(find.widgetWithText(FilledButton, 'สร้างการบ้าน'));
    await tester.pump();
    expect(find.text('กรอกชื่อการบ้าน'), findsOneWidget);
    expect(find.text('เลือกวิชา'), findsOneWidget);
    expect(assignments.created, isEmpty);
  });
}
