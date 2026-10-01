import 'package:eduvision/features/gradebook/gradebook_settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'gradebook_fakes.dart';

Future<FakeGradebookRepository> _pump(
  WidgetTester tester, [
  FakeGradebookRepository? repo,
]) async {
  tester.view.physicalSize = const Size(1080, 3200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final r = repo ?? FakeGradebookRepository(settings: settingsJson());
  await pumpScreen(
    tester,
    const GradebookSettingsScreen(courseId: 4),
    overrides: gradebookOverrides(r),
  );
  return r;
}

Finder _key(String k) => find.byKey(ValueKey(k));

bool _saveEnabled(WidgetTester tester) =>
    tester
        .widget<ButtonStyleButton>(_key('gradebook_settings_save'))
        .onPressed !=
    null;

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f);
  await tester.pumpAndSettle();
  await tester.tap(f);
  await tester.pumpAndSettle();
}

/// "ตั้งค่าสมุดคะแนน" (DESIGN §23.2, §23.9): the total must be 100.
void main() {
  testWidgets('saves only when the weights add up to 100', (tester) async {
    final repo = await _pump(tester);
    expect(find.text('รวม 100%'), findsOneWidget);
    expect(_saveEnabled(tester), isTrue);

    await tester.enterText(_key('category_weight_3'), '10');
    await tester.pumpAndSettle();
    expect(find.text('รวม 90% (ต้องเท่ากับ 100%)'), findsOneWidget);
    expect(_saveEnabled(tester), isFalse);

    await _tap(tester, _key('category_add'));
    await tester.enterText(_key('category_name_4'), 'โครงงาน');
    await tester.enterText(_key('category_weight_4'), '10');
    await tester.pumpAndSettle();
    expect(find.text('รวม 100%'), findsOneWidget);
    await _tap(tester, _key('category_drop_4'));
    await tester.tap(find.text('ตัดต่ำสุด 2 รายการ').last);
    await tester.pumpAndSettle();
    await _tap(tester, _key('category_default_4'));
    expect(_saveEnabled(tester), isTrue);

    await _tap(tester, _key('gradebook_settings_save'));
    final saved = repo.args('saveCategories').single as List;
    expect(saved, hasLength(5));
    expect(saved[3], {
      'id': 13,
      'name': 'จิตพิสัย',
      'weight': 10.0,
      'drop_lowest': 0,
      'is_homework_default': false,
    });
    expect(saved[4], {
      'name': 'โครงงาน',
      'weight': 10.0,
      'drop_lowest': 2,
      'is_homework_default': true,
    });
    expect(
      (saved[0] as Map)['is_homework_default'],
      isFalse,
      reason: 'one homework default only',
    );
    expect(repo.args('saveCutoffs'), isEmpty, reason: 'cutoffs unchanged');
    expect(find.text('stub-home'), findsOneWidget);
  });

  testWidgets('a blank or repeated name blocks saving', (tester) async {
    await _pump(tester);
    await tester.enterText(_key('category_name_1'), 'การบ้าน');
    await tester.pumpAndSettle();
    expect(find.text('ชื่อหมวดซ้ำกัน'), findsOneWidget);
    expect(_saveEnabled(tester), isFalse);
    await tester.enterText(_key('category_name_1'), ' ');
    await tester.pumpAndSettle();
    expect(find.text('ตั้งชื่อหมวดให้ครบ'), findsOneWidget);
  });

  testWidgets('removing a category with items asks first', (tester) async {
    final repo = await _pump(tester);
    await _tap(tester, _key('category_remove_3'));
    await tester.enterText(_key('category_weight_2'), '50');
    await tester.pumpAndSettle();
    expect(find.text('รวม 100%'), findsOneWidget);
    await _tap(tester, _key('gradebook_settings_save'));
    expect(find.text('ลบหมวดที่มีรายการ?'), findsOneWidget);
    expect(find.textContaining('รวม 2 รายการ'), findsOneWidget);
    await _tap(tester, find.text('ยกเลิก'));
    expect(repo.args('saveCategories'), isEmpty);

    await _tap(tester, _key('gradebook_settings_save'));
    await _tap(tester, find.widgetWithText(FilledButton, 'ลบและบันทึก'));
    expect((repo.args('saveCategories').single as List), hasLength(3));
  });

  testWidgets('cutoffs are checked, reset to the defaults, and saved', (
    tester,
  ) async {
    final repo = await _pump(
      tester,
      FakeGradebookRepository(
        settings: settingsJson(cutoffs: [85, 80, 75, 70, 65, 60, 55]),
      ),
    );
    await tester.enterText(_key('cutoff_1'), '90');
    await tester.pumpAndSettle();
    expect(
      find.text('เกณฑ์เกรดต้องลดหลั่นจากเกรด 4 ลงไปเกรด 1 และห้ามซ้ำกัน'),
      findsOneWidget,
    );
    expect(_saveEnabled(tester), isFalse);

    await _tap(tester, _key('cutoffs_default'));
    expect(_saveEnabled(tester), isTrue);
    await _tap(tester, _key('gradebook_settings_save'));
    expect(repo.args('saveCutoffs'), [null]);
    expect(repo.args('saveCategories'), isEmpty);
  });

  testWidgets('custom cutoffs are sent as a list; errors are shown', (
    tester,
  ) async {
    final repo = await _pump(tester);
    await tester.enterText(_key('cutoff_0'), '85');
    await tester.pumpAndSettle();
    repo.failNext = gradebookError(
      422,
      'น้ำหนักทุกหมวดรวมกันต้องเท่ากับ 100',
      code: 'weights_not_100',
    );
    await _tap(tester, _key('gradebook_settings_save'));
    expect(find.text('น้ำหนักทุกหมวดรวมกันต้องเท่ากับ 100'), findsOneWidget);
    await _tap(tester, _key('gradebook_settings_save'));
    expect(repo.args('saveCutoffs').last, [85, 75, 70, 65, 60, 55, 50]);
  });

  testWidgets('an empty set starts with no category', (tester) async {
    await _pump(tester, FakeGradebookRepository());
    expect(find.text('เพิ่มหมวดอย่างน้อยหนึ่งหมวด'), findsOneWidget);
    expect(find.text('รวม 0% (ต้องเท่ากับ 100%)'), findsOneWidget);
    expect(_saveEnabled(tester), isFalse);
  });

  testWidgets('a load error can be retried', (tester) async {
    final repo = _FailingSettings();
    await _pump(tester, repo);
    expect(find.text('โหลดไม่ได้'), findsOneWidget);
    final before = repo.loads;
    await _tap(tester, find.text('ลองใหม่'));
    expect(repo.loads, greaterThan(before));
  });
}

class _FailingSettings extends FakeGradebookRepository {
  int loads = 0;

  @override
  Future<Never> settings(int courseId) async {
    loads++;
    throw gradebookError(500, 'โหลดไม่ได้', code: 'server_error');
  }
}
