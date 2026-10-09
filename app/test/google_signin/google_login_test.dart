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
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'google_signin_fakes.dart';

final _button = find.byKey(const ValueKey('google_signin_button'));
final _error = find.byKey(const ValueKey('google_signin_error'));

class _Harness {
  _Harness(this.container, this.repo, this.gateway, this.storage, this.opened);

  final ProviderContainer container;
  final FakeGoogleSignInRepository repo;
  final FakeGoogleSignInGateway gateway;
  final InMemoryTokenStorage storage;
  final List<Uri> opened;

  SessionState get session => container.read(sessionProvider);
}

Future<_Harness> _pump(
  WidgetTester tester, {
  LoginTab tab = LoginTab.teacher,
  GoogleSignInMode mode = GoogleSignInMode.native,
  FakeGoogleSignInRepository? repo,
  FakeGoogleSignInGateway? gateway,
  FakeMeAuth? auth,
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
    LoginScreen(initialTab: tab),
    overrides: [
      tokenStorageProvider.overrideWithValue(storage),
      authRepositoryProvider.overrideWithValue(
        auth ?? FakeMeAuth(tab == LoginTab.student ? studentUser : teacherUser),
      ),
      googleSignInModeProvider.overrideWithValue(mode),
      googleSignInRepositoryProvider.overrideWithValue(fakeRepo),
      googleSignInGatewayProvider.overrideWithValue(fakeGateway),
      sameTabUrlOpenerProvider.overrideWithValue((url) async {
        opened.add(url);
        return true;
      }),
    ],
    extraRoutes: [
      GoRoute(
        path: AppRoutes.register,
        builder: (_, state) =>
            RegisterScreen(google: state.extra as GoogleRegistration?),
      ),
      GoRoute(
        path: AppRoutes.googleFirstLink,
        builder: (_, _) => const Scaffold(body: Text('first-link-page')),
      ),
    ],
  );
  return _Harness(container, fakeRepo, fakeGateway, storage, opened);
}

/// Taps the button; a dialog keeps the button's spinner running, so this
/// pumps a fixed time instead of settling.
Future<void> _tapGoogle(WidgetTester tester) async {
  await tester.ensureVisible(_button);
  await tester.tap(_button);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 500));
}

void main() {
  testWidgets('no Google button when this build cannot use Google', (
    tester,
  ) async {
    final h = await _pump(tester, mode: GoogleSignInMode.none);
    expect(_button, findsNothing);
    expect(h.repo.calls, isEmpty, reason: 'no config request at all');
  });

  testWidgets('no Google button when the server has sign-in off', (
    tester,
  ) async {
    await _pump(
      tester,
      repo: FakeGoogleSignInRepository(
        serverConfig: GoogleSignInServerConfig.off,
      ),
    );
    expect(_button, findsNothing);
  });

  testWidgets('a config the app cannot read hides the button', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..configError = apiError(500, 'server_error');
    await _pump(tester, repo: repo);
    expect(_button, findsNothing);
  });

  testWidgets('the web needs the server\'s web flow', (tester) async {
    await _pump(
      tester,
      mode: GoogleSignInMode.web,
      repo: FakeGoogleSignInRepository(
        serverConfig: const GoogleSignInServerConfig(
          enabled: true,
          webFlow: false,
        ),
      ),
    );
    expect(_button, findsNothing);
  });

  testWidgets('both tabs show the button with the PDPA notice', (tester) async {
    await _pump(tester);
    expect(_button, findsOneWidget);
    expect(find.text('เข้าสู่ระบบด้วย Google'), findsOneWidget);
    expect(find.text(googleSignInNotice), findsOneWidget);

    await tester.tap(find.text('นักเรียน'));
    await tester.pumpAndSettle();
    expect(_button, findsOneWidget);
    expect(find.textContaining('ครั้งแรกต้องยืนยันด้วย PIN'), findsOneWidget);
  });

  testWidgets('a linked teacher signs in with the staff intent', (
    tester,
  ) async {
    final h = await _pump(tester);
    await _tapGoogle(tester);

    expect(h.gateway.calls, 1);
    expect(h.repo.signIns, [('google-id-token', GoogleIntent.staff)]);
    expect(h.session, isA<SignedIn>());
    expect(await h.storage.read(), '5|google-token');
    expect(_error, findsNothing);
  });

  group('a new teacher (#71)', () {
    const schools = [
      SchoolOption(id: 7, name: 'โรงเรียนสาธิต Krucheck'),
      SchoolOption(id: 9, name: 'โรงเรียนบ้านหนองบัว'),
    ];
    final picker = find.byKey(const ValueKey('google_school_picker'));

    testWidgets('is signed in at once, no registration dialog', (tester) async {
      final h = await _pump(tester);
      await _tapGoogle(tester);

      expect(h.session, isA<SignedIn>());
      expect((h.session as SignedIn).user.isTeacher, isTrue);
      expect(h.repo.signInSchools, [null]);
      expect(
        find.byKey(const ValueKey('google_not_linked_dialog')),
        findsNothing,
      );
      expect(find.text('สมัครใช้งาน (ครู)'), findsNothing);
    });

    testWidgets('picks the school first when there are several', (
      tester,
    ) async {
      final repo = FakeGoogleSignInRepository()
        ..signInErrors.addAll([staffNeedsSchool(), null]);
      final h = await _pump(
        tester,
        repo: repo,
        auth: FakeMeAuth(teacherUser, schoolList: schools),
      );
      await _tapGoogle(tester);
      expect(picker, findsOneWidget);
      expect(find.text('โรงเรียนบ้านหนองบัว'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('google_school_9')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      expect(h.gateway.calls, 1, reason: 'the same ID token is sent again');
      expect(h.repo.signIns, [
        ('google-id-token', GoogleIntent.staff),
        ('google-id-token', GoogleIntent.staff),
      ]);
      expect(h.repo.signInSchools, [null, 9]);
      expect(h.session, isA<SignedIn>());
      expect(_error, findsNothing);
    });

    testWidgets('a token that grew old while picking is asked again', (
      tester,
    ) async {
      final repo = FakeGoogleSignInRepository()
        ..signInErrors.addAll([
          staffNeedsSchool(),
          apiError(422, 'google_token_invalid'),
          null,
        ]);
      final h = await _pump(
        tester,
        repo: repo,
        auth: FakeMeAuth(teacherUser, schoolList: schools),
      );
      await _tapGoogle(tester);
      await tester.tap(find.byKey(const ValueKey('google_school_7')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      expect(h.gateway.calls, 2);
      expect(h.repo.signIns.last.$1, 'google-id-token-2');
      expect(h.repo.signInSchools, [null, 7, 7]);
      expect(h.session, isA<SignedIn>());
    });

    testWidgets('one school is used without asking', (tester) async {
      final repo = FakeGoogleSignInRepository()
        ..signInErrors.addAll([staffNeedsSchool(), null]);
      final h = await _pump(
        tester,
        repo: repo,
        auth: FakeMeAuth(teacherUser, schoolList: [schools.first]),
      );
      await _tapGoogle(tester);
      expect(picker, findsNothing);
      expect(h.repo.signInSchools, [null, 7]);
      expect(h.session, isA<SignedIn>());
    });

    testWidgets('closing the school list cancels quietly', (tester) async {
      final repo = FakeGoogleSignInRepository()
        ..signInErrors.add(staffNeedsSchool());
      final h = await _pump(
        tester,
        repo: repo,
        auth: FakeMeAuth(teacherUser, schoolList: schools),
      );
      await _tapGoogle(tester);
      await tester.tapAt(const Offset(5, 5));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      expect(picker, findsNothing);
      expect(h.repo.signIns, hasLength(1));
      expect(h.session, isNot(isA<SignedIn>()));
      expect(_error, findsNothing);
      expect(_button, findsOneWidget);
    });
  });

  testWidgets('the student tab sends the student intent', (tester) async {
    final h = await _pump(tester, tab: LoginTab.student);
    await _tapGoogle(tester);
    expect(h.repo.signIns.single.$2, GoogleIntent.student);
    expect((h.session as SignedIn).user.isStudent, isTrue);
  });

  testWidgets('closing the account picker says nothing', (tester) async {
    final h = await _pump(
      tester,
      gateway: FakeGoogleSignInGateway(error: googleCanceled),
    );
    await _tapGoogle(tester);
    expect(_error, findsNothing);
    expect(h.repo.signIns, isEmpty);
    expect(h.session, isNot(isA<SignedIn>()));
  });

  testWidgets('an unknown teacher may register with the Google prefill', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository()..signInError = staffNotLinked();
    await _pump(tester, repo: repo);
    await _tapGoogle(tester);

    expect(
      find.byKey(const ValueKey('google_not_linked_dialog')),
      findsOneWidget,
    );
    expect(find.text('new@school.ac.th'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('google_register_teacher')));
    await tester.pumpAndSettle();

    expect(find.text('สมัครใช้งาน (ครู)'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('register_google_banner')),
      findsOneWidget,
    );
    expect(find.widgetWithText(TextFormField, 'ครูใหม่ ใจดี'), findsOneWidget);
    expect(
      find.widgetWithText(TextFormField, 'new@school.ac.th'),
      findsOneWidget,
    );
  });

  testWidgets('or log in with the password and link in the settings', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository()..signInError = staffNotLinked();
    await _pump(tester, repo: repo);
    await _tapGoogle(tester);
    await tester.tap(find.text('เข้าสู่ระบบด้วยรหัสผ่าน'));
    await tester.pumpAndSettle();

    expect(find.text('สมัครใช้งาน (ครู)'), findsNothing);
    expect(
      tester.widget<Text>(_error).data,
      contains('เชื่อมบัญชี Google" ในหน้าตั้งค่า'),
    );
  });

  testWidgets('an admin e-mail gets the server\'s message only', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository()
      ..signInError = apiError(
        404,
        'google_not_linked',
        message:
            'เข้าสู่ระบบด้วยรหัสผ่านก่อน แล้วกดเชื่อมบัญชี Google ในหน้าผู้ดูแลระบบ',
      );
    await _pump(tester, repo: repo);
    await _tapGoogle(tester);
    expect(
      find.byKey(const ValueKey('google_not_linked_dialog')),
      findsNothing,
    );
    expect(tester.widget<Text>(_error).data, contains('หน้าผู้ดูแลระบบ'));
  });

  testWidgets('an unknown student goes to the first confirmation', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository()..signInError = studentNotLinked();
    final h = await _pump(tester, tab: LoginTab.student, repo: repo);
    await _tapGoogle(tester);

    expect(find.text('first-link-page'), findsOneWidget);
    expect(h.container.read(googleLinkTicketProvider), 'b' * 48);
  });

  for (final (code, text) in [
    ('student_google_disabled', 'ยังไม่เปิดให้นักเรียนเข้าสู่ระบบด้วย Google'),
    ('google_domain_not_allowed', 'โดเมนนี้'),
  ]) {
    testWidgets('$code is explained in Thai', (tester) async {
      final repo = FakeGoogleSignInRepository()
        ..signInError = apiError(403, code, message: 'x');
      await _pump(tester, tab: LoginTab.student, repo: repo);
      await _tapGoogle(tester);
      expect(tester.widget<Text>(_error).data, contains(text));
    });
  }

  testWidgets('a pending teacher reads the server\'s reason', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..signInError = apiError(
        403,
        'account_not_active',
        message: 'บัญชีของคุณกำลังรอการอนุมัติจากผู้ดูแลระบบ',
      );
    await _pump(tester, repo: repo);
    await _tapGoogle(tester);
    expect(
      tester.widget<Text>(_error).data,
      'บัญชีของคุณกำลังรอการอนุมัติจากผู้ดูแลระบบ',
    );
  });

  testWidgets('switching tabs clears the last Google error', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..signInError = apiError(403, 'google_domain_not_allowed');
    await _pump(tester, repo: repo);
    await _tapGoogle(tester);
    expect(_error, findsOneWidget);
    await tester.tap(find.text('นักเรียน'));
    await tester.pumpAndSettle();
    expect(_error, findsNothing);
  });

  testWidgets('the web opens Google\'s account chooser in the same tab', (
    tester,
  ) async {
    final h = await _pump(tester, mode: GoogleSignInMode.web);
    await _tapGoogle(tester);

    expect(h.repo.webUrls.single.link, isFalse);
    expect(h.repo.webUrls.single.intent, GoogleIntent.staff);
    expect(h.opened, [FakeGoogleSignInRepository.googleUrl]);
    expect(h.gateway.calls, 0);
  });
}
