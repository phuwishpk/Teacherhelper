import 'package:dio/dio.dart';
import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/db/app_database.dart';
import '../../core/db/database_provider.dart';
import '../../platform/answer_sheet_pipeline.dart';
import 'exam_models.dart';
import 'exam_scan_models.dart';

/// One active scanned page of a student (`pages[]` of sheet-status,
/// DESIGN §22.15): what the teacher opens to pick its version.
class ExamSheetPageStatus {
  const ExamSheetPageStatus({
    required this.scanId,
    required this.pageNo,
    this.versionNo,
    this.versionSource,
    this.versionDoubtful = false,
  });

  final int scanId;
  final int pageNo;

  /// Null while the version is unknown ("ให้ครูเลือกชุด").
  final int? versionNo;

  /// single | bubble | page_one | teacher.
  final String? versionSource;

  /// The version bubble was unclear (`version_doubtful`).
  final bool versionDoubtful;

  bool get needsVersion => versionNo == null;

  factory ExamSheetPageStatus.fromJson(Map<String, dynamic> json) =>
      ExamSheetPageStatus(
        scanId: (json['scan_id'] as num).toInt(),
        pageNo: (json['page_no'] as num?)?.toInt() ?? 1,
        versionNo: (json['version_no'] as num?)?.toInt(),
        versionSource: json['version_source'] as String?,
        versionDoubtful: json['version_doubtful'] == true,
      );
}

/// One student's row of `GET /exams/{id}/sheet-status` (DESIGN §22.10).
class ExamSheetStudentStatus {
  const ExamSheetStudentStatus({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    required this.pagesReceived,
    required this.pageCount,
    required this.versionNo,
    required this.score,
    required this.status,
    required this.doubtCount,
    required this.needsVersion,
    this.pages = const [],
  });

  final int studentId;
  final int studentNumber;
  final String name;
  final List<int> pagesReceived;
  final int pageCount;
  final int? versionNo;
  final double? score;

  /// The submission's status, or `missing`.
  final String status;
  final int doubtCount;
  final bool needsVersion;

  /// The active pages, in page order.
  final List<ExamSheetPageStatus> pages;

  bool get isMissing => status == 'missing' || pagesReceived.isEmpty;
  bool get isPublished => status == 'published';
  bool get isReady => status == 'reviewed';
  bool get isComplete =>
      pageCount > 0 &&
      [for (var p = 1; p <= pageCount; p++) p].every(pagesReceived.contains);

  /// Pages of the layout not scanned yet.
  List<int> get missingPages => [
    for (var p = 1; p <= pageCount; p++)
      if (!pagesReceived.contains(p)) p,
  ];

  ExamSheetPageStatus? page(int pageNo) {
    for (final p in pages) {
      if (p.pageNo == pageNo) return p;
    }
    return null;
  }

  factory ExamSheetStudentStatus.fromJson(Map<String, dynamic> json) =>
      ExamSheetStudentStatus(
        studentId: (json['student_id'] as num).toInt(),
        studentNumber: (json['student_number'] as num?)?.toInt() ?? 0,
        name: json['name'] as String? ?? '',
        pagesReceived: [
          for (final p in (json['pages_received'] as List? ?? const []))
            (p as num).toInt(),
        ],
        pageCount: (json['page_count'] as num?)?.toInt() ?? 0,
        versionNo: (json['version_no'] as num?)?.toInt(),
        score: (json['score'] as num?)?.toDouble(),
        status: json['status'] as String? ?? 'missing',
        doubtCount: (json['doubt_count'] as num?)?.toInt() ?? 0,
        needsVersion: json['needs_version'] as bool? ?? false,
        pages: [
          for (final p in (json['pages'] as List? ?? const []))
            ExamSheetPageStatus.fromJson((p as Map).cast<String, dynamic>()),
        ]..sort((a, b) => a.pageNo.compareTo(b.pageNo)),
      );
}

class ExamSheetStatus {
  const ExamSheetStatus({
    required this.students,
    required this.maxScore,
    this.scanned = 0,
    this.total = 0,
    this.missingNumbers = const [],
    this.pageCount = 0,
    this.published = 0,
    this.readyToPublish = 0,
    this.waitingReview = 0,
  });

  final List<ExamSheetStudentStatus> students;
  final double maxScore;

  /// Students with every page in, of [total] in the class.
  final int scanned;
  final int total;
  final List<int> missingNumbers;
  final int pageCount;

  /// The counts "ประกาศผลทั้งห้อง" shows before it runs (§22.11).
  final int published;
  final int readyToPublish;

  /// Scanned but still needs review, a version or a page.
  final int waitingReview;

  /// Nothing is left to publish or review: every scanned student is
  /// published (students without a sheet do not count).
  bool get allPublished =>
      published > 0 && readyToPublish == 0 && waitingReview == 0;

  factory ExamSheetStatus.fromJson(Map<String, dynamic> json) {
    final summary = json['summary'] as Map<String, dynamic>? ?? const {};
    int count(String key) => (summary[key] as num?)?.toInt() ?? 0;
    return ExamSheetStatus(
      students: [
        for (final s in (json['data'] as List? ?? const []))
          ExamSheetStudentStatus.fromJson(s as Map<String, dynamic>),
      ],
      maxScore: (summary['max_score'] as num?)?.toDouble() ?? 0,
      scanned: count('scanned'),
      total: count('total'),
      missingNumbers: [
        for (final n in (summary['missing_numbers'] as List? ?? const []))
          (n as num).toInt(),
      ],
      pageCount: count('page_count'),
      published: count('published'),
      readyToPublish: count('ready_to_publish'),
      waitingReview: count('waiting_review'),
    );
  }

  ExamSheetStudentStatus? of(int studentId) {
    for (final s in students) {
      if (s.studentId == studentId) return s;
    }
    return null;
  }
}

/// One row of the key-sheet proposal (DESIGN §22.3): options are ORIGINAL
/// positions, ready for the key grid.
class KeySheetProposalItem {
  const KeySheetProposalItem({
    required this.questionId,
    required this.sheetNo,
    required this.type,
    required this.acceptedOptions,
    required this.acceptedValues,
    required this.doubtful,
    required this.differs,
  });

  final int questionId;
  final int sheetNo;
  final ExamSectionType type;
  final List<int> acceptedOptions;
  final List<String> acceptedValues;
  final bool doubtful;
  final bool differs;

  /// The key this row proposes, null when nothing was read.
  ExamKey? get key => switch (type) {
    ExamSectionType.numeric =>
      acceptedValues.isEmpty ? null : ExamKey(values: acceptedValues),
    _ => acceptedOptions.isEmpty ? null : ExamKey(options: acceptedOptions),
  };

  factory KeySheetProposalItem.fromJson(Map<String, dynamic> json) =>
      KeySheetProposalItem(
        questionId: (json['question_id'] as num).toInt(),
        sheetNo: (json['sheet_no'] as num?)?.toInt() ?? 0,
        type: ExamSectionType.fromApi(json['type'] as String?),
        acceptedOptions: [
          for (final o in (json['accepted_options'] as List? ?? const []))
            (o as num).toInt(),
        ],
        acceptedValues: [
          for (final v in (json['accepted_values'] as List? ?? const [])) '$v',
        ],
        doubtful: json['doubtful'] as bool? ?? false,
        differs: json['differs'] as bool? ?? false,
      );
}

class KeySheetProposal {
  const KeySheetProposal({
    required this.versionNo,
    required this.page,
    required this.items,
  });

  final int versionNo;
  final int page;
  final List<KeySheetProposalItem> items;

  factory KeySheetProposal.fromJson(Map<String, dynamic> json) =>
      KeySheetProposal(
        versionNo: (json['version_no'] as num).toInt(),
        page: (json['page'] as num?)?.toInt() ?? 1,
        items: [
          for (final i in (json['proposal'] as List? ?? const []))
            KeySheetProposalItem.fromJson(i as Map<String, dynamic>),
        ],
      );
}

/// The server's reading of one answer-sheet page: the body of
/// `POST /exam-sheets` and of `POST /exam-sheets/{scan_id}/version`
/// (DESIGN §22.15). [score] and [maxScore] are of this page, null while
/// the version is unknown.
class ExamSheetPageResult {
  const ExamSheetPageResult({
    required this.scanId,
    required this.pageNo,
    required this.needsVersion,
    this.submissionId,
    this.state,
    this.pageCount,
    this.versionNo,
    this.score,
    this.maxScore,
    this.doubts = const [],
  });

  final int scanId;
  final int? submissionId;
  final String? state;
  final int pageNo;
  final int? pageCount;
  final int? versionNo;
  final double? score;
  final double? maxScore;

  /// `{sheet_no, reason}` of each doubt; sheet_no is null for the version.
  final List<({int? sheetNo, String reason})> doubts;
  final bool needsVersion;

  factory ExamSheetPageResult.fromJson(Map<String, dynamic> json) =>
      ExamSheetPageResult(
        scanId: (json['scan_id'] as num).toInt(),
        submissionId: (json['submission_id'] as num?)?.toInt(),
        state: json['state'] as String?,
        pageNo: (json['page_no'] as num?)?.toInt() ?? 1,
        pageCount: (json['page_count'] as num?)?.toInt(),
        versionNo: (json['version_no'] as num?)?.toInt(),
        score: (json['score'] as num?)?.toDouble(),
        maxScore: (json['max_score'] as num?)?.toDouble(),
        doubts: [
          for (final d in (json['doubts'] as List? ?? const []))
            if (d is Map)
              (
                sheetNo: (d['sheet_no'] as num?)?.toInt(),
                reason: '${d['reason']}',
              ),
        ],
        needsVersion: json['needs_version'] == true,
      );
}

/// The exam scanning endpoints of build 3 (DESIGN §22.15). Answer-sheet
/// pages themselves go up through the scan queue (`POST /exam-sheets`).
abstract class ExamScanRepository {
  /// `GET /exams/{id}/scan-kit`.
  Future<ExamScanKit> scanKit(int examId);

  /// `GET /exams/{id}/sheet-status`.
  Future<ExamSheetStatus> sheetStatus(int examId);

  /// `POST /exam-sheets/{scan_id}/version`: the teacher picks the version
  /// of a page and the server scores the student at once (§22.11).
  Future<ExamSheetPageResult> chooseVersion(int scanId, int versionNo);

  /// `POST /exams/{id}/key-sheet-read`: a proposal, nothing is saved.
  Future<KeySheetProposal> keySheetRead(
    int examId, {
    required String qr,
    required AnswerSheetReading reading,
    int? versionNo,
  });
}

class ApiExamScanRepository implements ExamScanRepository {
  ApiExamScanRepository(this._dio);

  final Dio _dio;

  @override
  Future<ExamScanKit> scanKit(int examId) async {
    final res = await _dio.get<Object?>('/exams/$examId/scan-kit');
    return ExamScanKit.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ExamSheetStatus> sheetStatus(int examId) async {
    final res = await _dio.get<Object?>('/exams/$examId/sheet-status');
    return ExamSheetStatus.fromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<ExamSheetPageResult> chooseVersion(int scanId, int versionNo) async {
    final res = await _dio.post<Object?>(
      '/exam-sheets/$scanId/version',
      data: {'version_no': versionNo},
    );
    return ExamSheetPageResult.fromJson(unwrapJson(res.data));
  }

  @override
  Future<KeySheetProposal> keySheetRead(
    int examId, {
    required String qr,
    required AnswerSheetReading reading,
    int? versionNo,
  }) async {
    final res = await _dio.post<Object?>(
      '/exams/$examId/key-sheet-read',
      data: {'qr': qr, 'version_no': ?versionNo, ...reading.toApiJson()},
    );
    return KeySheetProposal.fromJson(unwrapJson(res.data));
  }
}

final examScanRepositoryProvider = Provider<ExamScanRepository>(
  (ref) => ApiExamScanRepository(ref.watch(dioProvider)),
);

/// Scan kits kept on the phone for offline scanning (DESIGN §22.9 step 1,
/// drift `cached_exam_kits`). A kit older than [maxAge] is deleted: it may
/// hold a key that was changed since.
class ExamKitCache {
  ExamKitCache(this._db, {DateTime Function()? clock})
    : _clock = clock ?? DateTime.now;

  static const maxAge = Duration(days: 30);

  final AppDatabase _db;
  final DateTime Function() _clock;

  Future<void> save(ExamScanKit kit) => _db
      .into(_db.cachedExamKits)
      .insertOnConflictUpdate(
        CachedExamKitsCompanion.insert(
          assignmentId: Value(kit.assignmentId),
          kitHash: kit.kitHash,
          json: kit.encode(),
          fetchedAt: _clock(),
        ),
      );

  /// The cached kit of [examId] and when it was fetched, or null.
  Future<({ExamScanKit kit, DateTime fetchedAt})?> load(int examId) async {
    await purgeOld();
    final row = await (_db.select(
      _db.cachedExamKits,
    )..where((t) => t.assignmentId.equals(examId))).getSingleOrNull();
    if (row == null) return null;
    try {
      return (kit: ExamScanKit.decode(row.json), fetchedAt: row.fetchedAt);
    } on FormatException {
      await remove(examId);
      return null;
    } on TypeError {
      await remove(examId);
      return null;
    }
  }

  Future<void> remove(int examId) => (_db.delete(
    _db.cachedExamKits,
  )..where((t) => t.assignmentId.equals(examId))).go();

  Future<int> purgeOld() =>
      (_db.delete(_db.cachedExamKits)..where(
            (t) => t.fetchedAt.isSmallerThanValue(_clock().subtract(maxAge)),
          ))
          .go();
}

final examKitCacheProvider = Provider<ExamKitCache>(
  (ref) => ExamKitCache(ref.watch(appDatabaseProvider)),
);
