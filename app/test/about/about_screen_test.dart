import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/about/about_screen.dart';
import 'package:eduvision/features/about/server_health.dart';
import 'package:eduvision/features/onboarding/onboarding_store.dart';
import 'package:eduvision/features/settings/settings_screen.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

class _FakeHealthRepo implements HealthRepository {
  _FakeHealthRepo(this.response);

  ServerHealth response;
  int callCount = 0;

  @override
  Future<ServerHealth> fetch() async {
    callCount++;
    return response;
  }
}

void main() {
  const teacher = User(id: 1, name: 'ครูสมศรี', role: 'teacher');

  testWidgets('override healthRepositoryProvider ตรวจข้อความของ 3 สถานะ', (
    tester,
  ) async {
    // 1. ok
    final repoOk = _FakeHealthRepo(
      ServerHealth(
        state: ServerState.ok,
        queueLastRunAt: DateTime.utc(2026, 11, 2, 10, 0),
      ),
    );
    await pumpScreen(
      tester,
      const AboutScreen(),
      overrides: [healthRepositoryProvider.overrideWithValue(repoOk)],
    );
    expect(find.text('เซิร์ฟเวอร์ทำงานปกติ'), findsOneWidget);
    expect(find.textContaining('คิวงานรันล่าสุด'), findsOneWidget);

    // 2. degraded
    final repoDegraded = _FakeHealthRepo(
      const ServerHealth(state: ServerState.degraded),
    );
    await pumpScreen(
      tester,
      const AboutScreen(),
      overrides: [healthRepositoryProvider.overrideWithValue(repoDegraded)],
    );
    expect(
      find.text('เซิร์ฟเวอร์มีปัญหาบางส่วน (คิวงานไม่ได้รันตามปกติ)'),
      findsOneWidget,
    );

    // 3. unreachable
    final repoUnreachable = _FakeHealthRepo(
      const ServerHealth(state: ServerState.unreachable),
    );
    await pumpScreen(
      tester,
      const AboutScreen(),
      overrides: [healthRepositoryProvider.overrideWithValue(repoUnreachable)],
    );
    expect(find.text('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'), findsOneWidget);
  });

  testWidgets('กด "ตรวจอีกครั้ง" แล้ว repository ถูกเรียกซ้ำ', (tester) async {
    final repo = _FakeHealthRepo(const ServerHealth(state: ServerState.ok));
    await pumpScreen(
      tester,
      const AboutScreen(),
      overrides: [healthRepositoryProvider.overrideWithValue(repo)],
    );
    expect(repo.callCount, 1);

    final retryFinder = find.byKey(const ValueKey('about_health_retry'));
    expect(retryFinder, findsOneWidget);
    await tester.tap(retryFinder);
    await tester.pumpAndSettle();

    expect(repo.callCount, 2);
  });

  testWidgets(
    'override onboardingStoreProvider ด้วย store จาก Firestore แสดงบรรทัดเวลา',
    (tester) async {
      final db = FakeFirebaseFirestore();
      final at = DateTime.utc(2026, 10, 15, 8, 30);
      await db.doc('users/uid_1/settings/app').set({
        'onboarding_done_at': Timestamp.fromDate(at),
        'device': 'web',
      });
      final storage = InMemoryTokenStorage();
      final store = OnboardingStore(
        userId: 1,
        storage: storage,
        db: db,
        uid: 'uid_1',
        device: 'web',
      );

      await pumpScreen(
        tester,
        const AboutScreen(),
        overrides: [
          currentUserProvider.overrideWithValue(teacher),
          onboardingStoreProvider.overrideWithValue(store),
          healthRepositoryProvider.overrideWithValue(
            _FakeHealthRepo(const ServerHealth(state: ServerState.ok)),
          ),
        ],
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('คุณดูวิธีใช้งานครั้งแรกเมื่อ'),
        findsOneWidget,
      );
      expect(find.textContaining('เว็บเบราว์เซอร์'), findsOneWidget);
    },
  );

  for (final size in const [Size(390, 844), Size(820, 1180), Size(1440, 900)]) {
    testWidgets(
      'ที่ขนาด ${size.width.toInt()}×${size.height.toInt()} ไม่มี overflow',
      (tester) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);

        await pumpScreen(
          tester,
          const AboutScreen(),
          overrides: [
            healthRepositoryProvider.overrideWithValue(
              _FakeHealthRepo(const ServerHealth(state: ServerState.ok)),
            ),
          ],
        );
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
      },
    );
  }

  testWidgets('รายวิชาตรงกับวิชาที่ส่งโครงงานจริง', (tester) async {
    await pumpScreen(
      tester,
      const AboutScreen(),
      overrides: [
        healthRepositoryProvider.overrideWithValue(
          _FakeHealthRepo(const ServerHealth(state: ServerState.ok)),
        ),
      ],
    );

    await tester.scrollUntilVisible(
      find.textContaining('KMITL'),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(
      find.textContaining('03376133 Mobile Devices Software Development'),
      findsOneWidget,
    );
    expect(find.textContaining('03376132 AI in Education'), findsOneWidget);
    expect(find.textContaining('โครงงานวิศวกรรมคอมพิวเตอร์'), findsNothing);
    expect(find.textContaining('คณะวิศวกรรมศาสตร์'), findsNothing);
  });

  testWidgets('เมนู settings_about เปิดหน้าเกี่ยวกับได้', (tester) async {
    final storage = InMemoryTokenStorage();
    await pumpScreen(
      tester,
      const SettingsScreen(),
      overrides: [
        currentUserProvider.overrideWithValue(teacher),
        tokenStorageProvider.overrideWithValue(storage),
        healthRepositoryProvider.overrideWithValue(
          _FakeHealthRepo(const ServerHealth(state: ServerState.ok)),
        ),
      ],
      extraRoutes: [
        GoRoute(path: AppRoutes.about, builder: (_, _) => const AboutScreen()),
      ],
    );

    final aboutFinder = find.byKey(const ValueKey('settings_about'));
    await tester.ensureVisible(aboutFinder);
    await tester.pumpAndSettle();
    expect(aboutFinder, findsOneWidget);

    await tester.tap(aboutFinder);
    await tester.pumpAndSettle();

    expect(find.byType(AboutScreen), findsOneWidget);
  });
}
