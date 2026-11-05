import 'package:drift/drift.dart';

part 'app_database.g.dart';

/// Longest side of a note's thumbnail (DESIGN §40).
const kNoteThumbMaxSide = 360;

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

/// What a queued scan is (DESIGN §22.14 `scan_queue.kind`): a worksheet
/// page (`POST /scans`) or an exam answer-sheet page (`POST /exam-sheets`).
enum ScanKind {
  worksheet('worksheet'),
  examSheet('exam_sheet');

  const ScanKind(this.dbValue);

  final String dbValue;

  static ScanKind fromDb(String value) =>
      values.firstWhere((k) => k.dbValue == value, orElse: () => worksheet);
}

class ScanKindConverter extends TypeConverter<ScanKind, String> {
  const ScanKindConverter();

  @override
  ScanKind fromSql(String fromDb) => ScanKind.fromDb(fromDb);

  @override
  String toSql(ScanKind value) => value.dbValue;
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

  /// Worksheet page or exam answer-sheet page (added in schema 2).
  TextColumn get kind => text()
      .map(const ScanKindConverter())
      .withDefault(const Constant('worksheet'))();

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

/// The scan kit of an exam (DESIGN §22.9, §22.14 `cached_exam_kits`):
/// layouts, the key of every version and the roster, so answer sheets can be
/// read and scored offline. Deleted after 30 days.
class CachedExamKits extends Table {
  IntColumn get assignmentId => integer()();
  TextColumn get kitHash => text()();

  /// The `data` object of `GET /exams/{id}/scan-kit` as JSON text.
  TextColumn get json => text()();
  DateTimeColumn get fetchedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {assignmentId};
}

/// Downloaded TFLite models (§9.8); one row per model name.
class ModelCache extends Table {
  TextColumn get name => text()();

  /// Semver string as in `model_versions.version` VARCHAR(20) (§8.6),
  /// e.g. "0.1.0" for ml/models/digit_crnn/0.1.0.
  TextColumn get version => text()();
  TextColumn get sha256 => text()();
  TextColumn get path => text()();
  DateTimeColumn get downloadedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {name};
}

/// "บันทึกครู" (DESIGN §40): a teacher's photo and short note, kept here
/// first (offline, full photo) and copied to Firestore `users/{uid}/notes`
/// (thumbnail and text) for the teacher's other devices.
@DataClassName('TeacherNoteRow')
class TeacherNotes extends Table {
  TextColumn get id => text()();

  /// The Krucheck account that wrote it: a shared phone shows each teacher
  /// only their own.
  IntColumn get userId => integer()();
  IntColumn get classroomId => integer().nullable()();
  TextColumn get classroomName => text().nullable()();
  TextColumn get body => text()();

  /// The photo as taken (JPEG), only on this device.
  BlobColumn get photo => blob().nullable()();

  /// What goes to Firestore (PNG, at most [kNoteThumbMaxSide] px).
  BlobColumn get thumb => blob().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get updatedAt => dateTime()();

  /// When Firestore last took this version; null = not yet.
  DateTimeColumn get syncedAt => dateTime().nullable()();

  /// Deleted here, still to be deleted in Firestore.
  BoolColumn get deleted => boolean().withDefault(const Constant(false))();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

@DriftDatabase(
  tables: [
    CachedLayouts,
    CachedRosters,
    ScanQueue,
    ModelCache,
    CachedExamKits,
    TeacherNotes,
  ],
)
class AppDatabase extends _$AppDatabase {
  AppDatabase(super.executor);

  /// 2: `scan_queue.kind` and `cached_exam_kits` (exam answer sheets).
  /// 3: `teacher_notes` (DESIGN §40).
  @override
  int get schemaVersion => 3;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onUpgrade: (m, from, to) async {
      if (from < 2) {
        await m.addColumn(scanQueue, scanQueue.kind);
        await m.createTable(cachedExamKits);
      }
      if (from < 3) await m.createTable(teacherNotes);
    },
  );

  /// ISO-8601 text keeps the UTC flag of timestamps across a round trip.
  @override
  DriftDatabaseOptions get options =>
      const DriftDatabaseOptions(storeDateTimeAsText: true);
}
