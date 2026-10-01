import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_page.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/school_students.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'classroom_fakes.dart';

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<void> _pumpDetail(
  WidgetTester tester,
  FakeSchoolClassrooms fake, {
  List<GoRoute> extraRoutes = const [],
}) async {
  _tall(tester);
  await pumpScreen(
    tester,
    const ClassroomDetailScreen(classroomId: 7),
    overrides: [
      classroomsRepositoryProvider.overrideWithValue(fake),
      coursesRepositoryProvider.overrideWithValue(
        FakeCoursesRepository([course(id: 4)]),
      ),
    ],
    extraRoutes: extraRoutes,
  );
}

Future<void> _menu(WidgetTester tester, String item) async {
  await tester.tap(find.byKey(const ValueKey('classroom_menu')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(item));
  await tester.pumpAndSettle();
}

Future<void> _studentMenu(WidgetTester tester, String name) async {
  await tester.tap(
    find.descendant(
      of: find.widgetWithText(ListTile, name),
      matching: find.byTooltip('ตัวเลือก'),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  group('ห้องเก่า (DESIGN §24.6)', () {
    testWidgets('a closed room is read-only and can be reopened', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms(
        open: [],
        closed: [room(closedAt: DateTime.utc(2026, 3, 31))],
      );
      await _pumpDetail(tester, fake);

      expect(find.byKey(const ValueKey('closed_banner')), findsOneWidget);
      expect(find.text('ห้องเก่า อ่านอย่างเดียว'), findsOneWidget);
      expect(find.byType(FloatingActionButton), findsNothing);
      expect(find.byTooltip('แก้ไข'), findsNothing);
      for (final label in [
        'พิมพ์บัตร QR',
        'เตรียมสแกนออฟไลน์',
        'สร้างการบ้าน',
      ]) {
        expect(find.text(label), findsNothing, reason: label);
      }
      expect(find.byKey(const ValueKey('classroom_add_course')), findsNothing);
      // Results stay readable.
      expect(find.text('ทักษะของห้อง'), findsOneWidget);
      expect(find.byKey(const ValueKey('classroom_course_4')), findsOneWidget);
      expect(find.text('เลขประจำตัว 65003'), findsOneWidget);

      // Students: view and merge only.
      await _studentMenu(tester, 'ด.ญ. มานี มีนา');
      expect(find.text('ทักษะและจุดอ่อน'), findsOneWidget);
      expect(find.text('รวมบัญชีนักเรียน'), findsOneWidget);
      expect(find.text('รีเซ็ต PIN'), findsNothing);
      expect(find.text('ออกบัตร QR ใหม่'), findsNothing);
      expect(find.text('เอาออกจากห้อง'), findsNothing);
      expect(find.text('แก้ชื่อ เลขประจำตัว และเลขที่'), findsNothing);
      await tester.tapAt(const Offset(5, 5));
      await tester.pumpAndSettle();

      // The menu offers it too; the banner's button is the quick way.
      await tester.tap(find.byKey(const ValueKey('classroom_menu')));
      await tester.pumpAndSettle();
      expect(find.text('ปิดห้อง (ย้ายไปห้องเก่า)'), findsNothing);
      expect(find.text('ลบห้อง'), findsOneWidget);
      await tester.tapAt(const Offset(5, 5));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('closed_banner_reopen')));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'เปิดห้อง'));
      await tester.pumpAndSettle();
      expect(fake.calls, contains('reopen 7'));
      expect(find.byKey(const ValueKey('closed_banner')), findsNothing);
      expect(find.byType(FloatingActionButton), findsOneWidget);
      expect(find.text('เปิดห้อง ป.5/2 อีกครั้งแล้ว'), findsOneWidget);
    });

    testWidgets('closing asks first, then the page turns read-only', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms();
      await _pumpDetail(tester, fake);
      expect(find.byKey(const ValueKey('closed_banner')), findsNothing);

      await _menu(tester, 'ปิดห้อง (ย้ายไปห้องเก่า)');
      expect(find.text('ปิดห้อง ป.5/2?'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(fake.calls, isNot(contains('close 7')));

      await _menu(tester, 'ปิดห้อง (ย้ายไปห้องเก่า)');
      await tester.tap(find.widgetWithText(FilledButton, 'ปิดห้อง'));
      await tester.pumpAndSettle();
      expect(fake.calls, contains('close 7'));
      expect(find.byKey(const ValueKey('closed_banner')), findsOneWidget);
      expect(find.byType(FloatingActionButton), findsNothing);
      expect(find.text('ย้าย ป.5/2 ไปห้องเก่าแล้ว'), findsOneWidget);
    });

    testWidgets(
      'a room with work is not deleted: the counts and "ปิดห้องแทน"',
      (tester) async {
        final fake = FakeSchoolClassrooms()
          ..deleteError = dioError(409, {
            'message': 'ห้องนี้มีงานหรือคะแนนแล้ว ลบไม่ได้ ใช้ "ปิดห้อง" แทน',
            'code': 'classroom_has_data',
            'errors': <String, Object?>{},
            'counts': {
              'submissions': 12,
              'gradebook_entries': 0,
              'gradebook_special_grades': 2,
              'gradebook_publications': 1,
            },
          });
        await _pumpDetail(tester, fake);

        await _menu(tester, 'ลบห้อง');
        await tester.tap(find.widgetWithText(FilledButton, 'ลบห้อง'));
        await tester.pumpAndSettle();
        expect(fake.calls, contains('delete 7'));
        expect(
          find.byKey(const ValueKey('classroom_has_data')),
          findsOneWidget,
        );
        expect(find.textContaining('งานที่ส่งแล้ว 12 ชิ้น'), findsOneWidget);
        expect(find.textContaining('ประกาศเกรดแล้ว 1 ครั้ง'), findsOneWidget);
        expect(find.textContaining('ร/มส 2 รายการ'), findsOneWidget);
        expect(find.textContaining('คะแนนในสมุดคะแนน'), findsNothing);

        await tester.tap(find.text('ปิดห้องแทน'));
        await tester.pumpAndSettle();
        await tester.tap(find.widgetWithText(FilledButton, 'ปิดห้อง'));
        await tester.pumpAndSettle();
        expect(fake.calls, contains('close 7'));
        expect(find.byKey(const ValueKey('closed_banner')), findsOneWidget);
      },
    );

    testWidgets('an empty room is deleted and the page closes', (tester) async {
      final fake = FakeSchoolClassrooms();
      await _pumpDetail(tester, fake);
      await _menu(tester, 'ลบห้อง');
      await tester.tap(find.widgetWithText(FilledButton, 'ลบห้อง'));
      await tester.pumpAndSettle();
      expect(fake.calls, contains('delete 7'));
      expect(find.text('stub-home'), findsOneWidget);
      expect(find.text('ลบห้อง ป.5/2 แล้ว'), findsOneWidget);
    });
  });

  group('students of an open room (DESIGN §24.4)', () {
    testWidgets('printing the whole room warns about shared cards first', (
      tester,
    ) async {
      await _pumpDetail(tester, FakeSchoolClassrooms());
      await tester.tap(find.text('พิมพ์บัตร QR'));
      await tester.pumpAndSettle();
      expect(find.text('พิมพ์บัตร QR ทั้งห้อง?'), findsOneWidget);
      expect(find.textContaining('นักเรียนที่อยู่หลายห้อง'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(find.text('พิมพ์บัตร QR ทั้งห้อง?'), findsNothing);
    });

    testWidgets('edit the name, the student code and the number', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms();
      await _pumpDetail(tester, fake);
      await _studentMenu(tester, 'ด.ช. ปิติ ชูใจ');
      await tester.tap(find.text('แก้ชื่อ เลขประจำตัว และเลขที่'));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const ValueKey('edit_student_code')),
        '',
      );
      await tester.enterText(
        find.byKey(const ValueKey('edit_student_number')),
        '0',
      );
      await tester.tap(find.byKey(const ValueKey('edit_student_save')));
      await tester.pumpAndSettle();
      expect(find.text('เลขที่ต้องอยู่ระหว่าง 1–255'), findsOneWidget);
      expect(fake.studentUpdates, isEmpty);

      await tester.enterText(
        find.byKey(const ValueKey('edit_student_number')),
        '9',
      );
      await tester.tap(find.byKey(const ValueKey('edit_student_save')));
      await tester.pumpAndSettle();
      expect(fake.studentUpdates, [(503, 'ด.ช. ปิติ ชูใจ', null)]);
      expect(fake.numberUpdates, [(7, 503, 9)]);
      expect(find.text('บันทึกข้อมูลนักเรียนแล้ว'), findsOneWidget);
    });

    testWidgets('a code another student holds keeps the dialog open', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms()
        ..updateError = dioError(422, {
          'message': 'เลขประจำตัวนี้เป็นของนักเรียนคนอื่นแล้ว',
          'code': 'student_code_taken',
          'errors': {
            'student_code': ['เลขประจำตัวนี้เป็นของนักเรียนคนอื่นแล้ว'],
          },
          'existing_student': {'id': 501, 'name': 'ด.ช. สมชาย ใจดี'},
        });
      await _pumpDetail(tester, fake);
      await _studentMenu(tester, 'ด.ญ. มานี มีนา');
      await tester.tap(find.text('แก้ชื่อ เลขประจำตัว และเลขที่'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('edit_student_code')),
        '65001',
      );
      await tester.tap(find.byKey(const ValueKey('edit_student_save')));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('เลขนี้เป็นของ ด.ช. สมชาย ใจดี'),
        findsOneWidget,
      );
      expect(fake.numberUpdates, isEmpty);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(find.text('แก้ข้อมูลนักเรียน'), findsNothing);
    });

    testWidgets('taking a student out asks first; work keeps them in', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms()
        ..removeError = dioError(409, {
          'message':
              'นักเรียนคนนี้มีงานหรือคะแนนในห้องนี้แล้ว เอาออกจากห้องไม่ได้',
          'code': 'student_has_data',
          'errors': <String, Object?>{},
        });
      await _pumpDetail(tester, fake);
      await _studentMenu(tester, 'ด.ญ. มานี มีนา');
      await tester.tap(find.text('เอาออกจากห้อง'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'เอาออก'));
      await tester.pumpAndSettle();
      expect(fake.calls, contains('remove 7 502'));
      expect(
        find.text(
          'นักเรียนคนนี้มีงานหรือคะแนนในห้องนี้แล้ว เอาออกจากห้องไม่ได้',
        ),
        findsOneWidget,
      );
      expect(find.text('ด.ญ. มานี มีนา'), findsOneWidget);

      fake.removeError = null;
      await _studentMenu(tester, 'ด.ญ. มานี มีนา');
      await tester.tap(find.text('เอาออกจากห้อง'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'เอาออก'));
      await tester.pumpAndSettle();
      expect(find.text('ด.ญ. มานี มีนา'), findsNothing);
      expect(find.text('เอา ด.ญ. มานี มีนา ออกจากห้องแล้ว'), findsOneWidget);
    });

    testWidgets('"บัญชีที่อาจซ้ำ" opens the merge preview, this room kept', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms()
        ..duplicates = const [
          DuplicateCandidate(
            a: SchoolStudent(id: 499, name: 'มานี มีนา'),
            b: manee,
            reasons: ['name'],
          ),
          // Neither account is in this room: not shown here.
          DuplicateCandidate(
            a: SchoolStudent(id: 1, name: 'ก'),
            b: SchoolStudent(id: 2, name: 'ก'),
            reasons: ['name'],
          ),
        ];
      await _pumpDetail(
        tester,
        fake,
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/merge',
            builder: (_, s) => Text(
              'preview-${s.pathParameters['id']}-'
              '${s.uri.queryParameters['keep']}-${s.uri.queryParameters['merge']}',
            ),
          ),
        ],
      );
      expect(
        find.byKey(const ValueKey('duplicate_candidates')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('duplicate_1_2')), findsNothing);
      expect(find.text('ชื่อเดียวกัน'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('duplicate_499_502')));
      await tester.pumpAndSettle();
      expect(find.text('preview-7-502-499'), findsOneWidget);
    });

    testWidgets('an empty room offers picking existing students', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms()..rosters[7] = const [];
      await _pumpDetail(
        tester,
        fake,
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/students/add',
            builder: (_, s) => Text(
              'add-${s.pathParameters['id']}-${s.uri.queryParameters['mode']}',
            ),
          ),
        ],
      );
      await tester.tap(find.byKey(const ValueKey('roster_add_existing')));
      await tester.pumpAndSettle();
      expect(find.text('add-7-existing'), findsOneWidget);
    });

    testWidgets('the add screen and merge open from the room', (tester) async {
      await _pumpDetail(
        tester,
        FakeSchoolClassrooms(),
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/students/:sid/merge',
            builder: (_, s) => Text(
              'merge-${s.pathParameters['id']}-${s.pathParameters['sid']}',
            ),
          ),
        ],
      );
      await _studentMenu(tester, 'ด.ญ. มานี มีนา');
      await tester.tap(find.text('รวมบัญชีนักเรียน'));
      await tester.pumpAndSettle();
      expect(find.text('merge-7-502'), findsOneWidget);
    });
  });

  group('classroom list (DESIGN §24.13)', () {
    Future<void> pumpList(WidgetTester tester, FakeSchoolClassrooms fake) =>
        pumpScreen(
          tester,
          const Scaffold(body: ClassroomsPage()),
          overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
        );

    testWidgets('roles on the cards; "ห้องเก่า" loads when opened', (
      tester,
    ) async {
      final fake = FakeSchoolClassrooms(
        open: [
          room(),
          room(id: 8, name: 'ม.1/1', role: ClassroomRole.subject),
        ],
        closed: [
          room(id: 3, name: 'ป.4/2', closedAt: DateTime.utc(2026, 3, 31)),
        ],
      );
      await pumpList(tester, fake);
      expect(find.text('ครูประจำชั้น'), findsOneWidget);
      expect(find.text('ครูประจำวิชา'), findsOneWidget);
      expect(find.text('ป.4/2'), findsNothing);
      expect(fake.calls, isNot(contains('listClosed')));

      await tester.tap(find.text('ห้องเก่า'));
      await tester.pumpAndSettle();
      expect(fake.calls, contains('listClosed'));
      expect(find.text('ป.4/2'), findsOneWidget);
      expect(find.textContaining('ปิดเมื่อ'), findsOneWidget);
    });

    testWidgets('no open room still offers "ห้องเก่า"', (tester) async {
      final fake = FakeSchoolClassrooms(open: []);
      await pumpList(tester, fake);
      expect(find.text('ยังไม่มีห้องเรียน'), findsOneWidget);
      await tester.tap(find.text('ห้องเก่า'));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่มีห้องเก่า'), findsOneWidget);
    });
  });
}
