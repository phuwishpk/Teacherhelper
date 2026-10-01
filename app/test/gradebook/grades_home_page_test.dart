import 'package:eduvision/features/gradebook/grades_home_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'gradebook_fakes.dart';

final _stubs = [
  stubRoute('/courses', 'courses'),
  stubRoute('/courses/:id', 'course'),
  stubRoute('/courses/:id/gradebook', 'gradebook'),
  stubRoute('/courses/:id/gradebook/settings', 'settings'),
];

Future<FakeGradebookRepository> _pump(
  WidgetTester tester, {
  FakeGradebookRepository? repo,
  FakeFileSharer? sharer,
  Size size = const Size(1080, 2600),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final r = repo ?? FakeGradebookRepository();
  await pumpScreen(
    tester,
    const Scaffold(body: GradesHomePage()),
    overrides: gradebookOverrides(r, sharer: sharer),
    extraRoutes: _stubs,
  );
  return r;
}

Finder _key(String k) => find.byKey(ValueKey(k));

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f);
  await tester.pumpAndSettle();
  await tester.tap(f);
  await tester.pumpAndSettle();
}

/// Goes back from a stub route to the page.
Future<void> _back(WidgetTester tester) async {
  await tester.tap(find.byType(BackButton));
  await tester.pumpAndSettle();
}

int _overviewCalls(FakeGradebookRepository repo) =>
    repo.args('overview').length;

/// "ตัดเกรด" of the teacher shell (DESIGN §23.9, §23.11 overview).
void main() {
  testWidgets('every status of the latest year shows with its counts', (
    tester,
  ) async {
    final repo = await _pump(tester);
    expect(repo.args('overview'), [(null, null)]);

    // 2569 is the latest year: its two courses, not the 2568 one.
    expect(_key('grades_course_4'), findsOneWidget);
    expect(_key('grades_course_5'), findsOneWidget);
    expect(_key('grades_course_6'), findsNothing);
    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    expect(find.text('ป.5 · ภาคเรียนที่ 1 · ปีการศึกษา 2569'), findsOneWidget);

    expect(find.text('ป.5/1 · 30 คน'), findsNWidgets(2));
    expect(find.text('ป.5/2 · 28 คน'), findsOneWidget);
    expect(find.text('ยังขาดคะแนน 2 หมวด'), findsOneWidget);
    expect(find.text('ยังไม่มีคะแนน: กลางภาค, ปลายภาค'), findsOneWidget);
    expect(
      find.byTooltip('หมวดที่ยังไม่มีคะแนน: กลางภาค, ปลายภาค'),
      findsOneWidget,
    );
    expect(find.text('พร้อมประกาศ'), findsOneWidget);
    expect(find.text('ประกาศแล้ว 30 ก.ย. 2569'), findsOneWidget);
    expect(find.text('ประกาศแล้ว แต่คะแนนเปลี่ยน'), findsOneWidget);
    expect(find.text('อาจติด มส 2 คน'), findsOneWidget);
    expect(find.text('ร 1 · มส 1'), findsOneWidget);
    // Zero counts are not shown.
    expect(find.textContaining('อาจติด มส'), findsOneWidget);

    // The unconfigured course: a warning on the card and on its classroom.
    expect(find.text('ยังไม่ตั้งหมวดคะแนน'), findsNWidgets(2));
    expect(_key('grades_setup_5'), findsOneWidget);
    expect(_key('grades_setup_4'), findsNothing);
  });

  testWidgets('year and semester chips filter the courses', (tester) async {
    final repo = await _pump(tester);

    await _tap(tester, _key('grades_semester_2'));
    expect(_key('grades_course_4'), findsNothing);
    expect(_key('grades_course_5'), findsOneWidget);

    await _tap(tester, _key('grades_semester_all'));
    expect(_key('grades_course_4'), findsOneWidget);
    expect(_key('grades_course_5'), findsOneWidget);

    await _tap(tester, _key('grades_year_2568'));
    expect(_key('grades_course_6'), findsOneWidget);
    expect(_key('grades_course_4'), findsNothing);
    // One semester only in 2568: no semester chips.
    expect(_key('grades_semester_all'), findsNothing);
    // Filtering happens in the app: still one load.
    expect(_overviewCalls(repo), 1);
  });

  testWidgets('no course: the empty state leads to the courses page', (
    tester,
  ) async {
    final repo = FakeGradebookRepository()..overviewBody = [];
    await _pump(tester, repo: repo);
    expect(find.text('ยังไม่มีรายวิชา'), findsOneWidget);

    await _tap(tester, _key('grades_create_course'));
    expect(find.text('courses /courses'), findsOneWidget);
    repo.overviewBody = overviewJson();
    await _back(tester);
    expect(_overviewCalls(repo), 2);
    expect(_key('grades_course_4'), findsOneWidget);
  });

  testWidgets('a course without classrooms points to the course page', (
    tester,
  ) async {
    final repo = FakeGradebookRepository()
      ..overviewBody = [overviewCourseJson()];
    await _pump(tester, repo: repo);
    await _tap(tester, _key('grades_no_rooms_4'));
    expect(find.text('course /courses/4'), findsOneWidget);
  });

  testWidgets('rows open the gradebook and settings, then reload on return', (
    tester,
  ) async {
    final repo = await _pump(tester);

    await _tap(tester, _key('grades_open_4_8'));
    expect(
      find.text('gradebook /courses/4/gradebook?classroom=8'),
      findsOneWidget,
    );
    await _back(tester);
    expect(_overviewCalls(repo), 2);

    await _tap(tester, _key('grades_room_4_9'));
    expect(
      find.text('gradebook /courses/4/gradebook?classroom=9'),
      findsOneWidget,
    );
    await _back(tester);

    await _tap(tester, _key('grades_settings_4_7'));
    expect(find.text('settings /courses/4/gradebook/settings'), findsOneWidget);
    await _back(tester);
    expect(_overviewCalls(repo), 4);

    // An unconfigured course starts from the gradebook's template picker.
    await _tap(tester, _key('grades_setup_5'));
    expect(find.text('gradebook /courses/5/gradebook'), findsOneWidget);
  });

  testWidgets('export shares the classroom CSV; not before categories', (
    tester,
  ) async {
    final sharer = FakeFileSharer();
    final repo = await _pump(tester, sharer: sharer);

    await _tap(tester, _key('grades_export_4_8'));
    expect(repo.args('exportCsv'), [8]);
    expect(sharer.shared.single.$2, 'สมุดคะแนน ค15101 ป.5/2');

    final disabled = tester.widget<IconButton>(_key('grades_export_5_7'));
    expect(disabled.onPressed, isNull);

    repo.failNext = gradebookError(
      409,
      'ยังไม่ได้ตั้งค่าสมุดคะแนนของรายวิชานี้',
    );
    await _tap(tester, _key('grades_export_4_7'));
    expect(find.text('ยังไม่ได้ตั้งค่าสมุดคะแนนของรายวิชานี้'), findsOneWidget);
    expect(sharer.shared, hasLength(1));
  });

  testWidgets('a load error offers a retry', (tester) async {
    final repo = FakeGradebookRepository()
      ..failOverview = gradebookError(403, 'ไม่มีสิทธิ์ดูหน้านี้');
    await _pump(tester, repo: repo);
    expect(find.text('ไม่มีสิทธิ์ดูหน้านี้'), findsOneWidget);

    await _tap(tester, find.text('ลองใหม่'));
    expect(_key('grades_course_4'), findsOneWidget);
  });

  testWidgets('pull to refresh loads the overview again', (tester) async {
    final repo = await _pump(tester);
    await tester
        .widget<RefreshIndicator>(find.byType(RefreshIndicator))
        .onRefresh();
    await tester.pumpAndSettle();
    expect(_overviewCalls(repo), 2);
  });

  testWidgets('a 360 px phone lays the rows out without overflow', (
    tester,
  ) async {
    await _pump(tester, size: const Size(360, 1600));
    expect(tester.takeException(), isNull);
    expect(find.text('ประกาศแล้ว แต่คะแนนเปลี่ยน'), findsOneWidget);
  });
}
