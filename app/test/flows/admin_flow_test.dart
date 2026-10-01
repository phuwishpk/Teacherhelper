import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/google_classroom/google_browser_connect.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_api_server.dart';
import 'teacher_flow.dart';

/// The unified login page (DESIGN §7.4) as an admin, with the real router,
/// session and dio interceptor against [FakeApiServer].
void main() {
  testWidgets(
    'admin: the teacher tab -> /admin-home -> one-time panel link -> sign out',
    (tester) async {
      final opened = <Uri>[];
      final app = await TeacherFlowApp.start(
        tester,
        overrides: [
          externalUrlOpenerProvider.overrideWithValue((url) async {
            opened.add(url);
            return true;
          }),
        ],
      );
      expect(app.location, AppRoutes.login);

      await tester.enterText(
        find.widgetWithText(TextFormField, 'อีเมล'),
        app.server.adminEmail,
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'รหัสผ่าน'),
        app.server.adminPassword,
      );
      await tester.tap(find.widgetWithText(FilledButton, 'เข้าสู่ระบบ'));
      await tester.pumpAndSettle();

      expect(app.location, AppRoutes.adminHome);
      expect(find.text('สวัสดี คุณผู้ดูแลระบบ'), findsOneWidget);
      expect(app.storage.user?['role'], 'admin');
      expect(find.byType(NavigationBar), findsNothing);

      // No teacher screen for an admin, whatever the location.
      app.router.go(AppRoutes.classroomNew);
      await tester.pumpAndSettle();
      expect(app.location, AppRoutes.adminHome);

      await tester.tap(find.text('เปิดหน้าผู้ดูแลระบบ'));
      await tester.pumpAndSettle();
      expect(app.server.handoffs, 1);
      final handoff = app.lastRequest('POST', '/auth/admin-handoff');
      expect(handoff.authorization, 'Bearer ${app.server.adminToken}');
      expect(opened, [Uri.parse(FakeApiServer.handoffUrl)]);
      expect(app.server.unrouted, isEmpty);

      await tester.tap(find.text('ออกจากระบบ'));
      await tester.pumpAndSettle();
      expect(app.location, AppRoutes.login);
      expect(app.storage.token, isNull);
      expect(app.server.logouts, 1);
      await app.stop(tester);
    },
  );

  testWidgets('the old student login link opens the student tab', (
    tester,
  ) async {
    final app = await TeacherFlowApp.start(tester);

    app.router.go(AppRoutes.studentLogin);
    await tester.pumpAndSettle();
    expect(app.location, AppRoutes.login);
    expect(
      app.router.routerDelegate.currentConfiguration.uri.toString(),
      AppRoutes.loginStudent,
    );
    expect(find.widgetWithText(TextFormField, 'PIN 6 หลัก'), findsOneWidget);
    await app.stop(tester);
  });
}
