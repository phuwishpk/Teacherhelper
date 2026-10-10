import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/auth/student_qr_scan_screen.dart';
import 'package:eduvision/features/google_signin/google_first_link_screen.dart';
import 'package:eduvision/features/google_signin/google_signin_config.dart';
import 'package:eduvision/features/google_signin/google_signin_providers.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'google_signin_fakes.dart';

final _submit = find.byKey(const ValueKey('google_first_link_submit'));
final _qr = find.byKey(const ValueKey('google_first_link_qr'));
final _accept = find.byKey(const ValueKey('google_notice_accept'));
final _error = find.byKey(const ValueKey('google_first_link_error'));

final _ticket = 'b' * 48;

Future<ProviderContainer> _pump(
  WidgetTester tester, {
  required FakeGoogleSignInRepository repo,
  String? ticket,
  bool qrScanSupported = true,
}) async {
  tester.view.physicalSize = const Size(800, 2000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final container = await pumpScreen(
    tester,
    GoogleFirstLinkScreen(qrScanSupported: qrScanSupported),
    overrides: [
      tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      authRepositoryProvider.overrideWithValue(FakeMeAuth(studentUser)),
      googleSignInRepositoryProvider.overrideWithValue(repo),
      googleLinkTicketProvider.overrideWith(
        () => GoogleLinkTicketNotifier()..initial = ticket,
      ),
    ],
    extraRoutes: [
      GoRoute(
        path: AppRoutes.googleFirstLinkQr,
        builder: (_, _) => const Scaffold(body: Text('qr-link-scanner')),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (_, _) => const Scaffold(body: Text('login-page')),
      ),
    ],
  );
  return container;
}

Future<void> _fillPin(WidgetTester tester) async {
  await tester.enterText(
    find.byKey(const ValueKey('link_username')),
    ' s1234567 ',
  );
  await tester.enterText(
    find.byKey(const ValueKey('link_password')),
    'ปลาทอง99',
  );
}

Future<void> _tap(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f);
  await tester.tap(f);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('without a ticket the page says to start again', (tester) async {
    await _pump(tester, repo: FakeGoogleSignInRepository());
    expect(find.byKey(const ValueKey('google_link_expired')), findsOneWidget);
    await _tap(tester, find.text('กลับไปหน้าเข้าสู่ระบบ'));
    expect(find.text('login-page'), findsOneWidget);
  });

  testWidgets('nothing is sent before the notice is accepted', (tester) async {
    final repo = FakeGoogleSignInRepository();
    await _pump(tester, repo: repo, ticket: _ticket);

    expect(find.text(googleSignInNotice), findsOneWidget);
    expect(tester.widget<FilledButton>(_submit).onPressed, isNull);
    expect(tester.widget<OutlinedButton>(_qr).onPressed, isNull);
    expect(find.textContaining('กดยอมรับข้อความแจ้งก่อน'), findsOneWidget);
    expect(repo.pinLinks, isEmpty);
  });

  testWidgets('PIN confirmation links Google and signs the student in', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository();
    final container = await _pump(tester, repo: repo, ticket: _ticket);
    await _tap(tester, _accept);
    await _fillPin(tester);
    await _tap(tester, _submit);

    expect(repo.pinLinks.single, {
      'link_ticket': _ticket,
      'username': 's1234567',
      'password': 'ปลาทอง99',
    });
    expect(container.read(sessionProvider), isA<SignedIn>());
    expect(container.read(googleLinkTicketProvider), isNull, reason: 'spent');
  });

  testWidgets('a wrong PIN keeps the ticket for another try', (tester) async {
    final repo = FakeGoogleSignInRepository()
      ..pinError = apiError(422, 'invalid_credentials');
    final container = await _pump(tester, repo: repo, ticket: _ticket);
    await _tap(tester, _accept);
    await _fillPin(tester);
    await _tap(tester, _submit);

    expect(
      tester.widget<Text>(_error).data,
      'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
    );
    expect(container.read(googleLinkTicketProvider), _ticket);
    expect(container.read(sessionProvider), isNot(isA<SignedIn>()));
  });

  testWidgets('a disabled school is a Google message, not a PIN one', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository()
      ..pinError = apiError(403, 'student_google_disabled');
    await _pump(tester, repo: repo, ticket: _ticket);
    await _tap(tester, _accept);
    await _fillPin(tester);
    await _tap(tester, _submit);
    expect(
      tester.widget<Text>(_error).data,
      contains('ยังไม่เปิดให้นักเรียนเข้าสู่ระบบด้วย Google'),
    );
  });

  testWidgets('an expired ticket turns the page into "start again"', (
    tester,
  ) async {
    final repo = FakeGoogleSignInRepository()
      ..pinError = apiError(422, 'link_ticket_invalid');
    final container = await _pump(tester, repo: repo, ticket: _ticket);
    await _tap(tester, _accept);
    await _fillPin(tester);
    await _tap(tester, _submit);

    expect(container.read(googleLinkTicketProvider), isNull);
    expect(find.byKey(const ValueKey('google_link_expired')), findsOneWidget);
  });

  testWidgets('the card scanner opens after accepting', (tester) async {
    await _pump(tester, repo: FakeGoogleSignInRepository(), ticket: _ticket);
    await _tap(tester, _accept);
    await _tap(tester, _qr);
    expect(find.text('qr-link-scanner'), findsOneWidget);
  });

  testWidgets('on the web the scanner explains it needs the Android app', (
    tester,
  ) async {
    await _pump(
      tester,
      repo: FakeGoogleSignInRepository(),
      ticket: _ticket,
      qrScanSupported: false,
    );
    await _tap(tester, _accept);
    await _tap(tester, _qr);
    expect(find.text('สแกนบัตร QR ได้ในแอป Android'), findsOneWidget);
    await _tap(tester, find.text('ตกลง'));
  });

  group('linkGoogleWithQr', () {
    Future<(ProviderContainer, FakeGoogleSignInRepository, WidgetRef)> setUpRef(
      WidgetTester tester, {
      String? ticket,
      Object? qrError,
    }) async {
      final repo = FakeGoogleSignInRepository()..qrError = qrError;
      late WidgetRef widgetRef;
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
            authRepositoryProvider.overrideWithValue(FakeMeAuth(studentUser)),
            googleSignInRepositoryProvider.overrideWithValue(repo),
            googleLinkTicketProvider.overrideWith(
              () => GoogleLinkTicketNotifier()..initial = ticket,
            ),
          ],
          child: Consumer(
            builder: (context, ref, _) {
              widgetRef = ref;
              return const SizedBox();
            },
          ),
        ),
      );
      final container = ProviderScope.containerOf(
        tester.element(find.byType(SizedBox)),
      );
      return (container, repo, widgetRef);
    }

    testWidgets('sends the card token without its prefix', (tester) async {
      final (container, repo, ref) = await setUpRef(tester, ticket: _ticket);
      await linkGoogleWithQr(ref, '${studentCardQrPrefix}card-token ');
      expect(repo.qrLinks.single, (_ticket, 'card-token'));
      expect(container.read(sessionProvider), isA<SignedIn>());
      expect(container.read(googleLinkTicketProvider), isNull);
    });

    testWidgets('without a ticket nothing is sent', (tester) async {
      final (_, repo, ref) = await setUpRef(tester);
      await expectLater(
        linkGoogleWithQr(ref, 'EVL1.x'),
        throwsA(isA<GoogleLinkTicketMissing>()),
      );
      expect(repo.qrLinks, isEmpty);
      expect(
        googleFirstLinkErrorMessage(const GoogleLinkTicketMissing()),
        contains('หมดอายุ'),
      );
    });

    testWidgets('a refused card reads like the card login', (tester) async {
      final (container, _, ref) = await setUpRef(
        tester,
        ticket: _ticket,
        qrError: apiError(422, 'qr_invalid'),
      );
      Object? caught;
      try {
        await linkGoogleWithQr(ref, 'EVL1.x');
      } catch (e) {
        caught = e;
      }
      expect(
        googleFirstLinkErrorMessage(caught!, qr: true),
        contains('บัตรนี้ใช้ไม่ได้แล้ว'),
      );
      expect(cardErrorMessage(caught), contains('บัตรนี้ใช้ไม่ได้แล้ว'));
      expect(container.read(googleLinkTicketProvider), _ticket);
    });
  });

  testWidgets('the QR scanner can sign in through another route', (
    tester,
  ) async {
    // Only builds: the camera never starts in a widget test.
    String? scanned;
    await tester.pumpWidget(
      ProviderScope(
        child: MaterialApp(
          home: StudentQrScanScreen(
            signIn: (ref, payload) async => scanned = payload,
            errorMessage: (_) => 'x',
          ),
        ),
      ),
    );
    expect(find.text('สแกนบัตร QR'), findsOneWidget);
    expect(scanned, isNull);
    await tester.pumpWidget(const SizedBox());
  });

  test('an unknown error keeps the API message', () {
    expect(
      googleFirstLinkErrorMessage(
        apiError(500, 'server_error', message: 'พัง'),
      ),
      'พัง',
    );
    expect(apiErrorCode(apiError(500, 'x')), 'x');
  });
}
