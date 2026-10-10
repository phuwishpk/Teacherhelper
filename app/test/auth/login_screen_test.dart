import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/auth/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

Finder get _studentTab => find.text('นักเรียน');
Finder get _teacherTab => find.text('ครู / ผู้ดูแลระบบ');
Finder get _emailField => find.widgetWithText(TextFormField, 'อีเมล');
Finder get _pinField => find.byKey(const ValueKey('student_password'));

Future<InMemoryTokenStorage> _pump(
  WidgetTester tester, {
  LoginScreen screen = const LoginScreen(),
  InMemoryTokenStorage? storage,
  Size size = const Size(800, 1200),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final store = storage ?? InMemoryTokenStorage();
  await pumpScreen(
    tester,
    screen,
    overrides: [tokenStorageProvider.overrideWithValue(store)],
    extraRoutes: [
      GoRoute(
        path: AppRoutes.studentQr,
        builder: (_, _) => const Scaffold(body: Text('qr-scanner')),
      ),
      GoRoute(
        path: AppRoutes.register,
        builder: (_, _) => const Scaffold(body: Text('register-page')),
      ),
    ],
  );
  return store;
}

void main() {
  testWidgets('the teacher tab validates an empty form without the API', (
    tester,
  ) async {
    await _pump(tester);

    expect(_emailField, findsOneWidget);
    expect(find.text('ยังไม่มีบัญชี? สมัครใช้งาน'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'เข้าสู่ระบบ'));
    await tester.pump();

    expect(find.text('กรอกอีเมลให้ถูกต้อง'), findsOneWidget);
    expect(find.text('กรอกรหัสผ่าน'), findsOneWidget);
  });

  testWidgets('the tabs switch between the two forms and are remembered', (
    tester,
  ) async {
    final storage = await _pump(tester);
    expect(_emailField, findsOneWidget);
    expect(_pinField, findsNothing);

    await tester.tap(_studentTab);
    await tester.pumpAndSettle();
    expect(_emailField, findsNothing);
    expect(_pinField, findsOneWidget);
    expect(find.text('สแกนบัตร QR'), findsOneWidget);
    // The register link belongs to the teacher tab only.
    expect(find.text('ยังไม่มีบัญชี? สมัครใช้งาน'), findsNothing);
    expect(storage.loginTab, 'student');

    await tester.tap(_teacherTab);
    await tester.pumpAndSettle();
    expect(_emailField, findsOneWidget);
    expect(storage.loginTab, 'teacher');
  });

  testWidgets('opens on the tab used last on this device', (tester) async {
    await _pump(tester, storage: InMemoryTokenStorage(loginTab: 'student'));

    expect(_pinField, findsOneWidget);
    expect(_emailField, findsNothing);
  });

  testWidgets('a ?tab= from the route wins over the remembered tab', (
    tester,
  ) async {
    await _pump(
      tester,
      screen: const LoginScreen(initialTab: LoginTab.teacher),
      storage: InMemoryTokenStorage(loginTab: 'student'),
    );

    expect(_emailField, findsOneWidget);
    expect(_pinField, findsNothing);
  });

  testWidgets('the register link opens the register page', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('ยังไม่มีบัญชี? สมัครใช้งาน'));
    await tester.pumpAndSettle();
    expect(find.text('register-page'), findsOneWidget);
  });

  testWidgets('"สแกนบัตร QR" opens the card scanner in the app', (
    tester,
  ) async {
    await _pump(
      tester,
      screen: const LoginScreen(initialTab: LoginTab.student),
    );

    await tester.tap(find.text('สแกนบัตร QR'));
    await tester.pumpAndSettle();
    expect(find.text('qr-scanner'), findsOneWidget);
  });

  testWidgets('on the web the QR button explains it needs the Android app', (
    tester,
  ) async {
    await _pump(
      tester,
      screen: const LoginScreen(
        initialTab: LoginTab.student,
        qrScanSupported: false,
      ),
    );
    expect(find.text('ใช้ได้ในแอป Android'), findsOneWidget);

    await tester.tap(find.text('สแกนบัตร QR'));
    await tester.pumpAndSettle();
    expect(find.text('สแกนบัตร QR ได้ในแอป Android'), findsOneWidget);
    expect(find.text('qr-scanner'), findsNothing);

    await tester.tap(find.text('ตกลง'));
    await tester.pumpAndSettle();
    expect(_pinField, findsOneWidget);
  });

  for (final tab in LoginTab.values) {
    testWidgets('fits a 360 px phone on the ${tab.name} tab', (tester) async {
      await _pump(
        tester,
        screen: LoginScreen(initialTab: tab),
        size: const Size(360, 640),
      );

      expect(tester.takeException(), isNull);
      expect(_teacherTab, findsOneWidget);
      expect(_studentTab, findsOneWidget);
      final button = find.widgetWithText(FilledButton, 'เข้าสู่ระบบ');
      await tester.ensureVisible(button);
      await tester.pumpAndSettle();
      expect(tester.getSize(button).width, lessThanOrEqualTo(360 - 32));
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('stays a readable column on a desktop window', (tester) async {
    await _pump(tester, size: const Size(1440, 900));

    final form = tester.getRect(_emailField);
    expect(form.width, lessThanOrEqualTo(440));
    // Centered.
    expect((form.center.dx - 720).abs(), lessThan(1));
  });
}
