import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/features/auth/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('login screen validates empty form without calling the API', (
    tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
        ],
        child: const MaterialApp(home: LoginScreen()),
      ),
    );

    expect(find.text('เข้าสู่ระบบสำหรับครู'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'เข้าสู่ระบบ'));
    await tester.pump();

    expect(find.text('กรอกอีเมลให้ถูกต้อง'), findsOneWidget);
    expect(find.text('กรอกรหัสผ่าน'), findsOneWidget);
  });
}
