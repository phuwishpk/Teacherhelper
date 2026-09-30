import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/gradebook/gradebook_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'gradebook_fakes.dart';

final _stubs = [
  stubRoute('/courses/:id/gradebook/settings', 'settings'),
  stubRoute('/exams/:id', 'exam'),
  stubRoute('/exams/:id/results', 'exam-results'),
  stubRoute('/assignments/:id', 'assignment'),
  stubRoute('/assignments/:id/review', 'review'),
];

FakeCoursesRepository _courses() => FakeCoursesRepository([
  course(
    classrooms: const [
      {'id': 7, 'name': 'ป.5/1'},
      {'id': 8, 'name': 'ป.5/2'},
    ],
  ),
]);

Future<FakeGradebookRepository> _pump(
  WidgetTester tester, {
  FakeGradebookRepository? repo,
  FakeFileSharer? sharer,
  GradebookScreen screen = const GradebookScreen(courseId: 4),
  Size size = const Size(1280, 2400),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final r = repo ?? FakeGradebookRepository(settings: settingsJson());
  await pumpScreen(
    tester,
    screen,
    overrides: [
      coursesRepositoryProvider.overrideWithValue(_courses()),
      ...gradebookOverrides(r, sharer: sharer),
    ],
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

void _clipboard(WidgetTester tester, String? text) {
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (call) async => call.method == 'Clipboard.getData'
        ? (text == null ? null : {'text': text})
        : null,
  );
  addTearDown(
    () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    ),
  );
}

/// The teacher's gradebook of a classroom (DESIGN §23.9).
void main() {
  testWidgets('an unconfigured course picks a template, then the settings', (
    tester,
  ) async {
    final repo = await _pump(tester, repo: FakeGradebookRepository());
    expect(find.text('เริ่มสมุดคะแนน'), findsOneWidget);
    expect(find.text('คะแนนเก็บ 70 : ปลายภาค 30'), findsOneWidget);
    expect(find.textContaining('คะแนนเก็บ 70 (การบ้าน)'), findsOneWidget);

    await _tap(tester, _key('template_hw_mid_final_affective'));
    expect(repo.args('applyTemplate'), ['hw_mid_final_affective']);
    expect(find.text('settings /courses/4/gradebook/settings'), findsOneWidget);
  });

  testWidgets('"ตั้งหมวดเอง" opens the empty settings', (tester) async {
    await _pump(tester, repo: FakeGradebookRepository());
    await _tap(tester, _key('template_custom'));
    expect(find.text('settings /courses/4/gradebook/settings'), findsOneWidget);
  });

  testWidgets('the grid shows groups, labels, badges, totals and grades', (
    tester,
  ) async {
    final repo = await _pump(tester);
    expect(repo.args('grid'), [(4, 7)]);
    expect(find.text('สมุดคะแนน ค15101'), findsOneWidget);
    expect(find.text('การบ้าน (30%)'), findsOneWidget);
    expect(find.text('จิตพิสัย (20%)'), findsOneWidget);
    expect(find.text('สรุป'), findsOneWidget);
    expect(find.text('ไม่นับเกรด'), findsOneWidget);
    expect(find.text('ไม่ส่ง'), findsOneWidget);
    expect(find.text('ไม่มีคะแนน'), findsNWidgets(2));
    expect(find.text('ยังไม่ถึงกำหนด'), findsOneWidget);
    expect(find.text('รอประกาศผล'), findsOneWidget);
    expect(find.text('ยกเว้น'), findsOneWidget);
    expect(find.text('ตัดออก'), findsOneWidget);
    expect(find.text('มส · อาจติด มส'), findsOneWidget);
    expect(
      tester
          .widget<Text>(
            find.descendant(of: _key('total_101'), matching: find.byType(Text)),
          )
          .data,
      '74',
    );
    expect(
      tester
          .widget<Text>(
            find.descendant(of: _key('grade_102'), matching: find.byType(Text)),
          )
          .data,
      'มส',
    );
    expect(_key('gradebook_in_progress'), findsNothing);

    // Another classroom of the course.
    await _tap(tester, _key('gradebook_classroom_8'));
    expect(repo.args('grid').last, (4, 8));
  });

  testWidgets('a manual exam cell takes a typed score, cleared or excused', (
    tester,
  ) async {
    final repo = await _pump(tester);
    await _tap(tester, _key('cell_101_a51'));
    expect(find.text('สอบปลายภาค · เต็ม 50'), findsOneWidget);
    await tester.enterText(_key('cell_score'), '51');
    await _tap(tester, _key('cell_save'));
    expect(find.text('คะแนนเกินคะแนนเต็ม (50)'), findsOneWidget);
    await tester.enterText(_key('cell_score'), '41,5');
    await _tap(tester, _key('cell_save'));
    expect(repo.args('saveScores').last, {
      'key': 'a51',
      'scores': [
        {'student_id': 101, 'score': 41.5},
      ],
    });
    expect(find.text('บันทึกแล้ว'), findsOneWidget);

    await _tap(tester, _key('cell_101_i60'));
    await tester.enterText(_key('cell_score'), '');
    await _tap(tester, _key('cell_excused'));
    await _tap(tester, _key('cell_save'));
    expect(repo.args('saveScores').last, {
      'key': 'i60',
      'scores': [
        {'student_id': 101, 'score': null, 'excused': true},
      ],
    });
  });

  testWidgets('app-graded work takes "ยกเว้น" only and opens its result', (
    tester,
  ) async {
    final repo = await _pump(tester);
    await _tap(tester, _key('cell_102_a50'));
    expect(_key('cell_score'), findsNothing);
    await _tap(tester, _key('cell_excused'));
    await _tap(tester, _key('cell_save'));
    expect(repo.args('saveScores').last, {
      'key': 'a50',
      'scores': [
        {'student_id': 102, 'excused': false},
      ],
    });

    await _tap(tester, _key('cell_101_a50'));
    expect(find.textContaining('คะแนนจากผลที่ประกาศแล้ว 8/10'), findsOneWidget);
    await _tap(tester, _key('cell_open_result'));
    expect(find.text('review /assignments/50/review'), findsOneWidget);
  });

  testWidgets('an exam cell opens the exam results', (tester) async {
    await _pump(tester);
    await _tap(tester, _key('cell_102_a54'));
    await _tap(tester, _key('cell_open_result'));
    expect(find.text('exam-results /exams/54/results'), findsOneWidget);
  });

  testWidgets('a failed save shows the API message', (tester) async {
    final repo = await _pump(tester);
    repo.failNext = gradebookError(422, 'ข้อมูลคะแนนไม่ถูกต้อง');
    await _tap(tester, _key('cell_101_a51'));
    await tester.enterText(_key('cell_score'), '10');
    await _tap(tester, _key('cell_save'));
    expect(find.text('ข้อมูลคะแนนไม่ถูกต้อง'), findsOneWidget);
  });

  testWidgets('"ให้เต็มทั้งห้อง" from the column menu', (tester) async {
    final repo = await _pump(tester);
    await _tap(tester, _key('col_i60'));
    expect(find.textContaining('นับแล้วในห้องนี้'), findsOneWidget);
    await _tap(tester, _key('column_fill_full'));
    expect(repo.args('fillFull'), ['i60']);
    expect(find.text('ให้เต็มแล้ว 2 คน'), findsOneWidget);
  });

  testWidgets('pasting from Excel previews values and the bad ones', (
    tester,
  ) async {
    _clipboard(tester, '45\tx\r\n60\r\n');
    final repo = await _pump(tester);
    await _tap(tester, _key('col_a51'));
    await _tap(tester, _key('column_paste'));
    expect(find.text('วางคะแนน: สอบปลายภาค'), findsOneWidget);
    expect(
      find.text(
        'อ่านได้ 2 บรรทัด · บันทึกได้ 1 ค่า · ค่าที่ผิด 1 ค่า (ไม่บันทึก)',
      ),
      findsOneWidget,
    );
    expect(find.text('"60" เกินคะแนนเต็ม (50)'), findsOneWidget);
    expect(find.text('33 → '), findsOneWidget);

    // Starting from student 2 leaves one line over the roster.
    await _tap(tester, _key('paste_start'));
    await tester.tap(find.text('เลขที่ 2 ด.ช. บี').last);
    await tester.pumpAndSettle();
    expect(find.textContaining('เกินจำนวนนักเรียน 1 บรรทัด'), findsOneWidget);
    await _tap(tester, _key('paste_save'));
    expect(repo.args('saveScores').last, {
      'key': 'a51',
      'scores': [
        {'student_id': 102, 'score': 45.0},
      ],
    });
    expect(find.text('บันทึกคะแนน 1 คนแล้ว'), findsOneWidget);
  });

  testWidgets('an empty clipboard says so', (tester) async {
    _clipboard(tester, null);
    final repo = await _pump(tester);
    await _tap(tester, _key('col_i61'));
    await _tap(tester, _key('column_paste'));
    expect(find.textContaining('คลิปบอร์ดว่าง'), findsOneWidget);
    expect(repo.args('saveScores'), isEmpty);
  });

  testWidgets('items are added for every classroom, edited and deleted', (
    tester,
  ) async {
    final repo = await _pump(tester);
    await _tap(tester, _key('gradebook_add_item'));
    await _tap(tester, _key('item_save'));
    expect(find.text('กรุณาตั้งชื่อรายการ'), findsOneWidget);
    await tester.enterText(_key('item_name'), 'ความตั้งใจ');
    await tester.enterText(_key('item_max'), '5');
    await _tap(tester, _key('item_category'));
    await tester.tap(find.text('จิตพิสัย (20%)').last);
    await tester.pumpAndSettle();
    await _tap(tester, _key('item_all_classrooms'));
    await _tap(tester, _key('item_save'));
    expect(repo.args('addItem').single, {
      'classroom_ids': [7, 8],
      'category_id': 13,
      'name': 'ความตั้งใจ',
      'max_points': 5.0,
      'is_attendance': false,
    });
    expect(find.text('เพิ่มรายการให้ 2 ห้องแล้ว'), findsOneWidget);

    await _tap(tester, _key('col_i61'));
    await _tap(tester, _key('column_edit'));
    expect(find.text('แก้รายการคะแนน'), findsOneWidget);
    await tester.enterText(_key('item_max'), '25');
    await _tap(tester, _key('item_save'));
    expect(repo.args('updateItem').single, {
      'id': 61,
      'category_id': 13,
      'name': 'การเข้าเรียน',
      'max_points': 25.0,
      'is_attendance': true,
    });

    await _tap(tester, _key('col_i60'));
    await _tap(tester, _key('column_delete'));
    await _tap(tester, find.widgetWithText(FilledButton, 'ลบ'));
    expect(repo.args('deleteItem'), [60]);
  });

  testWidgets('the column menu of homework opens it', (tester) async {
    await _pump(tester);
    await _tap(tester, _key('col_a50'));
    await _tap(tester, _key('column_open'));
    expect(find.text('assignment /assignments/50'), findsOneWidget);
  });

  testWidgets('ร/มส of a student with a note', (tester) async {
    final repo = await _pump(tester);
    await _tap(tester, _key('row_101'));
    expect(find.text('คะแนนรวม 73.8 → 74 เกรด 3'), findsOneWidget);
    await _tap(tester, find.text('ร'));
    await tester.enterText(_key('special_note'), 'รอสอบซ่อม');
    await _tap(tester, _key('special_save'));
    expect(repo.args('setSpecialGrade').single, {
      'classroom_id': 7,
      'student_id': 101,
      'special': 'r',
      'note': 'รอสอบซ่อม',
    });
    expect(find.text('ตั้งเกรด ร แล้ว'), findsOneWidget);

    await _tap(tester, _key('row_102'));
    expect(find.textContaining('อาจติด มส'), findsWidgets);
    await _tap(tester, find.text('ไม่มี'));
    await _tap(tester, _key('special_save'));
    expect(repo.args('setSpecialGrade').last, {
      'classroom_id': 7,
      'student_id': 102,
      'special': null,
      'note': null,
    });
  });

  testWidgets('publish asks first and warns about uncounted cells', (
    tester,
  ) async {
    final repo = await _pump(
      tester,
      repo: FakeGradebookRepository(
        settings: settingsJson(),
        grid: gridJson(uncategorisedColumn: true),
      ),
    );
    expect(_key('gradebook_uncategorised'), findsOneWidget);
    await _tap(tester, _key('gradebook_publish'));
    expect(find.text('ประกาศเกรด?'), findsOneWidget);
    expect(find.textContaining('"รอประกาศผล"'), findsOneWidget);
    expect(find.textContaining('"ยังไม่ถึงกำหนด"'), findsOneWidget);
    expect(find.textContaining('1 รายการที่ยังไม่ระบุหมวด'), findsWidgets);
    await _tap(tester, find.widgetWithText(FilledButton, 'ประกาศ'));
    expect(repo.args('publish'), [7]);
    expect(find.text('ประกาศเกรดแล้ว 2 คน'), findsOneWidget);
  });

  testWidgets('an incomplete classroom is "ระหว่างภาค" and cannot publish', (
    tester,
  ) async {
    await _pump(
      tester,
      repo: FakeGradebookRepository(
        settings: settingsJson(),
        grid: gridJson(complete: false),
      ),
    );
    expect(
      find.textContaining('ยังไม่มีรายการที่นับในหมวด ปลายภาค'),
      findsOneWidget,
    );
    expect(find.text('รวมระหว่างภาค'), findsOneWidget);
    final publish = tester.widget<ButtonStyleButton>(_key('gradebook_publish'));
    expect(publish.onPressed, isNull);

    await _tap(tester, _key('row_101'));
    expect(
      find.text('คะแนนระหว่างภาค 77.14% คิดจาก 2 จาก 4 หมวด (น้ำหนักรวม 50%)'),
      findsOneWidget,
    );
  });

  testWidgets('a category excused for the student is not "no score yet"', (
    tester,
  ) async {
    final grid = gridJson(complete: false);
    final first = (grid['rows'] as List).first as Map<String, dynamic>;
    (first['cells'] as Map<String, dynamic>)['a54'] = cellJson('excused');
    await _pump(
      tester,
      repo: FakeGradebookRepository(settings: settingsJson(), grid: grid),
    );

    await _tap(tester, _key('row_101'));
    expect(
      find.text('กลางภาค (20%): ไม่มีคะแนนในหมวดนี้ (ยกเว้นทุกรายการ)'),
      findsOneWidget,
    );
    expect(find.text('ปลายภาค (30%): ยังไม่มีคะแนน'), findsOneWidget);
  });

  testWidgets('a stale publication can be published again or withdrawn', (
    tester,
  ) async {
    final repo = await _pump(
      tester,
      repo: FakeGradebookRepository(
        settings: settingsJson(),
        grid: gridJson(
          publication: {
            'id': 3,
            'published_at': '2026-09-30T03:00:00+00:00',
            'stale': true,
          },
        ),
      ),
    );
    expect(find.textContaining('มีการเปลี่ยนแปลงหลังประกาศ'), findsOneWidget);
    expect(find.text('ประกาศใหม่'), findsOneWidget);
    await _tap(tester, _key('gradebook_withdraw'));
    await _tap(tester, find.widgetWithText(FilledButton, 'ถอนประกาศ'));
    expect(repo.args('withdraw'), [7]);
    expect(find.text('ถอนประกาศแล้ว'), findsOneWidget);
  });

  testWidgets('export hands the CSV to the share sheet', (tester) async {
    final sharer = FakeFileSharer();
    final repo = await _pump(tester, sharer: sharer);
    await _tap(tester, _key('gradebook_export'));
    expect(repo.args('exportCsv'), [7]);
    expect(sharer.shared.single.$1.fileName, 'gradebook-ค15101-ป.5/1.csv');
    expect(sharer.shared.single.$2, 'สมุดคะแนน ค15101 ป.5/1');

    repo.failNext = gradebookError(
      409,
      'รายวิชานี้ยังไม่ได้ตั้งค่าสมุดคะแนน',
      code: 'gradebook_not_configured',
    );
    await _tap(tester, _key('gradebook_export'));
    expect(find.text('รายวิชานี้ยังไม่ได้ตั้งค่าสมุดคะแนน'), findsOneWidget);
  });

  testWidgets('"กรอกคะแนน" opens the classroom scrolled to the exam', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(600, 1600),
      screen: const GradebookScreen(
        courseId: 4,
        initialClassroomId: 8,
        focusColumn: 'i61',
      ),
    );
    final scroll = tester.widget<SingleChildScrollView>(
      _key('gradebook_horizontal'),
    );
    expect(scroll.controller!.offset, greaterThan(0));
    expect(
      tester.widget<ChoiceChip>(_key('gradebook_classroom_8')).selected,
      isTrue,
    );
  });

  testWidgets('the settings button opens the settings', (tester) async {
    await _pump(tester);
    await _tap(tester, _key('gradebook_open_settings'));
    expect(find.text('settings /courses/4/gradebook/settings'), findsOneWidget);
  });
}
