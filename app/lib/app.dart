import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/auth/session.dart';
import 'core/push/push_coordinator.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'features/upload_queue/scan_queue_repository.dart';
import 'features/upload_queue/upload_worker.dart';

class KrucheckApp extends ConsumerStatefulWidget {
  const KrucheckApp({super.key});

  @override
  ConsumerState<KrucheckApp> createState() => _KrucheckAppState();
}

class _KrucheckAppState extends ConsumerState<KrucheckApp>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Read the stored token once; the router shows /splash until this settles.
    Future.microtask(() => ref.read(sessionProvider.notifier).restore());
    // FCM token registration and notification taps (no-op without Firebase).
    Future.microtask(() => ref.read(pushCoordinatorProvider));
    // A teacher who was offline may have scans waiting: make sure WorkManager
    // has an upload task queued (it runs once the network is back, §6.4).
    Future.microtask(_resumePendingUploads);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      // After an offline start, pick up the real /me once the app comes back
      // to the foreground (best effort; a failure keeps the cached user).
      ref.read(sessionProvider.notifier).refreshUser();
      _resumePendingUploads();
    }
  }

  Future<void> _resumePendingUploads() async {
    try {
      // Pending scans, and uploads interrupted when the app was killed.
      final awaiting = await ref
          .read(scanQueueRepositoryProvider)
          .countAwaitingUpload();
      if (awaiting > 0) {
        await ref.read(uploadSchedulerProvider).requestUpload();
      }
    } catch (_) {
      // No local database (e.g. web preview) or no WorkManager: nothing to do.
    }
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'Krucheck',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      routerConfig: ref.watch(routerProvider),
      scaffoldMessengerKey: ref.watch(rootMessengerKeyProvider),
    );
  }
}
