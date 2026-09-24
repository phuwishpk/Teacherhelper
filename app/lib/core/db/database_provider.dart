import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app_database.dart';

/// Name of the on-device SQLite file (documents directory).
const appDatabaseName = 'eduvision';

/// Opens the shared on-device database. `shareAcrossIsolates` lets the
/// workmanager upload isolate use the same connection as the UI isolate.
QueryExecutor openAppDatabase() => driftDatabase(
  name: appDatabaseName,
  native: const DriftNativeOptions(shareAcrossIsolates: true),
);

/// Tests override this with `AppDatabase(NativeDatabase.memory())`.
final appDatabaseProvider = Provider<AppDatabase>((ref) {
  final db = AppDatabase(openAppDatabase());
  ref.onDispose(db.close);
  return db;
});
