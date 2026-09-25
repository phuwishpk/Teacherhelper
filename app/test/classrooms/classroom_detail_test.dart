import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  final pinResets = <int>[];

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
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
  ];

  @override
  Future<PinReset> resetPin(int studentId) async {
    pinResets.add(studentId);
    return const PinReset('048213');
  }
}

void main() {
  testWidgets('reset PIN asks first, then shows the new PIN exactly once', (
    tester,
  ) async {
    final fake = _FakeClassrooms();
    await pumpScreen(
      tester,
      const ClassroomDetailScreen(classroomId: 7),
      overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
    );
    expect(find.text('K7Q3M2'), findsOneWidget, reason: 'class code shown');
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
