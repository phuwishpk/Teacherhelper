import 'package:eduvision/core/router/app_router.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'teacher_flow.dart';

/// The same flow `integration_test/teacher_flow_test.dart` runs on a device,
/// here on the host so it runs in CI and counts for coverage.
void main() {
  testWidgets(
    'teacher: sign in -> classroom -> assignment -> question -> layout -> '
    'review queue -> sign out',
    runTeacherFlow,
  );

  testWidgets('a tablet rail has the five teacher destinations and the '
      'dashboard shortcut opens "ตัดเกรด"', (tester) async {
    final app = await TeacherFlowApp.start(tester);
    tester.view.physicalSize = const Size(1280, 1600);
    await tester.pumpAndSettle();
    await signInAsTeacher(tester, app);

    NavigationRail rail() =>
        tester.widget<NavigationRail>(find.byType(NavigationRail));
    expect(find.byType(NavigationBar), findsNothing);
    expect(rail().destinations, hasLength(5));
    expect(
      find.descendant(
        of: find.byType(NavigationRail),
        matching: find.text('ตัดเกรด'),
      ),
      findsOneWidget,
    );

    final shortcut = find.byKey(const ValueKey('dashboard_grades'));
    await tester.ensureVisible(shortcut);
    await tester.pumpAndSettle();
    await tester.tap(shortcut);
    await tester.pumpAndSettle();

    expect(rail().selectedIndex, 4);
    expect(find.text('ยังไม่มีรายวิชา'), findsOneWidget);
    expect(app.lastRequest('GET', '/gradebook/overview').status, 200);

    await tester.tap(
      find.descendant(
        of: find.byType(NavigationRail),
        matching: find.text('หน้าหลัก'),
      ),
    );
    await tester.pumpAndSettle();
    expect(rail().selectedIndex, 0);
    expect(find.text('สวัสดี คุณครู ครูสมศรี'), findsOneWidget);

    // A 360 px phone: five bottom-bar labels, each within its slot.
    tester.view.physicalSize = const Size(360, 800);
    await tester.pumpAndSettle();
    expect(find.byType(NavigationRail), findsNothing);
    for (final label in [
      'หน้าหลัก',
      'ห้องเรียน',
      'การบ้าน',
      'ตรวจทาน',
      'ตัดเกรด',
    ]) {
      final text = find.descendant(
        of: find.byType(NavigationBar),
        matching: find.text(label),
      );
      expect(text, findsOneWidget);
      expect(tester.getSize(text).width, lessThanOrEqualTo(360 / 5));
    }
    expect(app.server.unrouted, isEmpty);
    await app.stop(tester);
  });

  testWidgets('a wrong password shows the API message and stores no token', (
    tester,
  ) async {
    final app = await TeacherFlowApp.start(tester);
    await tester.enterText(
      find.widgetWithText(TextFormField, 'อีเมล'),
      app.server.teacherEmail,
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'รหัสผ่าน'),
      'not-the-password',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เข้าสู่ระบบ'));
    await tester.pumpAndSettle();

    expect(app.location, AppRoutes.login);
    expect(find.text('อีเมลหรือรหัสผ่านไม่ถูกต้อง'), findsOneWidget);
    expect(app.storage.token, isNull);
    expect(app.server.requests.single.status, 422);
    await app.stop(tester);
  });

  testWidgets('a revoked token is dropped at startup and the login screen '
      'shows', (tester) async {
    final app = await TeacherFlowApp.start(tester, storedToken: 'revoked');

    expect(app.location, AppRoutes.login);
    expect(app.storage.token, isNull);
    final me = app.lastRequest('GET', '/me');
    expect(me.status, 401);
    expect(me.authorization, 'Bearer revoked');
    expect(app.server.requests, hasLength(1));
    await app.stop(tester);
  });
}
