import 'dart:async';

import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/app_settings.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/onboarding/onboarding_screen.dart';
import 'package:eduvision/features/onboarding/onboarding_store.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

/// Firestore keeps a write waiting while the device is offline.
class _StuckSettings extends AppSettingsStore {
  _StuckSettings(TokenStorage storage) : super(storage: storage);

  @override
  Future<void> writeOnboardingDone({
    required DateTime at,
    required String device,
  }) => Completer<void>().future;
}

void main() {
  const teacher = User(id: 1, name: 'ครูสมศรี', role: 'teacher');
  const student = User(id: 2, name: 'ด.ช.มานะ', role: 'student');

  Future<void> pumpOnboarding(
    WidgetTester tester, {
    User user = teacher,
    TokenStorage? storage,
    AppSettingsStore? appSettings,
    Size size = const Size(800, 1200),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final store = storage ?? InMemoryTokenStorage();
    await pumpScreen(
      tester,
      const OnboardingScreen(),
      overrides: [
        currentUserProvider.overrideWithValue(user),
        tokenStorageProvider.overrideWithValue(store),
        onboardingStoreProvider.overrideWithValue(
          OnboardingStore(
            userId: user.id,
            storage: store,
            appSettings: appSettings,
            device: 'web',
          ),
        ),
      ],
      extraRoutes: [
        GoRoute(
          path: AppRoutes.home,
          builder: (_, _) => const Scaffold(body: Text('home-screen')),
        ),
        GoRoute(
          path: AppRoutes.student,
          builder: (_, _) => const Scaffold(body: Text('student-screen')),
        ),
      ],
    );
  }

  testWidgets('ครูเห็นสไลด์แรกของครู นักเรียนเห็นสไลด์แรกของนักเรียน', (
    tester,
  ) async {
    await pumpOnboarding(tester, user: teacher);
    expect(find.text('สร้างการบ้านและพิมพ์ใบงาน'), findsOneWidget);
    expect(find.text('เข้าห้องเรียนง่ายๆ'), findsNothing);

    await unmountScreen(tester);

    await pumpOnboarding(tester, user: student);
    expect(find.text('เข้าห้องเรียนง่ายๆ'), findsOneWidget);
    expect(find.text('สร้างการบ้านและพิมพ์ใบงาน'), findsNothing);
  });

  testWidgets('กด "ถัดไป" 3 ครั้งแล้วปุ่มเปลี่ยนเป็น "เริ่มใช้งาน"', (
    tester,
  ) async {
    await pumpOnboarding(tester, user: teacher);

    expect(find.text('ถัดไป'), findsOneWidget);
    expect(find.text('เริ่มใช้งาน'), findsNothing);

    // Slide 1 -> 2
    await tester.tap(find.byKey(const Key('onboarding_next')));
    await tester.pumpAndSettle();
    expect(find.text('ถัดไป'), findsOneWidget);

    // Slide 2 -> 3
    await tester.tap(find.byKey(const Key('onboarding_next')));
    await tester.pumpAndSettle();
    expect(find.text('ถัดไป'), findsOneWidget);

    // Slide 3 -> 4
    await tester.tap(find.byKey(const Key('onboarding_next')));
    await tester.pumpAndSettle();

    expect(find.text('เริ่มใช้งาน'), findsOneWidget);
    expect(find.text('ถัดไป'), findsNothing);
  });

  testWidgets('กด "ข้าม" แล้ว markDone ถูกเรียก', (tester) async {
    final storage = InMemoryTokenStorage();
    await pumpOnboarding(tester, user: teacher, storage: storage);

    expect(await storage.readOnboardingDone(teacher.id), isFalse);

    await tester.tap(find.byKey(const Key('onboarding_skip')));
    await tester.pumpAndSettle();

    expect(await storage.readOnboardingDone(teacher.id), isTrue);
  });

  testWidgets('ข้ามได้แม้ Firestore ไม่ตอบ', (tester) async {
    final storage = InMemoryTokenStorage();
    await pumpOnboarding(
      tester,
      user: teacher,
      storage: storage,
      appSettings: _StuckSettings(storage),
    );

    await tester.tap(find.byKey(const Key('onboarding_skip')));
    await tester.pump(const Duration(seconds: 3));
    await tester.pumpAndSettle();

    expect(find.byType(OnboardingScreen), findsNothing);
    expect(await storage.readOnboardingDone(teacher.id), isTrue);
  });

  testWidgets('ที่ขนาด 390×844, 820×1180 และ 1440×900 ไม่มี overflow', (
    tester,
  ) async {
    const sizes = [Size(390, 844), Size(820, 1180), Size(1440, 900)];

    for (final size in sizes) {
      await pumpOnboarding(tester, user: teacher, size: size);

      for (var i = 0; i < 4; i++) {
        expect(tester.takeException(), isNull);
        if (i < 3) {
          await tester.tap(find.byKey(const Key('onboarding_next')));
          await tester.pumpAndSettle();
        }
      }

      await unmountScreen(tester);
    }
  });
}
