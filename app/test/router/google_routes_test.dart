import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:eduvision/features/google_signin/google_signin_providers.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../google_signin/google_signin_fakes.dart';
import '../helpers/pump_screen.dart';

/// The Google sign-in routes (DESIGN §24.9.4): the web flow's returns
/// survive the session restore at startup, and each role may open them.
void main() {
  Future<(ProviderContainer, GoRouter, FakeGoogleSignInRepository)> pump(
    WidgetTester tester, {
    User? user,
    String? startAt,
    bool restoreFirst = true,
  }) async {
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final repo = FakeGoogleSignInRepository()
      ..ticketError = apiError(422, 'google_ticket_invalid');
    final container = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(
          FakeMeAuth(user ?? teacherUser),
        ),
        tokenStorageProvider.overrideWithValue(
          InMemoryTokenStorage(token: user == null ? null : 'tok'),
        ),
        googleSignInModeProvider.overrideWithValue(GoogleSignInMode.none),
        googleSignInRepositoryProvider.overrideWithValue(repo),
      ],
    );
    addTearDown(container.dispose);
    if (restoreFirst) await container.read(sessionProvider.notifier).restore();
    final router = container.read(routerProvider);
    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    if (startAt != null) router.go(startAt);
    if (restoreFirst) {
      await tester.pumpAndSettle();
    } else {
      // The splash spins until the session is restored.
      await tester.pump();
      expect(router.routerDelegate.currentConfiguration.uri.path, '/splash');
      await container.read(sessionProvider.notifier).restore();
      await tester.pumpAndSettle();
    }
    return (container, router, repo);
  }

  String at(GoRouter router) =>
      router.routerDelegate.currentConfiguration.uri.toString();

  testWidgets('a login return that arrives during the restore is kept', (
    tester,
  ) async {
    final (_, router, repo) = await pump(
      tester,
      startAt: '/login/google?ticket=abc',
      restoreFirst: false,
    );
    expect(at(router), '/login/google?ticket=abc');
    expect(repo.calls, contains('ticket abc'));
    await unmountScreen(tester);
  });

  testWidgets('a link result that arrives during the restore is kept for '
      'an admin', (tester) async {
    final (_, router, _) = await pump(
      tester,
      user: adminUser,
      startAt: '/google-link?status=linked',
      restoreFirst: false,
    );
    expect(at(router), '/google-link?status=linked');
    expect(find.text('เชื่อมบัญชี Google แล้ว'), findsWidgets);
    await unmountScreen(tester);
  });

  testWidgets('a student may open the link result and "บัญชีของฉัน"', (
    tester,
  ) async {
    final (_, router, _) = await pump(
      tester,
      user: studentUser,
      startAt: '/google-link?status=google_identity_exists',
    );
    expect(router.routerDelegate.currentConfiguration.uri.path, '/google-link');
    router.go(AppRoutes.studentAccount);
    await tester.pumpAndSettle();
    expect(
      router.routerDelegate.currentConfiguration.uri.path,
      AppRoutes.studentAccount,
    );
    expect(find.text('บัญชีของฉัน'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('a signed-in user leaves the login return', (tester) async {
    final (_, router, repo) = await pump(
      tester,
      user: adminUser,
      startAt: '/login/google?ticket=abc',
    );
    expect(router.routerDelegate.currentConfiguration.uri.path, '/admin-home');
    expect(repo.calls, isNot(contains('ticket abc')));
    await unmountScreen(tester);
  });

  testWidgets('signed out, a link result goes to the login page', (
    tester,
  ) async {
    final (_, router, _) = await pump(
      tester,
      startAt: '/google-link?status=linked',
    );
    expect(router.routerDelegate.currentConfiguration.uri.path, '/login');
    await unmountScreen(tester);
  });

  testWidgets('the first confirmation is public', (tester) async {
    final (_, router, _) = await pump(tester, startAt: '/login/google/confirm');
    expect(
      router.routerDelegate.currentConfiguration.uri.path,
      '/login/google/confirm',
    );
    expect(find.text('ยืนยันตัวตนครั้งแรก'), findsOneWidget);
    await unmountScreen(tester);
  });

  test('route helpers', () {
    expect(AppRoutes.isPublic(AppRoutes.googleFirstLinkQr), isTrue);
    expect(AppRoutes.isPublic(AppRoutes.googleLinkResult), isFalse);
    expect(AppRoutes.isGoogleReturn('/login/google'), isTrue);
    expect(AppRoutes.isGoogleReturn('/login/google/confirm'), isFalse);
    expect(AppRoutes.isStudentArea(AppRoutes.studentAccount), isTrue);
  });
}
