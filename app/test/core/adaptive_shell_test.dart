import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/theme/breakpoints.dart';
import 'package:eduvision/core/widgets/adaptive_shell.dart';
import 'package:eduvision/core/widgets/auth_layout.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §27.2, §27.3: one shell, three window sizes.
void main() {
  final taps = <String>[];

  Widget shell({int index = 0, Widget? headerActions}) => MaterialApp(
    theme: AppTheme.light(),
    home: AdaptiveShell(
      title: 'Krucheck',
      accountName: 'ครูสมศรี',
      accountCaption: 'โรงเรียนสาธิต',
      destinations: const [
        ShellDestination('หน้าหลัก', Icons.home_outlined, Icons.home),
        ShellDestination(
          'ห้องเรียน',
          Icons.groups_outlined,
          Icons.groups,
          badge: 2,
        ),
        ShellDestination(
          'การบ้าน',
          Icons.assignment_outlined,
          Icons.assignment,
        ),
      ],
      selectedIndex: index,
      onSelected: (i) => taps.add('tab $i'),
      primaryAction: ShellAction(
        tooltip: 'สแกนใบงาน',
        icon: Icons.document_scanner_outlined,
        onPressed: () => taps.add('scan'),
      ),
      actions: [
        ShellAction(
          tooltip: 'ตั้งค่า',
          icon: Icons.settings_outlined,
          onPressed: () => taps.add('settings'),
        ),
        ShellAction(
          key: const ValueKey('sign_out'),
          tooltip: 'ออกจากระบบ',
          icon: Icons.logout,
          badge: 3,
          onPressed: () => taps.add('sign out'),
        ),
      ],
      body: const Center(child: Text('เนื้อหา')),
      floatingActionButton: const FloatingActionButton(
        onPressed: null,
        child: Icon(Icons.add),
      ),
      headerActions: headerActions,
    ),
  );

  Future<void> pumpAt(WidgetTester tester, double width, Widget app) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(app);
    await tester.pumpAndSettle();
  }

  setUp(taps.clear);

  test('the three window sizes', () {
    expect(Breakpoints.of(359), WindowSize.compact);
    expect(Breakpoints.of(599.9), WindowSize.compact);
    expect(Breakpoints.of(600), WindowSize.medium);
    expect(Breakpoints.of(1199), WindowSize.medium);
    expect(Breakpoints.of(1200), WindowSize.expanded);
  });

  testWidgets('a phone has the app bar and a bottom bar', (tester) async {
    await pumpAt(tester, 390, shell());

    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(NavigationRail), findsNothing);
    expect(find.widgetWithText(AppBar, 'Krucheck'), findsOneWidget);
    expect(find.byKey(const ValueKey('nav_badge_1')), findsOneWidget);
    expect(find.byType(FloatingActionButton), findsOneWidget);
    expect(find.text('ครูสมศรี'), findsNothing);

    await tester.tap(find.text('การบ้าน'));
    await tester.tap(find.byTooltip('ตั้งค่า'));
    await tester.tap(find.byKey(const ValueKey('sign_out')));
    expect(taps, ['tab 2', 'settings', 'sign out']);
    // The page gutter of a phone.
    expect(tester.element(find.text('เนื้อหา')).pageGutter, 16);
  });

  testWidgets('a tablet has the app bar and a rail with the main action', (
    tester,
  ) async {
    await pumpAt(tester, 820, shell());

    final rail = tester.widget<NavigationRail>(find.byType(NavigationRail));
    expect(rail.extended, isFalse);
    expect(rail.destinations, hasLength(3));
    expect(find.byType(NavigationBar), findsNothing);
    expect(find.widgetWithText(AppBar, 'Krucheck'), findsOneWidget);
    expect(tester.element(find.text('เนื้อหา')).pageGutter, 24);

    await tester.tap(find.byTooltip('สแกนใบงาน'));
    await tester.tap(find.text('ห้องเรียน'));
    expect(taps, ['scan', 'tab 1']);
  });

  testWidgets('a desktop has the sidebar, the tab name and its actions', (
    tester,
  ) async {
    await pumpAt(
      tester,
      1440,
      shell(
        index: 1,
        headerActions: FilledButton(
          onPressed: () => taps.add('create'),
          child: const Text('สร้างห้องเรียน'),
        ),
      ),
    );

    expect(find.byType(AppBar), findsNothing);
    final rail = tester.widget<NavigationRail>(find.byType(NavigationRail));
    expect((rail.extended, rail.selectedIndex), (true, 1));
    expect(find.byType(BrandMark), findsOneWidget);
    expect(find.text('Krucheck'), findsOneWidget);
    expect(find.text('ครูสมศรี'), findsOneWidget);
    expect(find.text('โรงเรียนสาธิต'), findsOneWidget);
    // The tab's name heads the page; the same word is in the sidebar.
    expect(find.text('ห้องเรียน'), findsNWidgets(2));
    expect(tester.element(find.text('เนื้อหา')).pageGutter, 32);

    await tester.tap(find.widgetWithText(FilledButton, 'สแกนใบงาน'));
    await tester.tap(find.text('สร้างห้องเรียน'));
    await tester.tap(find.byTooltip('ออกจากระบบ'));
    await tester.tap(
      find.descendant(
        of: find.byType(NavigationRail),
        matching: find.text('การบ้าน'),
      ),
    );
    expect(taps, ['scan', 'create', 'sign out', 'tab 2']);
  });

  testWidgets('the sign-in layout shows the brand above one card', (
    tester,
  ) async {
    for (final width in [360.0, 820.0, 1440.0]) {
      await pumpAt(
        tester,
        width,
        MaterialApp(
          theme: AppTheme.dark(),
          home: const Scaffold(
            body: AuthLayout(
              child: Column(
                children: [
                  Text('เข้าสู่ระบบ'),
                  ListTile(title: Text('สแกนบัตร QR')),
                ],
              ),
            ),
          ),
        ),
      );
      expect(find.text('Krucheck'), findsOneWidget);
      expect(find.text('เข้าสู่ระบบ'), findsOneWidget);
      expect(tester.takeException(), isNull, reason: 'width $width');
      // Never wider than the window, never wider than the form's cap.
      final card = tester.getSize(find.byType(Material).last);
      expect(card.width, lessThanOrEqualTo(width - 32));
      expect(card.width, lessThanOrEqualTo(440));
    }
  });
}
