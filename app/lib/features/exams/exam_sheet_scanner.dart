import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/db/app_database.dart';
import '../../platform/answer_sheet_pipeline.dart';
import '../../platform/scan_pipeline.dart';
import '../assignments/assignments_repository.dart';
import '../scan/scan_file_store.dart';
import '../scan/scan_processor.dart';
import '../scan/scan_quality.dart';
import '../upload_queue/queued_scan.dart';
import '../upload_queue/scan_queue_repository.dart';
import 'exam_scan_models.dart';
import 'exam_scan_repository.dart';

/// What happened to one photo of an answer sheet.
sealed class ExamSheetOutcome {
  const ExamSheetOutcome();
}

/// The photo cannot be used; [message] says why (Thai).
final class ExamSheetRejected extends ExamSheetOutcome {
  const ExamSheetRejected(this.message, {this.keptPhoto});

  final String message;

  /// Only the blur check failed: the photo is kept so the teacher may use
  /// it anyway (scan again with `acceptBlur`) or discard it.
  final String? keptPhoto;

  bool get blurOnly => keptPhoto != null;
}

/// Read, scored on the phone and queued for `POST /exam-sheets`.
final class ExamSheetQueued extends ExamSheetOutcome {
  const ExamSheetQueued({
    required this.clientScanId,
    required this.qr,
    required this.student,
    required this.pageCount,
    required this.version,
    required this.versionLabel,
    required this.score,
    required this.scannedAt,
    this.identifiedBy = identifiedByQr,
  });

  /// The student's own QR was on the sheet (§22.6).
  static const identifiedByQr = 'qr';

  /// The student ID filled in on a shared sheet named them (§22.19).
  static const identifiedByCode = 'code';

  /// The teacher picked the student of a shared sheet.
  static const identifiedByTeacher = 'teacher';

  final String clientScanId;

  /// For a shared sheet: its QR with the student it was filed under.
  final ExamQr qr;
  final ExamKitStudent? student;

  /// [identifiedByQr], [identifiedByCode] or [identifiedByTeacher].
  final String identifiedBy;
  final int pageCount;
  final ExamVersionDecision version;
  final String? versionLabel;

  /// Null while the version is unknown.
  final ExamPageScore? score;
  final DateTime scannedAt;

  int get reviewCount => score?.reviewCount ?? 0;

  /// Why no score is shown, or null.
  String? get waiting => version.versionNo != null
      ? null
      : qr.page > 1
      ? 'รอหน้า 1'
      : 'ให้ครูเลือกชุด';
}

/// A shared sheet (DESIGN §22.19) that was read but not queued yet: its
/// student still has to be chosen. Holds what [ExamSheetScanner.assign]
/// needs; the warped page file lives until `assign` or `discard`.
class PendingExamSheet {
  const PendingExamSheet({
    required this.qr,
    required this.detection,
    required this.reading,
    required this.capturedAt,
  });

  final ExamQr qr;
  final PageDetection detection;
  final AnswerSheetReading reading;
  final DateTime capturedAt;
}

/// A shared sheet whose filled-in ID does not settle the student: the
/// teacher picks one from the roster, or discards the photo. Nothing is
/// queued without a student.
final class ExamSheetNeedsStudent extends ExamSheetOutcome {
  const ExamSheetNeedsStudent({
    required this.pending,
    required this.reason,
    this.code,
    this.suggested,
  });

  /// The ID names a student whose page is already scanned.
  static const duplicate = 'duplicate';

  /// The ID was read but no student (or more than one) has it.
  static const unknown = 'unknown';

  final PendingExamSheet pending;

  /// `blank`, `invalid`, `unclear` (StudentCodeReader), [unknown] or
  /// [duplicate].
  final String reason;

  /// The ID that was read, when one was.
  final String? code;

  /// The student the ID names (only with [duplicate]).
  final ExamKitStudent? suggested;

  /// What to tell the teacher above the list of students.
  String get message => switch (reason) {
    StudentCodeReader.blank =>
      'ไม่ได้ฝนเลขประจำตัว เลือกนักเรียนของกระดาษใบนี้',
    StudentCodeReader.invalid =>
      'เลขประจำตัวฝนไม่ครบหรือฝนซ้ำหลัก เลือกนักเรียนของกระดาษใบนี้',
    StudentCodeReader.unclear =>
      'เลขประจำตัวฝนไม่ชัด เลือกนักเรียนของกระดาษใบนี้',
    duplicate =>
      'เลขประจำตัว $code เป็นของเลขที่ ${suggested?.studentNumber ?? '-'} '
          '${suggested?.name ?? ''} ซึ่งสแกนหน้า ${pending.qr.page} แล้ว '
          'เลือกคนเดิมเพื่อสแกนแทนใบเดิม หรือเลือกนักเรียนที่ถูกต้อง',
    _ => 'ไม่พบเลขประจำตัว ${code ?? ''} ในห้องนี้ เลือกนักเรียนของกระดาษใบนี้',
  };
}

/// Why the teacher's key sheet could not be read, or its proposal.
sealed class KeySheetOutcome {
  const KeySheetOutcome();
}

final class KeySheetRejected extends KeySheetOutcome {
  const KeySheetRejected(this.message);

  final String message;
}

final class KeySheetRead extends KeySheetOutcome {
  const KeySheetRead(this.proposal);

  final KeySheetProposal proposal;
}

/// Photo -> markers, QR, blur -> answer-sheet layout of the scan kit ->
/// bubble fill (Kotlin) -> version and score on the phone -> `scan_queue`
/// as `exam_sheet` (DESIGN §22.9). Also reads the teacher's key sheet
/// (§22.3), which needs the server and is not queued.
class ExamSheetScanner {
  ExamSheetScanner({
    required this._pipeline,
    required this._queue,
    required this._files,
    required this._onQueued,
    this._minBlurScore = defaultMinBlurScore,
    DateTime Function()? clock,
    String Function()? newId,
  }) : _clock = clock ?? DateTime.now,
       _newId = newId ?? const Uuid().v4;

  final AnswerSheetPipeline _pipeline;
  final ScanQueueRepository _queue;
  final ScanFileStore _files;
  final void Function() _onQueued;
  final double _minBlurScore;
  final DateTime Function() _clock;
  final String Function() _newId;

  bool get isSupported => _pipeline.isSupported;

  AnswerSheetPipeline get pipeline => _pipeline;

  /// Reads a student's answer sheet of [kit] and queues it. [pageOneVersion]
  /// gives the version of page 1 of a student when known (this session or
  /// the server), for page 2.
  ///
  /// A shared sheet (§22.19) is queued only when the ID filled in on it
  /// names exactly one student of the roster whose page is not in yet
  /// ([alreadyScanned]); otherwise the answer is [ExamSheetNeedsStudent]
  /// and the caller finishes with [assign] or [discard].
  Future<ExamSheetOutcome> scan(
    String imagePath,
    ExamScanKit kit, {
    required int? Function(int studentId) pageOneVersion,
    bool Function(int studentId, int page)? alreadyScanned,
    bool acceptBlur = false,
  }) async {
    final capturedAt = _clock();
    final checked = await _detect(imagePath, acceptBlur: acceptBlur);
    if (checked.error != null) {
      if (checked.blurOnly) {
        return ExamSheetRejected(checked.error!, keptPhoto: imagePath);
      }
      await _files.discard([imagePath]);
      return ExamSheetRejected(checked.error!);
    }
    final detection = checked.detection!;
    final qr = checked.qr!;
    String? problem;
    if (qr.assignmentId != kit.assignmentId) {
      problem = 'กระดาษคำตอบนี้เป็นของข้อสอบอื่น (#${qr.assignmentId})';
    } else if (qr.isKeySheet) {
      problem =
          'นี่คือกระดาษเฉลยของครู สแกนที่หน้าตารางเฉลย ("สแกนกระดาษเฉลย")';
    } else if (qr.layoutVersion != kit.layoutVersion) {
      problem =
          'กระดาษคำตอบนี้พิมพ์จาก layout เวอร์ชัน ${qr.layoutVersion} '
          'แต่ข้อมูลสแกนในเครื่องเป็นเวอร์ชัน ${kit.layoutVersion ?? '-'} '
          'กด "เตรียมสแกน" ใหม่ขณะออนไลน์ หรือพิมพ์กระดาษคำตอบใหม่';
    } else if (qr.shared != kit.codeSheets) {
      problem = qr.shared
          ? 'ข้อสอบนี้ใช้กระดาษคำตอบแบบ QR รายคน '
                'กระดาษแบบฝนเลขประจำตัวใบนี้ใช้ไม่ได้'
          : 'ข้อสอบนี้ใช้กระดาษคำตอบแบบฝนเลขประจำตัว '
                'กระดาษแบบ QR รายคนใบนี้ใช้ไม่ได้';
    } else if (!qr.shared && kit.student(qr.studentId) == null) {
      problem = 'ไม่พบนักเรียนของกระดาษคำตอบนี้ในรายชื่อห้อง';
    }
    final page = kit.layoutPage(qr.page);
    if (problem == null && page == null) {
      problem = 'กระดาษคำตอบนี้มี ${kit.pageCount} หน้า ไม่มีหน้า ${qr.page}';
    }
    if (problem != null) {
      await _files.discard([imagePath]);
      return ExamSheetRejected(problem);
    }

    final AnswerSheetReading reading;
    try {
      reading = await _pipeline.readAnswerSheet(
        imagePath,
        detection,
        jsonEncode(page),
      );
    } on ScanPipelineException catch (e) {
      await _files.discard([imagePath]);
      return ExamSheetRejected(e.message);
    }

    // The photo is not needed any more; the warped page is what is uploaded.
    await _files.discard([imagePath]);
    final pending = PendingExamSheet(
      qr: qr,
      detection: detection,
      reading: reading,
      capturedAt: capturedAt,
    );
    if (!qr.shared) {
      return _enqueue(
        pending,
        kit,
        kit.student(qr.studentId)!,
        ExamSheetQueued.identifiedByQr,
        pageOneVersion,
      );
    }

    final read = StudentCodeReader.read(
      reading.digits[StudentCodeReader.sheetNo],
    );
    final code = read.code;
    final student = code == null ? null : kit.studentByCode(code);
    if (student == null) {
      return ExamSheetNeedsStudent(
        pending: pending,
        reason: read.problem ?? ExamSheetNeedsStudent.unknown,
        code: code,
      );
    }
    if (alreadyScanned?.call(student.studentId, qr.page) ?? false) {
      return ExamSheetNeedsStudent(
        pending: pending,
        reason: ExamSheetNeedsStudent.duplicate,
        code: code,
        suggested: student,
      );
    }
    return _enqueue(
      pending,
      kit,
      student,
      ExamSheetQueued.identifiedByCode,
      pageOneVersion,
    );
  }

  /// Queues a shared sheet for the student the teacher picked. When the ID
  /// on the sheet is that student's, the upload says so (`code`) and the
  /// server checks it; otherwise it is the teacher's word (`teacher`).
  Future<ExamSheetQueued> assign(
    PendingExamSheet pending,
    ExamScanKit kit,
    ExamKitStudent student, {
    required int? Function(int studentId) pageOneVersion,
  }) {
    final code = StudentCodeReader.read(
      pending.reading.digits[StudentCodeReader.sheetNo],
    ).code;
    final byCode = code != null && code == student.studentCode;
    return _enqueue(
      pending,
      kit,
      student,
      byCode
          ? ExamSheetQueued.identifiedByCode
          : ExamSheetQueued.identifiedByTeacher,
      pageOneVersion,
    );
  }

  /// Drops a shared sheet the teacher did not assign.
  Future<void> discard(PendingExamSheet pending) =>
      _files.discard([pending.reading.warpedPagePath]);

  /// Decides the version, scores the page on the phone and puts it in
  /// `scan_queue` for [student].
  Future<ExamSheetQueued> _enqueue(
    PendingExamSheet pending,
    ExamScanKit kit,
    ExamKitStudent student,
    String identifiedBy,
    int? Function(int studentId) pageOneVersion,
  ) async {
    final reading = pending.reading;
    final qr = pending.qr.shared
        ? pending.qr.forStudent(student.studentId)
        : pending.qr;
    final version = ExamSheetScorer.version(
      kit.versionCount,
      qr.page,
      reading.versionFill,
      pageOneVersion(qr.studentId),
    );
    final key = version.versionNo == null
        ? null
        : kit.versions[version.versionNo]?.key;
    final score = key == null
        ? null
        : ExamSheetScorer.scorePage(key, reading.rows, reading.digits);

    final id = _newId();
    final meta = <String, Object?>{
      'client_scan_id': id,
      'qr': pending.detection.qrPayload,
      'scanned_at': pending.capturedAt.toUtc().toIso8601String(),
      'blur_score': pending.detection.blurScore,
      if (qr.shared) ...{
        'student_id': student.studentId,
        'student_source': identifiedBy,
      },
      ...reading.toApiJson(),
      'device_score': ?score?.score,
    };
    final files = await _files.adopt(id, {'page': reading.warpedPagePath});
    await _queue.enqueue(
      clientScanId: id,
      meta: meta,
      files: files,
      kind: ScanKind.examSheet,
    );
    await _files.discard([reading.warpedPagePath]);
    _onQueued();

    return ExamSheetQueued(
      clientScanId: id,
      qr: qr,
      student: student,
      pageCount: kit.pageCount,
      version: version,
      versionLabel: version.versionNo == null
          ? null
          : kit.versions[version.versionNo]?.label,
      score: score,
      scannedAt: pending.capturedAt,
      identifiedBy: identifiedBy,
    );
  }

  /// Reads the teacher's key sheet of exam [examId] and asks the server for
  /// the proposal (§22.3). [layoutPages] loads the layout of a version
  /// (`GET /assignments/{id}/layouts?version=`); [versionNo] is the version
  /// page 1 gave, for page 2.
  Future<KeySheetOutcome> readKeySheet(
    String imagePath,
    int examId, {
    required Future<List<Map<String, dynamic>>> Function(int layoutVersion)
    layoutPages,
    required ExamScanRepository repository,
    int? versionNo,
  }) async {
    try {
      final checked = await _detect(imagePath, acceptBlur: false);
      if (checked.error != null) return KeySheetRejected(checked.error!);
      final qr = checked.qr!;
      if (qr.assignmentId != examId) {
        return KeySheetRejected(
          'กระดาษนี้เป็นของข้อสอบอื่น (#${qr.assignmentId})',
        );
      }
      if (!qr.isKeySheet) {
        return const KeySheetRejected(
          'นี่คือกระดาษคำตอบของนักเรียน ไม่ใช่กระดาษเฉลยของครู',
        );
      }
      final pages = await layoutPages(qr.layoutVersion);
      Map<String, dynamic>? page;
      for (var i = 0; i < pages.length; i++) {
        if (((pages[i]['page'] as num?)?.toInt() ?? i + 1) == qr.page) {
          page = pages[i];
        }
      }
      if (page == null) {
        return KeySheetRejected('ไม่พบหน้า ${qr.page} ของกระดาษเฉลยนี้');
      }
      final reading = await _pipeline.readAnswerSheet(
        imagePath,
        checked.detection!,
        jsonEncode(page),
      );
      try {
        final proposal = await repository.keySheetRead(
          examId,
          qr: checked.detection!.qrPayload!,
          reading: reading,
          versionNo: versionNo,
        );
        return KeySheetRead(proposal);
      } finally {
        // The warped key page is not kept, whether the server answered or not.
        await _files.discard([reading.warpedPagePath]);
      }
    } on ScanPipelineException catch (e) {
      return KeySheetRejected(e.message);
    } on DioException catch (e) {
      return KeySheetRejected(apiErrorMessage(e));
    } finally {
      await _files.discard([imagePath]);
    }
  }

  Future<({PageDetection? detection, ExamQr? qr, String? error, bool blurOnly})>
  _detect(String imagePath, {required bool acceptBlur}) async {
    final PageDetection detection;
    try {
      detection = await _pipeline.detectPage(imagePath);
    } on ScanPipelineException catch (e) {
      return (detection: null, qr: null, error: e.message, blurOnly: false);
    }
    final missing = detection.missingMarkerIds.toSet().toList()..sort();
    String? error;
    if (missing.isNotEmpty) {
      error = MarkersMissing(missing).message;
    } else if (detection.qrPayload == null || detection.qrPayload!.isEmpty) {
      error = const QrUnreadable().message;
    }
    final qr = ExamQr.tryParse(detection.qrPayload);
    if (error == null && qr == null) {
      error = WorksheetQr.tryParse(detection.qrPayload) != null
          ? 'นี่คือใบงานของการบ้าน ไม่ใช่กระดาษคำตอบข้อสอบ สแกนจากเมนู "สแกน"'
          : 'QR นี้ไม่ใช่กระดาษคำตอบข้อสอบของ Krucheck';
    }
    if (error == null && !acceptBlur && detection.blurScore < _minBlurScore) {
      return (
        detection: detection,
        qr: qr,
        error: TooBlurry(detection.blurScore, _minBlurScore).message,
        blurOnly: true,
      );
    }
    return (detection: detection, qr: qr, error: error, blurOnly: false);
  }
}

final examSheetScannerProvider = Provider<ExamSheetScanner>(
  (ref) => ExamSheetScanner(
    pipeline: ref.watch(answerSheetPipelineProvider),
    queue: ref.watch(scanQueueRepositoryProvider),
    files: ref.watch(scanFileStoreProvider),
    onQueued: ref.watch(scanUploadTriggerProvider),
  ),
);

/// Layout pages of an exam's answer-sheet layout version, for the key sheet.
final examLayoutPagesProvider =
    Provider<
      Future<List<Map<String, dynamic>>> Function(int examId, int version)
    >((ref) {
      final assignments = ref.watch(assignmentsRepositoryProvider);
      return (examId, version) async {
        final versions = await assignments.layouts(examId, version: version);
        return [
          for (final v in versions)
            if (v.version == version) ...v.pages,
        ];
      };
    });

/// Continuous scanning (DESIGN §22.10): takes the photo when two frames in a
/// row show all four markers, a QR of this exam's answer sheets and a sharp
/// enough image.
class FrameGate {
  FrameGate({
    required this.examId,
    this.minBlurScore = defaultMinBlurScore,
    this.needed = 2,
  });

  final int examId;
  final double minBlurScore;
  final int needed;

  int _streak = 0;
  String? _payload;

  /// The QR payload when [frame] completes the streak, else null.
  String? offer(FrameDetection frame) {
    final qr = ExamQr.tryParse(frame.qrPayload);
    final good =
        frame.markersFound >= 4 &&
        qr != null &&
        qr.assignmentId == examId &&
        !qr.isKeySheet &&
        frame.blurScore >= minBlurScore;
    if (!good) {
      reset();
      return null;
    }
    if (_payload != frame.qrPayload) {
      _streak = 0;
      _payload = frame.qrPayload;
    }
    _streak++;
    if (_streak < needed) return null;
    _streak = 0;
    return _payload;
  }

  void reset() {
    _streak = 0;
    _payload = null;
  }
}

enum DuplicateVerdict {
  /// Not scanned in this session.
  fresh,

  /// The same sheet within [ExamScanSession.silentWindow]: skip quietly.
  silent,

  /// Scanned earlier in this session: ask before replacing it.
  seen,
}

/// The students of the running summary (§22.10) who still miss pages.
class ExamMissingStudent {
  const ExamMissingStudent(this.student, this.missingPages);

  final ExamKitStudent student;
  final List<int> missingPages;
}

class ExamScanSummary {
  const ExamScanSummary({
    required this.scanned,
    required this.total,
    required this.missing,
  });

  final int scanned;
  final int total;
  final List<ExamMissingStudent> missing;

  static const shownNumbers = 10;

  /// "สแกนแล้ว 12/30 คน ยังขาด เลขที่ 3, 7, 12".
  String get text {
    final head = 'สแกนแล้ว $scanned/$total คน';
    if (total == 0) return head;
    if (missing.isEmpty) return '$head ครบทุกคนแล้ว';
    final numbers = [
      for (final m in missing.take(shownNumbers)) m.student.studentNumber,
    ].join(', ');
    final more = missing.length > shownNumbers
        ? ' และอีก ${missing.length - shownNumbers} คน'
        : '';
    return '$head ยังขาด เลขที่ $numbers$more';
  }
}

/// State of one answer-sheet scanning session: what was scanned now,
/// what the server already has, and the duplicate rule of §22.10.
class ExamScanSession {
  ExamScanSession(this.kit);

  /// A repeat of the same sheet within this window is skipped silently.
  static const silentWindow = Duration(seconds: 5);

  final ExamScanKit kit;

  /// Student -> page -> the latest outcome of this session.
  final results = <int, Map<int, ExamSheetQueued>>{};
  final _seenAt = <(int, int), DateTime>{};

  /// Pages of this exam waiting in the upload queue: student -> pages.
  Map<int, Set<int>> queued = const {};

  /// The latest `GET /exams/{id}/sheet-status`, when online.
  ExamSheetStatus? server;

  DuplicateVerdict check(ExamQr qr, DateTime now) {
    final at = _seenAt[(qr.studentId, qr.page)];
    if (at == null) return DuplicateVerdict.fresh;
    return now.difference(at) < silentWindow
        ? DuplicateVerdict.silent
        : DuplicateVerdict.seen;
  }

  /// Marks [qr]'s sheet as just seen (also for a rejected read, so the same
  /// sheet is not photographed again and again while it lies in view).
  void seen(ExamQr qr, DateTime now) => _seenAt[(qr.studentId, qr.page)] = now;

  void record(ExamSheetQueued outcome) {
    results.putIfAbsent(outcome.qr.studentId, () => {})[outcome.qr.page] =
        outcome;
    seen(outcome.qr, outcome.scannedAt);
  }

  /// Page 1's version of [studentId]: this session first, then the server.
  int? pageOneVersion(int studentId) =>
      results[studentId]?[1]?.version.versionNo ??
      server?.of(studentId)?.versionNo;

  /// Updates [queued] from the upload queue rows of this exam.
  void setQueue(Iterable<QueuedScan> scans) {
    final out = <int, Set<int>>{};
    for (final scan in scans) {
      if (scan.kind != ScanKind.examSheet || scan.state == ScanState.failed) {
        continue;
      }
      final qr = ExamQr.tryParse(scan.qrPayload);
      if (qr == null || qr.assignmentId != kit.assignmentId) continue;
      // A shared sheet's QR names no student: the queued meta does (§22.19).
      final studentId = qr.shared
          ? (scan.meta['student_id'] as num?)?.toInt()
          : qr.studentId;
      if (studentId == null) continue;
      out.putIfAbsent(studentId, () => {}).add(qr.page);
    }
    queued = out;
  }

  Set<int> pagesOf(int studentId) => {
    ...?results[studentId]?.keys,
    ...?queued[studentId],
    ...?server?.of(studentId)?.pagesReceived,
  };

  ExamScanSummary summary() {
    final pages = kit.pageCount < 1 ? 1 : kit.pageCount;
    var scanned = 0;
    final missing = <ExamMissingStudent>[];
    final roster = [...kit.roster]
      ..sort((a, b) => a.studentNumber.compareTo(b.studentNumber));
    for (final student in roster) {
      final have = pagesOf(student.studentId);
      final lacking = [
        for (var p = 1; p <= pages; p++)
          if (!have.contains(p)) p,
      ];
      if (lacking.isEmpty) {
        scanned++;
      } else {
        missing.add(ExamMissingStudent(student, lacking));
      }
    }
    return ExamScanSummary(
      scanned: scanned,
      total: roster.length,
      missing: missing,
    );
  }
}

/// Logs a failed status refresh without failing the screen.
void logStatusError(Object e) => debugPrint('sheet-status: $e');
