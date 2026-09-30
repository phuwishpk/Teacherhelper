import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  final pinResets = <int>[];
  final pendingIssued = <int>[];
  List<RosterStudent> rows = const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
  ];

  @override
  Future<List<EnrolledStudent>> issuePendingPins(int classroomId) async {
    pendingIssued.add(classroomId);
    rows = [
      for (final r in rows)
        RosterStudent(
          studentId: r.studentId,
          studentNumber: r.studentNumber,
          name: r.name,
        ),
    ];
    return const [
      EnrolledStudent(
        studentId: 4568,
        studentNumber: 13,
        name: 'ด.ช. มาใหม่',
        pin: '550011',
      ),
    ];
  }

  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'K7Q3M2',
      studentCount: 1,
    ),
  ];

  @override
  Future<List<RosterStudent>> roster(int id) async => rows;

  @override
  Future<PinReset> resetPin(int studentId) async {
    pinResets.add(studentId);
    return const PinReset('048213');
  }
}

void main() {
  testWidgets(
    'students the background sync added get their PINs from the room',
    (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final fake = _FakeClassrooms()
        ..rows = const [
          RosterStudent(
            studentId: 4567,
            studentNumber: 12,
            name: 'ด.ญ. สมหญิง',
          ),
          RosterStudent(
            studentId: 4568,
            studentNumber: 13,
            name: 'ด.ช. มาใหม่',
            pinPending: true,
          ),
        ];
      await pumpScreen(
        tester,
        const ClassroomDetailScreen(classroomId: 7),
        overrides: [
          classroomsRepositoryProvider.overrideWithValue(fake),
          coursesRepositoryProvider.overrideWithValue(FakeCoursesRepository()),
        ],
      );

      expect(find.byKey(const ValueKey('pin_pending_4568')), findsOneWidget);
      expect(find.byKey(const ValueKey('pin_pending_4567')), findsNothing);
      expect(
        find.text('นักเรียนใหม่จาก Google Classroom 1 คนยังไม่ได้รับ PIN'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('issue_pending_pins')));
      await tester.pumpAndSettle();
      expect(fake.pendingIssued, [7]);
      expect(find.text('550011'), findsOneWidget);

      // Back asks first: the PINs are shown once.
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('ออกจากหน้านี้?'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(find.text('550011'), findsOneWidget);

      await tester.tap(find.text('จด PIN แล้ว เสร็จสิ้น'));
      await tester.pumpAndSettle();
      expect(find.text('550011'), findsNothing);
      expect(find.byKey(const ValueKey('pending_pins_card')), findsNothing);
      expect(find.byKey(const ValueKey('pin_pending_4568')), findsNothing);
    },
  );

  testWidgets('reset PIN asks first, then shows the new PIN exactly once', (
    tester,
  ) async {
    // Tall enough for the courses card above the roster.
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final fake = _FakeClassrooms();
    await pumpScreen(
      tester,
      const ClassroomDetailScreen(classroomId: 7),
      overrides: [
        classroomsRepositoryProvider.overrideWithValue(fake),
        coursesRepositoryProvider.overrideWithValue(
          FakeCoursesRepository([course(id: 4)]),
        ),
      ],
    );
    expect(find.text('K7Q3M2'), findsOneWidget, reason: 'class code shown');
    expect(
      find.byKey(const ValueKey('classroom_course_4')),
      findsOneWidget,
      reason: 'the courses bound to the classroom are listed',
    );
    expect(find.text('ด.ญ. สมหญิง'), findsOneWidget);

    await tester.tap(find.byTooltip('ตัวเลือก'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('รีเซ็ต PIN'));
    await tester.pumpAndSettle();

    // Confirmation dialog first; cancelling calls nothing.
    expect(find.text('รีเซ็ต PIN ของ ด.ญ. สมหญิง?'), findsOneWidget);
    await tester.tap(find.text('ยกเลิก'));
    await tester.pumpAndSettle();
    expect(fake.pinResets, isEmpty);

    await tester.tap(find.byTooltip('ตัวเลือก'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('รีเซ็ต PIN'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'รีเซ็ต PIN'));
    await tester.pumpAndSettle();

    expect(fake.pinResets, [4567]);
    expect(find.text('048213'), findsOneWidget);
    expect(find.textContaining('ระบบจะไม่แสดง PIN นี้อีก'), findsOneWidget);

    await tester.tap(find.text('จดแล้ว'));
    await tester.pumpAndSettle();
    expect(find.text('048213'), findsNothing, reason: 'shown once only');
  });
}
