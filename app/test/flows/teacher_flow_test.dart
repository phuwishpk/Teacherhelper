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
