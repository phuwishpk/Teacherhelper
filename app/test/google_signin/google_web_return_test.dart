import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/google_signin/google_signin_providers.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:eduvision/features/google_signin/google_web_return_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'google_signin_fakes.dart';

final _message = find.byKey(const ValueKey('google_return_message'));

List<GoRoute> get _stubs => [
  for (final path in [
    AppRoutes.login,
    AppRoutes.googleFirstLink,
    AppRoutes.register,
    AppRoutes.settings,
    AppRoutes.student,
    AppRoutes.studentAccount,
    AppRoutes.adminHome,
  ])
    GoRoute(
      path: path,
      builder: (_, _) => Scaffold(body: Text('page $path')),
    ),
];

/// The ticket screen keeps its spinner after a sign-in (the app's router
/// leaves it then), so it is pumped without settling.
Future<ProviderContainer> _pumpTicket(
  WidgetTester tester,
  String ticket,
  FakeGoogleSignInRepository repo,
) async {
  final container = ProviderContainer(
    overrides: [
      tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      authRepositoryProvider.overrideWithValue(FakeMeAuth(teacherUser)),
      googleSignInRepositoryProvider.overrideWithValue(repo),
    ],
  );
  addTearDown(container.dispose);
  final router = GoRouter(
    initialLocation: '/',
    routes: [
      GoRoute(
        path: '/',
        builder: (_, _) => GoogleLoginReturnScreen(ticket: ticket),
      ),
      ..._stubs,
    ],
  );
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: MaterialApp.router(routerConfig: router),
    ),
  );
  for (var i = 0; i < 5; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
  return container;
}

Future<ProviderContainer> _pump(
  WidgetTester tester,
  Widget screen, {
  required FakeGoogleSignInRepository repo,
  User user = teacherUser,
  bool signedIn = false,
}) async {
  final container = await pumpScreen(
    tester,
    screen,
    overrides: [
      tokenStorageProvider.overrideWithValue(
        InMemoryTokenStorage(token: signedIn ? 'tok' : null),
      ),
      authRepositoryProvider.overrideWithValue(FakeMeAuth(user)),
      googleSignInRepositoryProvider.overrideWithValue(repo),
    ],
    extraRoutes: _stubs,
  );
  if (signedIn) {
    await container.read(sessionProvider.notifier).restore();
    await tester.pumpAndSettle();
  }
  return container;
}

void main() {
  group('/login/google', () {
    testWidgets('a ticket is redeemed and the user signed in', (tester) async {
      final repo = FakeGoogleSignInRepository();
      final container = await _pumpTicket(tester, 'c' * 48, repo);
      expect(find.text('กำลังเข้าสู่ระบบ…'), findsOneWidget);
      expect(repo.calls, ['ticket ${'c' * 48}']);
      expect(container.read(sessionProvider), isA<SignedIn>());
    });

    testWidgets('a cancelled chooser says so and goes back', (tester) async {
      final repo = FakeGoogleSignInRepository();
      await _pump(
        tester,
        const GoogleLoginReturnScreen(error: 'cancelled'),
        repo: repo,
      );
      expect(
        tester.widget<Text>(_message).data,
        'ยกเลิกการเข้าสู่ระบบด้วย Google แล้ว',
      );
      expect(repo.calls, isEmpty);
      await tester.tap(find.text('กลับไปหน้าเข้าสู่ระบบ'));
      await tester.pumpAndSettle();
      expect(find.text('page /login'), findsOneWidget);
    });

    testWidgets('no ticket at all is an expired link', (tester) async {
      await _pump(
        tester,
        const GoogleLoginReturnScreen(),
        repo: FakeGoogleSignInRepository(),
      );
      expect(tester.widget<Text>(_message).data, contains('หมดอายุ'));
    });

    testWidgets('a spent ticket is explained', (tester) async {
      final repo = FakeGoogleSignInRepository()
        ..ticketError = apiError(422, 'google_ticket_invalid');
      await _pumpTicket(tester, 'c' * 48, repo);
      expect(tester.widget<Text>(_message).data, contains('หมดอายุหรือถูกใช้'));
    });

    testWidgets('an unknown student continues to the first confirmation', (
      tester,
    ) async {
      final repo = FakeGoogleSignInRepository()
        ..ticketError = studentNotLinked();
      final container = await _pumpTicket(tester, 'c' * 48, repo);
      expect(find.text('page ${AppRoutes.googleFirstLink}'), findsOneWidget);
      expect(container.read(googleLinkTicketProvider), 'b' * 48);
    });
  });

  group('/google-link', () {
    testWidgets('linked: the teacher goes back to the settings', (
      tester,
    ) async {
      await _pump(
        tester,
        const GoogleLinkResultScreen(status: 'linked'),
        repo: FakeGoogleSignInRepository(),
        signedIn: true,
      );
      expect(find.text('เชื่อมบัญชี Google แล้ว'), findsWidgets);
      await tester.tap(find.text('กลับ'));
      await tester.pumpAndSettle();
      expect(find.text('page ${AppRoutes.settings}'), findsOneWidget);
    });

    testWidgets('a failure explains the code; a student returns to the '
        'account page', (tester) async {
      await _pump(
        tester,
        const GoogleLinkResultScreen(status: 'google_already_linked'),
        repo: FakeGoogleSignInRepository(),
        user: studentUser,
        signedIn: true,
      );
      expect(find.text('เชื่อมบัญชี Google ไม่สำเร็จ'), findsOneWidget);
      expect(
        tester
            .widget<Text>(
              find.byKey(const ValueKey('google_link_result_message')),
            )
            .data,
        contains('ผู้ใช้อื่น'),
      );
      await tester.tap(find.text('กลับ'));
      await tester.pumpAndSettle();
      expect(find.text('page ${AppRoutes.studentAccount}'), findsOneWidget);
    });

    testWidgets('an admin returns to the admin page', (tester) async {
      await _pump(
        tester,
        const GoogleLinkResultScreen(status: 'linked'),
        repo: FakeGoogleSignInRepository(),
        user: adminUser,
        signedIn: true,
      );
      await tester.tap(find.text('กลับ'));
      await tester.pumpAndSettle();
      expect(find.text('page ${AppRoutes.adminHome}'), findsOneWidget);
    });
  });
}
