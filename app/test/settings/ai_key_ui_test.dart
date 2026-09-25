import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/home/dashboard_page.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:eduvision/features/settings/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';

class _NoClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [];
}

class _NoAssignments extends Fake implements AssignmentsRepository {
  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];
}

const _teacher = User(id: 1, name: 'สมศรี', role: 'teacher');

Future<void> _pumpDashboard(WidgetTester tester, FakeAiKeyRepository keys) {
  final router = GoRouter(
    routes: [
      GoRoute(
        path: '/',
        builder: (_, _) => Scaffold(
          body: DashboardPage(user: _teacher, onNavigate: (_) {}),
        ),
      ),
      GoRoute(
        path: '/settings',
        builder: (_, _) => const Scaffold(body: Text('settings-page')),
      ),
    ],
  );
  return tester.pumpWidget(
    ProviderScope(
      overrides: [
        aiKeyRepositoryProvider.overrideWithValue(keys),
        classroomsRepositoryProvider.overrideWithValue(_NoClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(_NoAssignments()),
      ],
      child: MaterialApp.router(routerConfig: router),
    ),
  );
}

void main() {
  group('home key card (DESIGN §10.1)', () {
    testWidgets('no key: "ยังไม่ได้ใส่" and a button to enter one', (
      tester,
    ) async {
      await _pumpDashboard(
        tester,
        FakeAiKeyRepository(const AiKeyStatus(configured: false)),
      );
      await tester.pumpAndSettle();

      final card = find.byKey(const ValueKey('ai_key_card'));
      expect(card, findsOneWidget);
      expect(
        find.descendant(of: card, matching: find.text('ยังไม่ได้ใส่')),
        findsOneWidget,
      );
      expect(find.textContaining('ใส่ key ก่อนสแกน'), findsOneWidget);

      await tester.tap(find.widgetWithText(FilledButton, 'ใส่ key'));
      await tester.pumpAndSettle();
      expect(find.text('settings-page'), findsOneWidget);
    });

    testWidgets('key set: masked last 4 and "แก้ไข"', (tester) async {
      await _pumpDashboard(
        tester,
        FakeAiKeyRepository(
          const AiKeyStatus(configured: true, keyLast4: '1234'),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('ใส่แล้ว (••••1234)'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'แก้ไข'), findsOneWidget);
      expect(find.textContaining('ใส่ key ก่อนสแกน'), findsNothing);
    });

    testWidgets('no key but a server key: says the server key is used', (
      tester,
    ) async {
      await _pumpDashboard(
        tester,
        FakeAiKeyRepository(
          const AiKeyStatus(configured: false, serverKeyAvailable: true),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('key กลางของเซิร์ฟเวอร์'), findsOneWidget);
      expect(find.textContaining('ใส่ key ก่อนสแกน'), findsNothing);
    });
  });

  group('settings key form', () {
    testWidgets('test-and-save sends the key once and clears the field', (
      tester,
    ) async {
      final keys = FakeAiKeyRepository(const AiKeyStatus(configured: false));
      await pumpScreen(
        tester,
        const SettingsScreen(),
        overrides: [aiKeyRepositoryProvider.overrideWithValue(keys)],
      );
      expect(find.text('ยังไม่ได้ใส่'), findsOneWidget);
      expect(
        find.textContaining('เก็บแบบเข้ารหัสบนเซิร์ฟเวอร์'),
        findsOneWidget,
      );

      final field = find.byKey(const ValueKey('ai_key_field'));
      expect(
        tester.widget<TextField>(field).obscureText,
        isTrue,
        reason: 'the key is typed hidden',
      );
      // Empty input is rejected without calling the API.
      await tester.tap(find.text('ทดสอบและบันทึก'));
      await tester.pumpAndSettle();
      expect(find.text('วาง Gemini API key ก่อน'), findsOneWidget);
      expect(keys.saved, isEmpty);

      await tester.enterText(field, '  AIzaSyTEST-1234  ');
      await tester.tap(find.text('ทดสอบและบันทึก'));
      await tester.pumpAndSettle();

      expect(keys.saved, ['AIzaSyTEST-1234']);
      expect(tester.widget<TextField>(field).controller!.text, isEmpty);
      expect(find.text('ใส่แล้ว'), findsOneWidget);
      expect(find.text('key ที่ใช้อยู่: ••••1234'), findsOneWidget);
      expect(find.textContaining('ทดสอบใช้งานได้ล่าสุด'), findsOneWidget);
    });

    testWidgets('ai_key_invalid shows the reason in Thai and keeps no key', (
      tester,
    ) async {
      final keys = FakeAiKeyRepository(const AiKeyStatus(configured: false))
        ..saveError = apiError(422, {
          'message': 'Gemini API key ใช้ไม่ได้',
          'errors': {
            'gemini_api_key': ['Gemini ไม่ยอมรับ key นี้ ตรวจว่าคัดลอกมาครบ'],
          },
          'code': 'ai_key_invalid',
        });
      await pumpScreen(
        tester,
        const SettingsScreen(),
        overrides: [aiKeyRepositoryProvider.overrideWithValue(keys)],
      );

      await tester.enterText(
        find.byKey(const ValueKey('ai_key_field')),
        'AIzaWRONG',
      );
      await tester.tap(find.text('ทดสอบและบันทึก'));
      await tester.pumpAndSettle();

      expect(
        find.text('Gemini ไม่ยอมรับ key นี้ ตรวจว่าคัดลอกมาครบ'),
        findsOneWidget,
      );
      expect(find.text('ยังไม่ได้ใส่'), findsOneWidget);
    });

    testWidgets('delete asks first, then removes the key', (tester) async {
      final keys = FakeAiKeyRepository(
        AiKeyStatus(
          configured: true,
          keyLast4: '9876',
          lastVerifiedAt: DateTime.utc(2026, 9, 30),
          serverKeyAvailable: true,
        ),
      );
      await pumpScreen(
        tester,
        const SettingsScreen(),
        overrides: [aiKeyRepositoryProvider.overrideWithValue(keys)],
      );
      expect(find.text('key ที่ใช้อยู่: ••••9876'), findsOneWidget);

      await tester.tap(find.widgetWithText(OutlinedButton, 'ลบ key'));
      await tester.pumpAndSettle();
      expect(find.text('ลบ Gemini API key?'), findsOneWidget);
      expect(find.textContaining('key กลางของเซิร์ฟเวอร์'), findsWidgets);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(keys.deletes, 0);

      await tester.tap(find.widgetWithText(OutlinedButton, 'ลบ key'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'ลบ key'));
      await tester.pumpAndSettle();

      expect(keys.deletes, 1);
      expect(find.text('ยังไม่ได้ใส่'), findsOneWidget);
      expect(find.widgetWithText(OutlinedButton, 'ลบ key'), findsNothing);
    });
  });
}
