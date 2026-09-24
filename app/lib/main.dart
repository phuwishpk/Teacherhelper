import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'features/upload_queue/upload_worker.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final container = ProviderContainer();
  // Registers the background upload isolate (no-op off Android).
  await container.read(uploadSchedulerProvider).initialize();
  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const EduVisionApp(),
    ),
  );
}
