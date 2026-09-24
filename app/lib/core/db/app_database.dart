import 'package:drift/drift.dart';

part 'app_database.g.dart';

/// Upload state of a queued scan (DESIGN §6.4 scan_queue.state).
/// Stored with the snake_case names from the design so a DB dump reads the
/// same as the document.
enum ScanState {
  needsLayout('needs_layout'),
  pending('pending'),
  uploading('uploading'),
  done('done'),
  conflict('conflict'),
  failed('failed');

  const ScanState(this.dbValue);

  final String dbValue;

  static ScanState fromDb(String value) =>
      values.firstWhere((s) => s.dbValue == value);
}

class ScanStateConverter extends TypeConverter<ScanState, String> {
  const ScanStateConverter();

  @override
  ScanState fromSql(String fromDb) => ScanState.fromDb(fromDb);

  @override
  String toSql(ScanState value) => value.dbValue;
}

/// One page of a layout version (DESIGN §5.3), cached for offline cropping.
class CachedLayouts extends Table {
  IntColumn get assignmentId => integer()();
  IntColumn get version => integer()();
  IntColumn get page => integer()();
  TextColumn get json => text()();
  DateTimeColumn get cachedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {assignmentId, version, page};
}

/// Roster rows so the scan screen can show a name next to a QR (§5.4).
class CachedRosters extends Table {
  IntColumn get classroomId => integer()();
  IntColumn get studentId => integer()();
  IntColumn get studentNumber => integer()();
  TextColumn get name => text()();

  @override
  Set<Column<Object>> get primaryKey => {classroomId, studentId};
}

/// Scans waiting to be uploaded as `POST /scans` multipart (§9.4).
class ScanQueue extends Table {
  /// UUID generated on the phone; the server de-duplicates on it.
  TextColumn get clientScanId => text()();
  TextColumn get state => text().map(const ScanStateConverter())();

  /// The `meta` JSON object exactly as it will be sent (§9.4).
  TextColumn get metaJson => text()();

  /// JSON object mapping multipart field name -> local file path,
  /// e.g. {"page": ".../page.webp", "crop_q501": ".../q501.webp"}.
  TextColumn get filesJson => text()();
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  TextColumn get lastError => text().nullable()();

  /// `scan_id` returned by the server; needed for confirm-replace.
  IntColumn get serverScanId => integer().nullable()();

  /// Earliest time of the next upload attempt (exponential backoff).
  DateTimeColumn get nextAttemptAt => dateTime().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {clientScanId};
}

/// Downloaded TFLite models (§9.8); one row per model name.
class ModelCache extends Table {
  TextColumn get name => text()();
  IntColumn get version => integer()();
  TextColumn get sha256 => text()();
  TextColumn get path => text()();
  DateTimeColumn get downloadedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {name};
}

@DriftDatabase(tables: [CachedLayouts, CachedRosters, ScanQueue, ModelCache])
class AppDatabase extends _$AppDatabase {
  AppDatabase(super.executor);

  @override
  int get schemaVersion => 1;

  /// ISO-8601 text keeps the UTC flag of timestamps across a round trip.
  @override
  DriftDatabaseOptions get options =>
      const DriftDatabaseOptions(storeDateTimeAsText: true);
}
