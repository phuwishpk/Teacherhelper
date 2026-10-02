import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/auth/login_screen.dart';
import 'package:eduvision/features/auth/register_screen.dart';
import 'package:eduvision/features/google_signin/google_signin_config.dart';
import 'package:eduvision/features/google_signin/google_signin_gateway.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:eduvision/features/google_signin/google_signin_providers.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:eduvision/features/google_signin/google_web_return_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'google_signin_fakes.dart';

/// "สมัครด้วย Google" of the register page (DESIGN §24.9.5).

final _button = find.byKey(const ValueKey('google_signup_button'));
final _error = find.byKey(const ValueKey('google_signup_error'));
final _banner = find.byKey(const ValueKey('register_google_banner'));
final _dialog = find.byKey(const ValueKey('google_not_linked_dialog'));
final _password = find.widgetWithText(
  TextFormField,
  'รหัสผ่าน (อย่างน้อย 8 ตัว)',
);

const _schools = [SchoolOption(id: 7, name: 'โรงเรียนสาธิต EduVision')];

/// `/me` plus the school list and the registration of the register page.
class _Auth extends FakeMeAuth {
  _Auth({this.list = _schools}) : super(teacherUser);

  final List<SchoolOption> list;
  final tickets = <String?>[];
  final emails = <String>[];

  @override
  Future<List<SchoolOption>> schools() async => list;

  @override
  Future<void> register({
    int? schoolId,
    required String name,
    required String email,
    required String password,
    String? googleLinkTicket,
  }) async {
    tickets.add(googleLinkTicket);
    emails.add(email);
  }
}

class _Harness {
  _Harness(this.container, this.repo, this.gateway, this.storage, this.opened);

  final ProviderContainer container;
  final FakeGoogleSignInRepository repo;
  final FakeGoogleSignInGateway gateway;
  final InMemoryTokenStorage storage;
  final List<Uri> opened;
}

Future<_Harness> _pump(
  WidgetTester tester, {
  GoogleSignInMode mode = GoogleSignInMode.native,
  GoogleRegistration? google,
  FakeGoogleSignInRepository? repo,
  FakeGoogleSignInGateway? gateway,
  bool opens = true,
  _Auth? auth,
}) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final fakeRepo = repo ?? FakeGoogleSignInRepository();
  final fakeGateway = gateway ?? FakeGoogleSignInGateway();
  final storage = InMemoryTokenStorage();
  final opened = <Uri>[];
  final container = await pumpScreen(
    tester,
    RegisterScreen(google: google),
    overrides: [
      tokenStorageProvider.overrideWithValue(storage),
      authRepositoryProvider.overrideWithValue(auth ?? _Auth()),
      googleSignInModeProvider.overrideWithValue(mode),
      googleSignInRepositoryProvider.overrideWithValue(fakeRepo),
      googleSignInGatewayProvider.overrideWithValue(fakeGateway),
      sameTabUrlOpenerProvider.overrideWithValue((url) async {
        opened.add(url);
        return opens;
      }),
    ],
    extraRoutes: [
      GoRoute(path: '/login', builder: (_, _) => const Text('login-stub')),
    ],
  );
  return _Harness(container, fakeRepo, fakeGateway, storage, opened);
}

Future<void> _tap(WidgetTester tester) async {
  await tester.ensureVisible(_button);
  await tester.tap(_button);
  await tester.pumpAndSettle();
}

bool _passwordFocused(WidgetTester tester) => tester
    .widget<EditableText>(
      find.descendant(of: _password, matching: find.byType(EditableText)),
    )
    .focusNode
    .hasFocus;

void main() {
  group('the button', () {
    testWidgets('is offered with the PDPA notice when Google is available', (
      tester,
    ) async {
      await _pump(tester);
      expect(_button, findsOneWidget);
      expect(find.text('สมัครด้วย Google'), findsOneWidget);
      expect(find.text(googleSignInNotice), findsOneWidget);
      expect(find.text('หรือ'), findsOneWidget);
      expect(_banner, findsNothing);
    });

    testWidgets('is hidden when the page opened with a Google prefill', (
      tester,
    ) async {
      await _pump(
        tester,
        google: const GoogleRegistration(
          linkTicket: 'tkt',
          name: 'ครูใหม่ ใจดี',
          email: 'new@school.ac.th',
        ),
      );
      expect(_banner, findsOneWidget);
      expect(_button, findsNothing);
    });

    testWidgets('is hidden when this build cannot use Google', (tester) async {
      final h = await _pump(tester, mode: GoogleSignInMode.none);
      expect(_button, findsNothing);
      expect(h.repo.calls, isEmpty, reason: 'no config request at all');
    });

    testWidgets('is hidden when the server has sign-in off', (tester) async {
      await _pump(
        tester,
        repo: FakeGoogleSignInRepository(
          serverConfig: GoogleSignInServerConfig.off,
        ),
      );
      expect(_button, findsNothing);
    });
  });

  group('on Android', () {
    testWidgets('an unknown Google account fills this page, no dialog', (
      tester,
    ) async {
      final auth = _Auth();
      final repo = FakeGoogleSignInRepository()..signInError = staffNotLinked();
      final h = await _pump(tester, repo: repo, auth: auth);
      await _tap(tester);

      expect(h.repo.signIns, [('google-id-token', GoogleIntent.staff)]);
      expect(_dialog, findsNothing);
      expect(_banner, findsOneWidget);
      expect(find.textContaining('new@school.ac.th จะเชื่อม'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('register_google_helper')),
        findsOneWidget,
      );
      expect(_button, findsNothing);
      expect(find.widgetWithText(TextFormField, 'ครูใหม่ ใจดี'), findsOne);
      expect(find.widgetWithText(TextFormField, 'new@school.ac.th'), findsOne);
      expect(_passwordFocused(tester), isTrue);

      await tester.enterText(_password, 'secret-pass-1');
      await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
      await tester.pumpAndSettle();
      expect(auth.tickets, ['a' * 48]);
      expect(auth.emails, ['new@school.ac.th']);
      expect(find.text('สมัครสำเร็จ'), findsOneWidget);
    });

    testWidgets('a name the teacher typed is kept', (tester) async {
      final repo = FakeGoogleSignInRepository()..signInError = staffNotLinked();
      await _pump(tester, repo: repo);
      await tester.enterText(
        find.widgetWithText(TextFormField, 'ชื่อ-นามสกุล'),
        'ครูสมใจ',
      );
      await _tap(tester);
      expect(find.widgetWithText(TextFormField, 'ครูสมใจ'), findsOne);
      expect(find.widgetWithText(TextFormField, 'new@school.ac.th'), findsOne);
    });

    testWidgets('a new teacher is signed in at once, no form (#71)', (
      tester,
    ) async {
      final auth = _Auth(
        list: const [
          ..._schools,
          SchoolOption(id: 9, name: 'โรงเรียนบ้านหนองบัว'),
        ],
      );
      final repo = FakeGoogleSignInRepository()
        ..signInErrors.addAll([staffNeedsSchool(), null]);
      final h = await _pump(tester, repo: repo, auth: auth);
      // The dialog keeps the button's spinner running: pump, do not settle.
      await tester.ensureVisible(_button);
      await tester.tap(_button);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(
        find.byKey(const ValueKey('google_school_picker')),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('google_school_9')));
      await tester.pumpAndSettle();

      expect(h.repo.signInSchools, [null, 9]);
      expect(h.container.read(sessionProvider), isA<SignedIn>());
      expect(auth.tickets, isEmpty, reason: 'no registration request');
      expect(_banner, findsNothing);
      expect(_error, findsNothing);
    });

    testWidgets('an account linked already signs in', (tester) async {
      final h = await _pump(tester);
      await _tap(tester);

      expect(h.container.read(sessionProvider), isA<SignedIn>());
      expect(await h.storage.read(), '5|google-token');
      expect(_error, findsNothing);
      expect(_banner, findsNothing);
    });

    testWidgets('other errors are explained under the button', (tester) async {
      final repo = FakeGoogleSignInRepository()
        ..signInError = apiError(403, 'google_domain_not_allowed');
      await _pump(tester, repo: repo);
      await _tap(tester);
      expect(tester.widget<Text>(_error).data, contains('โดเมนนี้'));
      expect(_banner, findsNothing);
      expect(_button, findsOneWidget);
    });

    testWidgets('an admin e-mail gets the server\'s message', (tester) async {
      final repo = FakeGoogleSignInRepository()
        ..signInError = apiError(
          404,
          'google_not_linked',
          message: 'เข้าสู่ระบบด้วยรหัสผ่านก่อน',
        );
      await _pump(tester, repo: repo);
      await _tap(tester);
      expect(tester.widget<Text>(_error).data, 'เข้าสู่ระบบด้วยรหัสผ่านก่อน');
      expect(_dialog, findsNothing);
    });

    testWidgets('closing the account picker says nothing', (tester) async {
      final h = await _pump(
        tester,
        gateway: FakeGoogleSignInGateway(error: googleCanceled),
      );
      await _tap(tester);
      expect(_error, findsNothing);
      expect(h.repo.signIns, isEmpty);
    });
  });

  group('on the web', () {
    testWidgets('marks the sign-up, then opens Google in the same tab', (
      tester,
    ) async {
      final h = await _pump(tester, mode: GoogleSignInMode.web);
      await _tap(tester);

      expect(h.repo.webUrls.single.link, isFalse);
      expect(h.repo.webUrls.single.intent, GoogleIntent.staff);
      expect(h.opened, [FakeGoogleSignInRepository.googleUrl]);
      expect(h.storage.googleSignUpStartedAt, isNotNull);
      expect(h.gateway.calls, 0);
    });

    testWidgets('a chooser that does not open clears the mark', (tester) async {
      final h = await _pump(tester, mode: GoogleSignInMode.web, opens: false);
      await _tap(tester);
      expect(tester.widget<Text>(_error).data, contains('ลองอีกครั้ง'));
      expect(h.storage.googleSignUpStartedAt, isNull);
    });

    testWidgets('the login page\'s Google button clears an old mark', (
      tester,
    ) async {
      final storage = InMemoryTokenStorage(
        googleSignUpStartedAt: DateTime.now(),
      );
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await pumpScreen(
        tester,
        const LoginScreen(initialTab: LoginTab.teacher),
        overrides: [
          tokenStorageProvider.overrideWithValue(storage),
          authRepositoryProvider.overrideWithValue(_Auth()),
          googleSignInModeProvider.overrideWithValue(GoogleSignInMode.web),
          googleSignInRepositoryProvider.overrideWithValue(
            FakeGoogleSignInRepository(),
          ),
          sameTabUrlOpenerProvider.overrideWithValue((_) async => true),
        ],
      );
      final login = find.byKey(const ValueKey('google_signin_button'));
      await tester.ensureVisible(login);
      await tester.tap(login);
      await tester.pumpAndSettle();
      expect(storage.googleSignUpStartedAt, isNull);
    });
  });

  group('the return on /login/google', () {
    Future<InMemoryTokenStorage> pumpReturn(
      WidgetTester tester, {
      DateTime? mark,
      String? ticket = 'web-ticket',
      String? error,
      FakeGoogleSignInRepository? repo,
      _Auth? auth,
    }) async {
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final storage = InMemoryTokenStorage(googleSignUpStartedAt: mark);
      final container = ProviderContainer(
        overrides: [
          tokenStorageProvider.overrideWithValue(storage),
          authRepositoryProvider.overrideWithValue(auth ?? _Auth()),
          googleSignInModeProvider.overrideWithValue(GoogleSignInMode.web),
          googleSignInRepositoryProvider.overrideWithValue(
            repo ??
                (FakeGoogleSignInRepository()..ticketError = staffNotLinked()),
          ),
          sameTabUrlOpenerProvider.overrideWithValue((_) async => true),
        ],
      );
      addTearDown(container.dispose);
      final router = GoRouter(
        initialLocation: '/',
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) =>
                GoogleLoginReturnScreen(ticket: ticket, error: error),
          ),
          GoRoute(
            path: AppRoutes.register,
            builder: (_, state) =>
                RegisterScreen(google: state.extra as GoogleRegistration?),
          ),
          GoRoute(
            path: AppRoutes.login,
            builder: (_, _) => const Text('login-stub'),
          ),
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
      return storage;
    }

    testWidgets('after "สมัครด้วย Google" goes straight to the filled page', (
      tester,
    ) async {
      final storage = await pumpReturn(tester, mark: DateTime.now());
      expect(_dialog, findsNothing);
      expect(find.text('สมัครใช้งาน (ครู)'), findsOneWidget);
      expect(_banner, findsOneWidget);
      expect(find.widgetWithText(TextFormField, 'new@school.ac.th'), findsOne);
      expect(storage.googleSignUpStartedAt, isNull);
    });

    testWidgets('a new teacher picks the school and the mark is kept', (
      tester,
    ) async {
      final repo = FakeGoogleSignInRepository()
        ..ticketError = staffNeedsSchool();
      final storage = await pumpReturn(
        tester,
        mark: DateTime.now(),
        repo: repo,
        auth: _Auth(
          list: const [
            ..._schools,
            SchoolOption(id: 9, name: 'โรงเรียนบ้านหนองบัว'),
          ],
        ),
      );
      await tester.tap(find.byKey(const ValueKey('google_school_7')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      expect(repo.webUrlSchools, [7]);
      expect(
        storage.googleSignUpStartedAt,
        isNotNull,
        reason: 'a school that approves teachers itself comes back here',
      );
      expect(_dialog, findsNothing);
    });

    testWidgets('without the mark asks like the login page', (tester) async {
      await pumpReturn(tester);
      expect(_dialog, findsOneWidget);
    });

    testWidgets('an old mark is ignored and cleared', (tester) async {
      final storage = await pumpReturn(
        tester,
        mark: DateTime.now().subtract(const Duration(minutes: 30)),
      );
      expect(_dialog, findsOneWidget);
      expect(storage.googleSignUpStartedAt, isNull);
    });

    testWidgets('a cancelled chooser clears the mark too', (tester) async {
      final storage = await pumpReturn(
        tester,
        mark: DateTime.now(),
        ticket: null,
        error: 'cancelled',
      );
      expect(
        find.byKey(const ValueKey('google_return_message')),
        findsOneWidget,
      );
      expect(storage.googleSignUpStartedAt, isNull);
    });
  });
}
