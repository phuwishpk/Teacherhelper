import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/admin/admin_home_screen.dart';
import 'package:eduvision/features/google_signin/google_identity_card.dart';
import 'package:eduvision/features/google_signin/google_signin_config.dart';
import 'package:eduvision/features/google_signin/google_signin_gateway.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:eduvision/features/google_signin/google_signin_providers.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:eduvision/features/student/student_account_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'google_signin_fakes.dart';

final _card = find.byKey(const ValueKey('google_identity_card'));
final _link = find.byKey(const ValueKey('google_identity_link'));
final _unlink = find.byKey(const ValueKey('google_identity_unlink'));

class _Pumped {
  _Pumped(this.repo, this.gateway, this.opened);

  final FakeGoogleSignInRepository repo;
  final FakeGoogleSignInGateway gateway;
  final List<Uri> opened;
}

Future<_Pumped> _pump(
  WidgetTester tester, {
  User user = teacherUser,
  FakeGoogleSignInRepository? repo,
  FakeGoogleSignInGateway? gateway,
  GoogleSignInMode mode = GoogleSignInMode.native,
  Widget screen = const Scaffold(
    body: SingleChildScrollView(child: GoogleIdentityCard()),
  ),
}) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final fakeRepo = repo ?? FakeGoogleSignInRepository();
  final fakeGateway = gateway ?? FakeGoogleSignInGateway();
  final opened = <Uri>[];
  final container = await pumpScreen(
    tester,
    screen,
    overrides: [
      tokenStorageProvider.overrideWithValue(
        InMemoryTokenStorage(token: 'tok'),
      ),
      authRepositoryProvider.overrideWithValue(FakeMeAuth(user)),
      googleSignInModeProvider.overrideWithValue(mode),
      googleSignInRepositoryProvider.overrideWithValue(fakeRepo),
      googleSignInGatewayProvider.overrideWithValue(fakeGateway),
      sameTabUrlOpenerProvider.overrideWithValue((url) async {
        opened.add(url);
        return true;
      }),
    ],
  );
  await container.read(sessionProvider.notifier).restore();
  await tester.pumpAndSettle();
  return _Pumped(fakeRepo, fakeGateway, opened);
}

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f);
  await tester.tap(f);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('hidden when Google sign-in is off for the build', (
    tester,
  ) async {
    final p = await _pump(tester, mode: GoogleSignInMode.none);
    expect(_card, findsNothing);
    expect(p.repo.calls, isEmpty);
  });

  testWidgets('hidden when the server has it off', (tester) async {
    await _pump(
      tester,
      repo: FakeGoogleSignInRepository(
        serverConfig: GoogleSignInServerConfig.off,
      ),
    );
    expect(_card, findsNothing);
  });

  testWidgets('hidden when /me/google-identity answers 503', (tester) async {
    await _pump(
      tester,
      repo: FakeGoogleSignInRepository()
        ..identityError = apiError(503, 'google_signin_not_configured'),
    );
    expect(_card, findsNothing);
  });

  testWidgets('a failed read offers to try again', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..identityError = apiError(403, 'forbidden', message: 'ห้าม');
    await _pump(tester, repo: repo);
    expect(find.text('ห้าม'), findsOneWidget);
    repo.identityError = null;
    await _tap(tester, find.text('ลองใหม่'));
    expect(_link, findsOneWidget);
  });

  testWidgets('a teacher reads the notice and links on the phone', (
    tester,
  ) async {
    final p = await _pump(tester);
    expect(find.text('บัญชี Google สำหรับเข้าสู่ระบบ'), findsOneWidget);
    expect(find.text(googleSignInNotice), findsOneWidget);

    await _tap(tester, _link);
    expect(p.gateway.calls, 1);
    expect(p.repo.links, [('google-id-token', true)]);
    expect(find.text('เชื่อมกับ kru@school.ac.th แล้ว'), findsOneWidget);
    expect(
      find.text('เชื่อมบัญชี Google kru@school.ac.th แล้ว'),
      findsOneWidget,
    );
  });

  testWidgets('closing the picker links nothing', (tester) async {
    final p = await _pump(
      tester,
      gateway: FakeGoogleSignInGateway(error: googleCanceled),
    );
    await _tap(tester, _link);
    expect(p.repo.links, isEmpty);
    expect(find.byType(SnackBar), findsNothing);
  });

  testWidgets('an account linked to someone else is explained', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..linkError = apiError(409, 'google_already_linked');
    await _pump(tester, repo: repo);
    await _tap(tester, _link);
    expect(find.textContaining('เชื่อมกับผู้ใช้อื่นอยู่แล้ว'), findsOneWidget);
    expect(_link, findsOneWidget);
  });

  testWidgets('a linked teacher unlinks after confirming', (tester) async {
    final repo = FakeGoogleSignInRepository(
      identity: const GoogleIdentity(
        linked: true,
        email: 'kru@school.ac.th',
        name: 'ครู Google',
      ),
    );
    await _pump(tester, repo: repo);
    expect(find.text('เชื่อมกับ kru@school.ac.th แล้ว'), findsOneWidget);
    expect(find.text('ครู Google'), findsOneWidget);

    await _tap(tester, _unlink);
    expect(find.textContaining('อีเมลและรหัสผ่านได้ตามเดิม'), findsOneWidget);
    await _tap(tester, find.widgetWithText(FilledButton, 'ยกเลิกการเชื่อม'));

    expect(repo.calls, contains('unlink'));
    expect(_link, findsOneWidget);
    expect(find.text('ยกเลิกการเชื่อมบัญชี Google แล้ว'), findsOneWidget);
  });

  testWidgets('a failed unlink keeps the link', (tester) async {
    final repo = FakeGoogleSignInRepository(
      identity: const GoogleIdentity(linked: true, email: 'k@s.th'),
    )..unlinkError = apiError(500, 'server_error', message: 'พัง');
    await _pump(tester, repo: repo);
    await _tap(tester, _unlink);
    await _tap(tester, find.widgetWithText(FilledButton, 'ยกเลิกการเชื่อม'));
    expect(find.text('พัง'), findsOneWidget);
    expect(_unlink, findsOneWidget);
  });

  group('student (PDPA notice, DESIGN §24.14)', () {
    testWidgets('must accept the notice before linking', (tester) async {
      final p = await _pump(tester, user: studentUser);
      await _tap(tester, _link);

      expect(
        find.byKey(const ValueKey('google_notice_dialog')),
        findsOneWidget,
      );
      final next = find.byKey(const ValueKey('google_notice_continue'));
      expect(tester.widget<FilledButton>(next).onPressed, isNull);
      await _tap(tester, find.byKey(const ValueKey('google_notice_accept')));
      await _tap(tester, next);

      expect(p.repo.links, [('google-id-token', true)]);
    });

    testWidgets('cancelling the notice links nothing', (tester) async {
      final p = await _pump(tester, user: studentUser);
      await _tap(tester, _link);
      await _tap(tester, find.text('ยกเลิก'));
      expect(p.gateway.calls, 0);
      expect(p.repo.links, isEmpty);
    });

    testWidgets('a school with Google off for students says so', (
      tester,
    ) async {
      await _pump(
        tester,
        user: studentUser,
        repo: FakeGoogleSignInRepository(
          identity: const GoogleIdentity(linked: false, canLink: false),
        ),
      );
      expect(
        find.byKey(const ValueKey('google_identity_disabled')),
        findsOneWidget,
      );
      expect(_link, findsNothing);
    });

    testWidgets('unlinking reminds the card and PIN still work', (
      tester,
    ) async {
      await _pump(
        tester,
        user: studentUser,
        repo: FakeGoogleSignInRepository(
          identity: const GoogleIdentity(linked: true, email: 's@school.ac.th'),
        ),
      );
      await _tap(tester, _unlink);
      expect(
        find.textContaining('บัตร QR หรือ PIN ได้ตามเดิม'),
        findsOneWidget,
      );
    });
  });

  testWidgets('the web links through Google\'s page in the same tab', (
    tester,
  ) async {
    final p = await _pump(tester, mode: GoogleSignInMode.web);
    await _tap(tester, _link);
    expect(p.repo.webUrls.single, (
      link: true,
      intent: null,
      acceptNotice: true,
    ));
    expect(p.opened, [FakeGoogleSignInRepository.googleUrl]);
    expect(p.gateway.calls, 0);
  });

  testWidgets('"บัญชีของฉัน" of a student holds the card', (tester) async {
    await _pump(
      tester,
      user: studentUser,
      screen: const StudentAccountScreen(),
    );
    expect(find.text('บัญชีของฉัน'), findsOneWidget);
    expect(find.text('ด.ญ. มานี มีนา'), findsOneWidget);
    expect(_card, findsOneWidget);
    expect(find.text('ออกจากระบบ'), findsOneWidget);
  });

  testWidgets('the admin page holds the card', (tester) async {
    await _pump(tester, user: adminUser, screen: const AdminHomeScreen());
    expect(_card, findsOneWidget);
    expect(find.text('เปิดหน้าผู้ดูแลระบบ'), findsOneWidget);
  });
}
