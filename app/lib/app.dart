import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/auth/session.dart';
import 'core/db/app_database.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'features/upload_queue/scan_queue_repository.dart';
import 'features/upload_queue/upload_worker.dart';

class EduVisionApp extends ConsumerStatefulWidget {
  const EduVisionApp({super.key});

  @override
  ConsumerState<EduVisionApp> createState() => _EduVisionAppState();
}

class _EduVisionAppState extends ConsumerState<EduVisionApp> {
  @override
  void initState() {
    super.initState();
    // Read the stored token once; the router shows /splash until this settles.
    Future.microtask(() => ref.read(sessionProvider.notifier).restore());
    // A teacher who was offline may have scans waiting: make sure WorkManager
    // has an upload task queued (it runs once the network is back, §6.4).
    Future.microtask(_resumePendingUploads);
  }

  Future<void> _resumePendingUploads() async {
    try {
      final pending = await ref
          .read(scanQueueRepositoryProvider)
          .countByState(ScanState.pending);
      if (pending > 0) {
        await ref.read(uploadSchedulerProvider).requestUpload();
      }
    } catch (_) {
      // No local database (e.g. web preview) or no WorkManager: nothing to do.
    }
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'EduVision',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      routerConfig: ref.watch(routerProvider),
    );
  }
}
