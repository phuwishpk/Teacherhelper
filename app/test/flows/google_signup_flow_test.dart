import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/google_signin/google_signin_gateway.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:eduvision/features/google_signin/google_signin_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../google_signin/google_signin_fakes.dart';
import '../helpers/fake_api_server.dart';
import 'teacher_flow.dart';

/// DESIGN §24.9.3, #71: a teacher without an account taps the Google button
/// on the login page or "สมัครด้วย Google" on the register page and lands
/// on the teacher home, with no registration form and no approval wait.
/// The whole app on the fake API; only the account picker is faked.
void main() {
  Future<TeacherFlowApp> start(WidgetTester tester) {
    final server = FakeApiServer()..googleSignIn = true;
    return TeacherFlowApp.start(
      tester,
      server: server,
      overrides: [
        googleSignInModeProvider.overrideWithValue(GoogleSignInMode.native),
        googleSignInGatewayProvider.overrideWithValue(
          FakeGoogleSignInGateway(),
        ),
      ],
    );
  }

  Future<void> tapAndExpectHome(
    WidgetTester tester,
    TeacherFlowApp app,
    String buttonKey,
  ) async {
    final button = find.byKey(ValueKey(buttonKey));
    await tester.ensureVisible(button);
    await tester.pumpAndSettle();
    await tester.tap(button);
    await tester.pumpAndSettle();

    final signIn = app.lastRequest('POST', '/auth/google');
    expect(signIn.jsonBody, {'id_token': 'google-id-token', 'intent': 'staff'});
    expect(app.location, AppRoutes.home);
    expect(await app.storage.read(), app.server.teacherToken);
    expect(find.text('สมัครใช้งาน (ครู)'), findsNothing);
    expect(
      find.byKey(const ValueKey('google_not_linked_dialog')),
      findsNothing,
    );
    expect(app.server.unrouted, isEmpty);
  }

  testWidgets('from the login page straight to the teacher home', (
    tester,
  ) async {
    final app = await start(tester);
    expect(app.location, AppRoutes.login);
    await tapAndExpectHome(tester, app, 'google_signin_button');
    await app.stop(tester);
  });

  testWidgets('from the register page straight to the teacher home', (
    tester,
  ) async {
    final app = await start(tester);
    app.router.go(AppRoutes.register);
    await tester.pumpAndSettle();
    expect(app.location, AppRoutes.register);
    await tapAndExpectHome(tester, app, 'google_signup_button');
    await app.stop(tester);
  });
}
