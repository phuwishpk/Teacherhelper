import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classroom_detail_screen.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../classrooms/classroom_fakes.dart';
import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'google_signin_fakes.dart';

const _linked = RosterStudent(
  studentId: 502,
  studentNumber: 1,
  name: 'ด.ญ. มานี มีนา',
  googleLinked: true,
);
const _notLinked = RosterStudent(
  studentId: 503,
  studentNumber: 2,
  name: 'ด.ช. ปิติ ชูใจ',
  googleLinked: false,
);

Future<FakeGoogleSignInRepository> _pump(
  WidgetTester tester, {
  ClassroomRole role = ClassroomRole.homeroom,
  FakeGoogleSignInRepository? repo,
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final classrooms = FakeSchoolClassrooms(open: [room(role: role)]);
  classrooms.rosters[7] = const [_linked, _notLinked];
  final google = repo ?? FakeGoogleSignInRepository();
  await pumpScreen(
    tester,
    const ClassroomDetailScreen(classroomId: 7),
    overrides: [
      classroomsRepositoryProvider.overrideWithValue(classrooms),
      coursesRepositoryProvider.overrideWithValue(
        FakeCoursesRepository([course(id: 4)]),
      ),
      googleSignInRepositoryProvider.overrideWithValue(google),
    ],
  );
  return google;
}

Future<void> _openMenu(WidgetTester tester, String name) async {
  final menu = find.descendant(
    of: find.widgetWithText(ListTile, name),
    matching: find.byTooltip('ตัวเลือก'),
  );
  await tester.ensureVisible(menu);
  await tester.tap(menu);
  await tester.pumpAndSettle();
}

void main() {
  test('google_linked of the roster', () {
    expect(
      RosterStudent.fromJson({
        'student_id': 1,
        'student_number': 1,
        'name': 'ก',
        'google_linked': true,
      }).googleLinked,
      isTrue,
    );
    expect(
      RosterStudent.fromJson({
        'student_id': 1,
        'student_number': 1,
        'name': 'ก',
        'google_linked': null,
      }).googleLinked,
      isNull,
    );
  });

  testWidgets('the homeroom teacher unlinks a student\'s Google account', (
    tester,
  ) async {
    final google = await _pump(tester);
    await _openMenu(tester, 'ด.ญ. มานี มีนา');
    await tester.tap(find.text('ยกเลิกการเชื่อม Google'));
    await tester.pumpAndSettle();
    expect(find.textContaining('บัตร QR หรือ PIN ได้ตามเดิม'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'ยกเลิกการเชื่อม'));
    await tester.pumpAndSettle();

    expect(google.unlinkedStudents, [502]);
    expect(
      find.text('ยกเลิกการเชื่อม Google ของ ด.ญ. มานี มีนา แล้ว'),
      findsOneWidget,
    );
  });

  testWidgets('no unlink for a student without Google', (tester) async {
    await _pump(tester);
    await _openMenu(tester, 'ด.ช. ปิติ ชูใจ');
    expect(find.text('ยกเลิกการเชื่อม Google'), findsNothing);
  });

  testWidgets('a subject teacher cannot unlink', (tester) async {
    await _pump(tester, role: ClassroomRole.subject);
    await _openMenu(tester, 'ด.ญ. มานี มีนา');
    expect(find.text('ยกเลิกการเชื่อม Google'), findsNothing);
  });

  testWidgets('a refused unlink is explained', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..unlinkError = apiError(
        403,
        'not_homeroom_teacher',
        message: 'ไม่ใช่ครูประจำชั้น',
      );
    await _pump(tester, repo: repo);
    await _openMenu(tester, 'ด.ญ. มานี มีนา');
    await tester.tap(find.text('ยกเลิกการเชื่อม Google'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'ยกเลิกการเชื่อม'));
    await tester.pumpAndSettle();
    expect(find.text('ไม่ใช่ครูประจำชั้น'), findsOneWidget);
  });
}
