import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/api/api_config.dart';
import 'core/push/push_messaging.dart';
import 'features/upload_queue/upload_worker.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  if (!apiBaseUrlConfigured) {
    // Release build without --dart-define=API_BASE_URL (see app/README.md).
    runApp(const MisconfiguredApp());
    return;
  }
  // FCM only when this build has a Firebase config (DESIGN §9.9).
  final push = await initPushMessaging();
  final container = ProviderContainer(
    overrides: [pushMessagingProvider.overrideWithValue(push)],
  );
  // Registers the background upload isolate (no-op off Android).
  await container.read(uploadSchedulerProvider).initialize();
  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const EduVisionApp(),
    ),
  );
}

/// Shown instead of the app when the build has no API base URL, so the
/// problem is visible to whoever installed the APK instead of surfacing as a
/// generic connection error on the login screen.
class MisconfiguredApp extends StatelessWidget {
  const MisconfiguredApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'EduVision',
      home: Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.build_circle_outlined, size: 56),
                const SizedBox(height: 16),
                Text(
                  'แอปนี้ถูก build โดยไม่ได้ระบุที่อยู่เซิร์ฟเวอร์ (API_BASE_URL)',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                const Text(
                  'ให้ผู้ดูแล build ใหม่ด้วย flutter build apk --release '
                  '--dart-define=API_BASE_URL=https://<โดเมนของโรงเรียน>',
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
