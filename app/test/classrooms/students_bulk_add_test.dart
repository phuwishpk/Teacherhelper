import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/school_students.dart';
import 'package:eduvision/features/classrooms/students_bulk_add_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'classroom_fakes.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  final added = <(int, List<StudentEnrolment>)>[];
  var rosterRows = const <RosterStudent>[];

  @override
  Future<List<Classroom>> list() async => const [];

  @override
  Future<List<RosterStudent>> roster(int id) async => rosterRows;

  @override
  Future<List<EnrolledStudent>> addStudents(
    int id,
    List<StudentEnrolment> rows,
  ) async {
    added.add((id, rows));
    final students = rows.cast<NewStudent>();
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

/// Tall enough for the mode switch, the list and the button.
void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<void> _addTwo(WidgetTester tester, _FakeClassrooms fake) async {
  _tall(tester);
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
    _tall(tester);
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
    _tall(tester);
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

  group('existing students of the school (DESIGN §24.4)', () {
    Future<void> openExisting(WidgetTester tester, FakeSchoolClassrooms fake) =>
        pumpScreen(
          tester,
          const StudentsBulkAddScreen(
            classroomId: 7,
            initialMode: StudentsAddMode.existing,
          ),
          overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
        );

    Future<void> search(WidgetTester tester, String q) async {
      await tester.enterText(
        find.byKey(const ValueKey('school_student_query')),
        q,
      );
      await tester.tap(find.byKey(const ValueKey('school_student_search')));
      await tester.pumpAndSettle();
    }

    testWidgets('search, pick with the next free number and keep the PIN', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final fake = FakeSchoolClassrooms();
      await openExisting(tester, fake);

      expect(find.textContaining('อย่างน้อย 2 ตัวอักษร'), findsOneWidget);
      await search(tester, 'ด.');
      expect(fake.searches, ['ด.']);
      // Already in this room: listed but not pickable.
      final inRoom = find.byKey(const ValueKey('school_student_502'));
      expect(tester.widget<ListTile>(inRoom).enabled, isFalse);
      expect(
        find.descendant(of: inRoom, matching: find.text('อยู่ในห้องนี้แล้ว')),
        findsOneWidget,
      );
      expect(
        find.textContaining('ป.4/2 ปี 2568 เลขที่ 4 (ห้องเก่า)'),
        findsOneWidget,
        reason: 'the rooms the student is in, closed ones marked',
      );

      await tester.tap(find.byKey(const ValueKey('school_student_501')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('pick_501')), findsOneWidget);
      expect(
        tester
            .widget<TextField>(find.byKey(const ValueKey('pick_number_501')))
            .controller!
            .text,
        '3',
        reason: 'after the roster numbers 1 and 2',
      );
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('school_student_501')),
          matching: find.text('เลือกแล้ว'),
        ),
        findsOneWidget,
      );

      await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'));
      await tester.pumpAndSettle();
      expect(fake.added.single, const [
        ExistingStudentEnrolment(studentId: 501, studentNumber: 3),
      ]);
      expect(fake.added.single.single.toJson(), {
        'student_id': 501,
        'student_number': 3,
      });
      // No PIN was issued: nothing to show, back to the room with a note.
      expect(find.text('stub-home'), findsOneWidget);
      expect(
        find.text('เพิ่มนักเรียน 1 คนแล้ว ใช้ PIN และบัตร QR เดิมได้'),
        findsOneWidget,
      );
    });

    testWidgets('a new PIN on request is shown once, alone', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final fake = FakeSchoolClassrooms();
      await openExisting(tester, fake);
      await search(tester, '650');
      await tester.tap(find.byKey(const ValueKey('school_student_501')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('pick_number_501')),
        '12',
      );
      await tester.tap(find.byKey(const ValueKey('pick_reissue_501')));
      await tester.pump();
      await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'));
      await tester.pumpAndSettle();

      expect(fake.added.single, const [
        ExistingStudentEnrolment(
          studentId: 501,
          studentNumber: 12,
          reissuePin: true,
        ),
      ]);
      expect(fake.added.single.single.toJson()['reissue_pin'], isTrue);
      expect(find.text('เพิ่มนักเรียน 1 คนแล้ว'), findsOneWidget);
      expect(find.text('2000012'), findsOneWidget);
    });

    testWidgets('a number taken twice in the list is caught before sending', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final fake = FakeSchoolClassrooms()
        ..school = [
          somchai,
          const SchoolStudent(id: 504, name: 'ด.ช. ชูใจ ใจดี'),
        ];
      await openExisting(tester, fake);
      await search(tester, 'ใจดี');
      await tester.tap(find.byKey(const ValueKey('school_student_501')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('school_student_504')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('pick_number_504')),
        '3',
      );
      await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'));
      await tester.pumpAndSettle();
      expect(fake.added, isEmpty);
      expect(find.text('เลขที่ 3 ซ้ำกันในรายการ'), findsOneWidget);

      // Taking one out of the list fixes it.
      await tester.tap(find.byTooltip('เอาออกจากรายการ').last);
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('pick_504')), findsNothing);
    });

    testWidgets(
      'a student code another student holds offers adding that student instead',
      (tester) async {
        tester.view.physicalSize = const Size(1080, 2400);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        final fake = FakeSchoolClassrooms()
          ..addError = dioError(422, {
            'message': 'เลขประจำตัว 65001 เป็นของนักเรียนคนอื่นในโรงเรียนแล้ว',
            'code': 'student_code_taken',
            'errors': {
              'students.1.student_code': [
                'เลขประจำตัว 65001 เป็นของ ด.ช. สมชาย ใจดี',
              ],
            },
            'existing_student': {'id': 501, 'name': 'ด.ช. สมชาย ใจดี'},
          });
        await pumpScreen(
          tester,
          const StudentsBulkAddScreen(classroomId: 7),
          overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
        );
        await tester.enterText(
          find.byType(TextField),
          '3 ด.ญ. ชูใจ มีสุข\n4 ด.ช. สมชาย ใจดี 65001\n',
        );
        await tester.pump();
        expect(find.text('เลขประจำตัว 65001'), findsOneWidget);
        await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'));
        await tester.pumpAndSettle();

        expect(
          fake.added.single.last,
          const NewStudent(
            studentNumber: 4,
            name: 'ด.ช. สมชาย ใจดี',
            studentCode: '65001',
          ),
        );
        expect(
          find.text('• เลขประจำตัว 65001 เป็นของ ด.ช. สมชาย ใจดี'),
          findsOneWidget,
          reason: 'errors.students.{i} are listed',
        );
        fake.addError = null;
        await tester.tap(find.byKey(const ValueKey('use_code_holder')));
        await tester.pumpAndSettle();

        // Now on "เลือกนักเรียนที่มีอยู่" with the holder picked as number 4.
        expect(find.byKey(const ValueKey('pick_501')), findsOneWidget);
        expect(
          tester
              .widget<TextField>(find.byKey(const ValueKey('pick_number_501')))
              .controller!
              .text,
          '4',
        );
        await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มนักเรียน'));
        await tester.pumpAndSettle();
        expect(fake.added.last, const [
          ExistingStudentEnrolment(studentId: 501, studentNumber: 4),
        ]);
      },
    );
  });
}
