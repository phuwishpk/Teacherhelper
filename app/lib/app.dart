import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/auth/session.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';

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
