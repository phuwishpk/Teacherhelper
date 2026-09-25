import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/features/auth/register_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

class _FakeAuth extends Fake implements AuthRepository {
  final calls = <Map<String, String>>[];

  @override
  Future<void> register({
    required String schoolCode,
    required String name,
    required String email,
    required String password,
  }) async {
    calls.add({
      'school_code': schoolCode,
      'name': name,
      'email': email,
      'password': password,
    });
  }
}

void main() {
  testWidgets('validates locally before calling the API', (tester) async {
    final auth = _FakeAuth();
    await pumpScreen(
      tester,
      const RegisterScreen(),
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
    await tester.pump();
    expect(find.text('กรอกรหัสโรงเรียน'), findsOneWidget);
    expect(find.text('กรอกชื่อ'), findsOneWidget);
    expect(find.text('กรอกอีเมลให้ถูกต้อง'), findsOneWidget);
    expect(find.text('รหัสผ่านต้องยาวอย่างน้อย 8 ตัว'), findsOneWidget);
    expect(auth.calls, isEmpty);
  });

  testWidgets(
    'registers with DESIGN §9.1 fields and ends with the pending-approval message',
    (tester) async {
      final auth = _FakeAuth();
      await pumpScreen(
        tester,
        const RegisterScreen(),
        overrides: [
          authRepositoryProvider.overrideWithValue(auth),
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
        extraRoutes: [
          GoRoute(path: '/login', builder: (_, _) => const Text('login-stub')),
        ],
      );

      await tester.enterText(
        find.widgetWithText(TextFormField, 'รหัสโรงเรียน (school_code)'),
        'SCH001',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'ชื่อ-นามสกุล'),
        'ครูสมศรี ใจดี',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'อีเมล'),
        'somsri@example.com',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'รหัสผ่าน (อย่างน้อย 8 ตัว)'),
        'secret-pass-1',
      );
      await tester.tap(find.widgetWithText(FilledButton, 'สมัครใช้งาน'));
      await tester.pumpAndSettle();

      expect(auth.calls, [
        {
          'school_code': 'SCH001',
          'name': 'ครูสมศรี ใจดี',
          'email': 'somsri@example.com',
          'password': 'secret-pass-1',
        },
      ]);
      expect(find.text('สมัครสำเร็จ'), findsOneWidget);
      expect(
        find.textContaining('รอผู้ดูแลโรงเรียนอนุมัติ'),
        findsOneWidget,
        reason: 'new accounts are pending until a school admin approves',
      );

      await tester.tap(find.text('รับทราบ'));
      await tester.pumpAndSettle();
      expect(find.text('login-stub'), findsOneWidget);
    },
  );
}
