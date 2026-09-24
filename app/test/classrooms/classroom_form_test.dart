import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_form_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  final created = <Map<String, Object>>[];

  @override
  Future<List<Classroom>> list() async => const [];

  @override
  Future<Classroom> create({
    required String name,
    required int gradeLevel,
    required int academicYear,
  }) async {
    created.add({
      'name': name,
      'grade_level': gradeLevel,
      'academic_year': academicYear,
    });
    return Classroom(
      id: 1,
      name: name,
      gradeLevel: gradeLevel,
      academicYear: academicYear,
      classCode: 'ABC123',
    );
  }
}

void main() {
  testWidgets('empty name is rejected before calling the API', (tester) async {
    final fake = _FakeClassrooms();
    await pumpScreen(
      tester,
      const ClassroomFormScreen(),
      overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
    );

    await tester.tap(find.widgetWithText(FilledButton, 'สร้างห้องเรียน'));
    await tester.pump();

    expect(find.text('กรอกชื่อห้อง'), findsOneWidget);
    expect(fake.created, isEmpty);
  });

  testWidgets('creates the classroom with grade and year, then pops', (
    tester,
  ) async {
    final fake = _FakeClassrooms();
    await pumpScreen(
      tester,
      const ClassroomFormScreen(),
      overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
    );

    await tester.enterText(
      find.widgetWithText(TextFormField, 'ชื่อห้อง'),
      'ป.5/2',
    );
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ป.5').last);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextFormField, 'ปีการศึกษา (พ.ศ.)'),
      '2569',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'สร้างห้องเรียน'));
    await tester.pumpAndSettle();

    expect(fake.created, [
      {'name': 'ป.5/2', 'grade_level': 5, 'academic_year': 2569},
    ]);
    expect(find.text('stub-home'), findsOneWidget, reason: 'screen popped');
  });
}
