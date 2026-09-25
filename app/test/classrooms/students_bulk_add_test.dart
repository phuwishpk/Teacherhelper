import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/students_bulk_add_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  final added = <(int, List<NewStudent>)>[];
  var rosterRows = const <RosterStudent>[];

  @override
  Future<List<Classroom>> list() async => const [];

  @override
  Future<List<RosterStudent>> roster(int id) async => rosterRows;

  @override
  Future<List<EnrolledStudent>> addStudents(
    int id,
    List<NewStudent> students,
  ) async {
    added.add((id, students));
    rosterRows = [
      for (final s in students)
        RosterStudent(
          studentId: 100 + s.studentNumber,
          studentNumber: s.studentNumber,
          name: s.name,
        ),
    ];
    return [
      for (final s in students)
        EnrolledStudent(
          studentId: 100 + s.studentNumber,
          studentNumber: s.studentNumber,
          name: s.name,
          pin: '00000${s.studentNumber}',
        ),
    ];
  }
}

Future<void> _addTwo(WidgetTester tester, _FakeClassrooms fake) async {
  await pumpScreen(
    tester,
    const StudentsBulkAddScreen(classroomId: 7),
    overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
  );
  await tester.enterText(
    find.byType(TextField),
    '1 ด.ช. สมชาย ใจดี\n2 ด.ญ. สมหญิง รักเรียน\n',
  );
  await tester.pump();
  await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('parses pasted lines, previews them and posts the roster', (
    tester,
  ) async {
    final fake = _FakeClassrooms();
    await pumpScreen(
      tester,
      const StudentsBulkAddScreen(classroomId: 7),
      overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
    );

    final button = find.widgetWithText(FilledButton, 'เพิ่มนักเรียน');
    expect(
      tester.widget<FilledButton>(button).onPressed,
      isNull,
      reason: 'nothing to add yet',
    );

    await tester.enterText(
      find.byType(TextField),
      '1 ด.ช. สมชาย ใจดี\n2 ด.ญ. สมหญิง รักเรียน\n',
    );
    await tester.pump();
    expect(find.text('จะเพิ่ม 2 คน'), findsOneWidget);
    expect(find.text('ด.ช. สมชาย ใจดี'), findsOneWidget);

    await tester.tap(button);
    await tester.pumpAndSettle();

    expect(fake.added, hasLength(1));
    expect(fake.added.single.$1, 7);
    expect(fake.added.single.$2, const [
      NewStudent(studentNumber: 1, name: 'ด.ช. สมชาย ใจดี'),
      NewStudent(studentNumber: 2, name: 'ด.ญ. สมหญิง รักเรียน'),
    ]);

    // The one-time PINs from the 201 answer are shown before leaving.
    expect(find.text('เพิ่มนักเรียน 2 คนแล้ว'), findsOneWidget);
    expect(find.text('000001'), findsOneWidget);
    expect(find.text('000002'), findsOneWidget);

    await tester.tap(find.text('จด PIN แล้ว เสร็จสิ้น'));
    await tester.pumpAndSettle();
    expect(find.text('stub-home'), findsOneWidget, reason: 'popped when done');
  });

  testWidgets(
    '"คัดลอก PIN ทั้งหมด" puts a tab-separated list on the clipboard',
    (tester) async {
      String? copied;
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (call) async {
          if (call.method == 'Clipboard.setData') {
            copied = (call.arguments as Map)['text'] as String?;
          }
          return null;
        },
      );
      addTearDown(
        () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
          SystemChannels.platform,
          null,
        ),
      );

      await _addTwo(tester, _FakeClassrooms());
      await tester.tap(find.text('คัดลอก PIN ทั้งหมด'));
      await tester.pumpAndSettle();

      expect(
        copied,
        'เลขที่\tชื่อ\tPIN\n'
        '1\tด.ช. สมชาย ใจดี\t000001\n'
        '2\tด.ญ. สมหญิง รักเรียน\t000002',
      );
      expect(find.text('คัดลอก PIN 2 คนแล้ว'), findsOneWidget);
    },
  );

  testWidgets('back from the PIN list asks first, since PINs are shown once', (
    tester,
  ) async {
    await _addTwo(tester, _FakeClassrooms());

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('ออกจากหน้านี้?'), findsOneWidget);

    await tester.tap(find.text('ยกเลิก'));
    await tester.pumpAndSettle();
    expect(find.text('000001'), findsOneWidget, reason: 'still on the list');

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    await tester.tap(find.text('ออก'));
    await tester.pumpAndSettle();
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('a bad line blocks submission and is pointed out', (
    tester,
  ) async {
    final fake = _FakeClassrooms();
    await pumpScreen(
      tester,
      const StudentsBulkAddScreen(classroomId: 7),
      overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
    );
    await tester.enterText(
      find.byType(TextField),
      '1 ด.ช. สมชาย ใจดี\nไม่มีเลขที่\n',
    );
    await tester.pump();
    expect(find.textContaining('บรรทัด 2:'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
            find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'),
          )
          .onPressed,
      isNull,
    );
    expect(fake.added, isEmpty);
  });
}
