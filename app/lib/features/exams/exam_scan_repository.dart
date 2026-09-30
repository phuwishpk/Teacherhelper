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
      );
}

class ExamSheetStatus {
  const ExamSheetStatus({required this.students, required this.maxScore});

  final List<ExamSheetStudentStatus> students;
  final double maxScore;

  factory ExamSheetStatus.fromJson(Map<String, dynamic> json) {
    final summary = json['summary'] as Map<String, dynamic>? ?? const {};
    return ExamSheetStatus(
      students: [
        for (final s in (json['data'] as List? ?? const []))
          ExamSheetStudentStatus.fromJson(s as Map<String, dynamic>),
      ],
      maxScore: (summary['max_score'] as num?)?.toDouble() ?? 0,
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

/// The exam scanning endpoints of build 3 (DESIGN §22.15). Answer-sheet
/// pages themselves go up through the scan queue (`POST /exam-sheets`).
abstract class ExamScanRepository {
  /// `GET /exams/{id}/scan-kit`.
  Future<ExamScanKit> scanKit(int examId);

  /// `GET /exams/{id}/sheet-status`.
  Future<ExamSheetStatus> sheetStatus(int examId);

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
