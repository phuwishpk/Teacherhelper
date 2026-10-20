import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/widgets/adaptive_shell.dart';
import 'package:eduvision/core/widgets/function_menu.dart';
import 'package:eduvision/features/help/help_content.dart';
import 'package:eduvision/features/home/teacher_functions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §35: the searchable menu of every teacher function.
void main() {
  final calls = <String>[];

  List<MenuGroup> menu() => teacherMenu(
    TeacherMenuActions(
      openTab: (i) => calls.add('tab $i'),
      push: (route) => calls.add('push $route'),
      signOut: () => calls.add('sign out'),
      openAssistant: () => calls.add('assistant'),
      tabs: [
        for (final label in [
          'หน้าหลัก',
          'ห้องเรียน',
          'การบ้าน',
          'ตรวจทาน',
          'ตัดเกรด',
        ])
          (
            label: label,
            icon: Icons.circle_outlined,
            selectedIcon: Icons.circle,
            badge: 0,
          ),
      ],
    ),
    guide: (tab, where, help) => calls.add('guide $tab ${help ?? '-'}'),
  );

  setUp(calls.clear);

  test('every function has its own id and goes somewhere real', () {
    final groups = menu();
    final functions = [for (final g in groups) ...g.functions];
    expect(functions.map((f) => f.id).toSet(), hasLength(functions.length));
    expect(functions.length, greaterThan(40));

    final topics = {for (final t in teacherHelpTopics) t.id};
    for (final f in functions) {
      calls.clear();
      f.run();
      final call = calls.single;
      if (call.startsWith('guide ')) {
        final topic = call.split(' ').last;
        expect(topics, contains(topic), reason: '${f.id} names a guide topic');
      } else if (call.startsWith('push ')) {
        expect(call, startsWith('push /'), reason: f.id);
      }
    }
  });

  test('search finds by title, place and keyword, titles first', () {
    final groups = menu();
    List<String> ids(String q) => [
      for (final (_, f) in searchFunctions(groups, q)) f.id,
    ];

    expect(ids('csv'), ['grade_export']);
    expect(ids('ลืมรหัส'), ['student_password']);
    expect(ids('ตัวชี้วัดของฉัน').first, 'my_indicators');
    expect(ids('เกรด'), containsAll(['grade_publish', 'special_grade']));
    // Every word must match.
    expect(ids('ประกาศ เกรด'), ['grade_publish']);
    expect(ids('ไม่มีอะไรแบบนี้'), isEmpty);
    expect(ids('   '), isEmpty);
  });

  Future<void> pump(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light(),
        home: AdaptiveShell(
          title: 'Krucheck',
          destinations: const [
            ShellDestination('หน้าหลัก', Icons.home_outlined, Icons.home),
            ShellDestination('ห้องเรียน', Icons.groups_outlined, Icons.groups),
          ],
          selectedIndex: 0,
          onSelected: (i) => calls.add('tab $i'),
          body: const Center(child: Text('เนื้อหา')),
          menu: ({required inDrawer}) => FunctionMenu(
            groups: menu(),
            selectedGroup: 0,
            inDrawer: inDrawer,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('the desktop sidebar searches and folds out each tab', (
    tester,
  ) async {
    await pump(tester, 1440);
    expect(find.byType(NavigationRail), findsNothing);

    // A tab's label opens the tab; its arrow folds out its functions.
    await tester.tap(find.byKey(const ValueKey('menu_group_4')));
    expect(calls, ['tab 4']);
    expect(find.byKey(const ValueKey('menu_function_gradebook')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('menu_group_toggle_4')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('menu_function_grade_export')));
    expect(calls.last, 'guide 4 gradebook');

    // Searching shows the matches; Enter opens the first.
    await tester.enterText(
      find.byKey(const ValueKey('function_search')),
      'ตารางสอน',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('function_groups')), findsNothing);
    expect(
      find.byKey(const ValueKey('function_result_timetable')),
      findsOneWidget,
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(calls.last, 'push /timetable');
    expect(find.byKey(const ValueKey('function_groups')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  for (final width in [390.0, 820.0]) {
    testWidgets('a ${width.toInt()} px window opens the menu as a drawer', (
      tester,
    ) async {
      await pump(tester, width);
      expect(find.byKey(const ValueKey('function_search')), findsNothing);

      await tester.tap(find.byIcon(Icons.menu));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('function_search')),
        'key',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('function_result_ai_key')));
      await tester.pumpAndSettle();

      expect(calls.last, 'push /settings');
      // The drawer closed before going there.
      expect(find.byKey(const ValueKey('function_search')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }
}
