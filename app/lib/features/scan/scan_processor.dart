import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/db/app_database.dart';
import '../../platform/scan_pipeline.dart';
import '../assignments/assignments_repository.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_repository.dart';
import '../upload_queue/queued_scan.dart';
import '../upload_queue/scan_queue_repository.dart';
import '../upload_queue/upload_queue_providers.dart';
import 'offline_cache_repository.dart';
import 'page_layout.dart';
import 'scan_file_store.dart';
import 'scan_meta.dart';
import 'scan_quality.dart';

/// Reads the numeric crops of a page (the on-device digit reader, DESIGN
/// §12). Keyed by crop id; crops it does not answer are left out.
typedef DigitReader = Future<Map<String, CnnReading>> Function(PageCrops crops);

/// What the scan screen does with one photo.
sealed class ScanAnalysis {
  const ScanAnalysis({
    required this.imagePath,
    required this.capturedAt,
    this.detection,
    this.source = const ScanSource.camera(),
  });

  /// The photo from the camera (cache directory).
  final String imagePath;
  final DateTime capturedAt;
  final PageDetection? detection;

  /// Camera or Google Classroom attachment; sent along in `meta`.
  final ScanSource source;
}

/// The photo cannot be used; [issues] say why.
final class ScanRejected extends ScanAnalysis {
  const ScanRejected({
    required super.imagePath,
    required super.capturedAt,
    super.detection,
    super.source,
    required this.issues,
  });

  final List<ScanIssue> issues;

  /// Only the blur check failed: the teacher may keep the photo anyway.
  bool get canOverride => detection != null && onlyBlur(issues);
}

/// A good photo whose layout is not on the device and could not be fetched
/// (offline). It can wait in the queue as `needs_layout` (DESIGN §6.3).
final class ScanNeedsLayout extends ScanAnalysis {
  const ScanNeedsLayout({
    required super.imagePath,
    required super.capturedAt,
    required PageDetection super.detection,
    super.source,
    required this.qr,
    required this.student,
    required this.reason,
  });

  final WorksheetQr qr;
  final RosterStudent? student;
  final String reason;

  @override
  PageDetection get detection => super.detection!;
}

/// Cropped and ready for the teacher to confirm.
final class ScanReady extends ScanAnalysis {
  const ScanReady({
    required super.imagePath,
    required super.capturedAt,
    required PageDetection super.detection,
    super.source,
    required this.qr,
    required this.layout,
    required this.crops,
    required this.student,
    required this.alreadyQueued,
  });

  final WorksheetQr qr;
  final PageLayout layout;
  final PageCrops crops;
  final RosterStudent? student;

  /// A scan of the same page (assignment, student, page) is still waiting
  /// in the queue; confirming adds a newer one that replaces it on the
  /// server (DESIGN §9.4 rescan rules).
  final bool alreadyQueued;

  @override
  PageDetection get detection => super.detection!;
}

sealed class LayoutLookup {
  const LayoutLookup();
}

final class LayoutFound extends LayoutLookup {
  const LayoutFound(this.layout);

  final PageLayout layout;
}

/// Not cached and the server could not be asked (offline, signed out, 5xx).
final class LayoutUnavailable extends LayoutLookup {
  const LayoutUnavailable(this.reason);

  final String reason;
}

/// The server answered that this assignment, version or page does not
/// exist for this teacher: waiting will not help.
final class LayoutRejected extends LayoutLookup {
  const LayoutRejected(this.issue);

  final ScanIssue issue;
}

class NeedsLayoutRun {
  const NeedsLayoutRun({
    required this.processed,
    required this.waiting,
    required this.failed,
  });

  final int processed;
  final int waiting;
  final int failed;
}

/// Turns camera photos into queued scans: detect -> check -> layout ->
/// crop -> confirm -> `scan_queue` (DESIGN §6.2–§6.4, §9.4). Independent of
/// the camera, so Google Classroom attachments (DESIGN §18.2) go through the
/// same steps with `source: ScanSource.classroom(...)`, which also accepts
/// the anonymous spare worksheet (§18.3) and adds `source` /
/// `google_submission_id` to `meta`.
class ScanProcessor {
  ScanProcessor({
    required this._pipeline,
    required this._offlineCache,
    required this._assignments,
    required this._classrooms,
    required this._queue,
    required this._files,
    required this._onQueued,
    this._digitReader,
    this._minBlurScore = defaultMinBlurScore,
    DateTime Function()? clock,
    String Function()? newId,
  }) : _clock = clock ?? DateTime.now,
       _newId = newId ?? const Uuid().v4;

  final ScanPipeline _pipeline;
  final OfflineCacheRepository _offlineCache;
  final AssignmentsRepository _assignments;
  final ClassroomsRepository _classrooms;
  final ScanQueueRepository _queue;
  final ScanFileStore _files;
  final void Function() _onQueued;
  final DigitReader? _digitReader;
  final double _minBlurScore;
  final DateTime Function() _clock;
  final String Function() _newId;

  /// Assignments whose roster was already fetched once in this session.
  final _rosterFetched = <int>{};

  bool get isSupported => _pipeline.isSupported;

  /// Runs the whole check on a photo. [source] tells a Google Classroom
  /// attachment from a camera photo (DESIGN §18.2, §18.3).
  Future<ScanAnalysis> analyze(
    String imagePath, {
    ScanSource source = const ScanSource.camera(),
  }) async {
    final capturedAt = _clock();
    final PageDetection detection;
    try {
      detection = await _pipeline.detectPage(imagePath);
    } on ScanPipelineException catch (e) {
      return ScanRejected(
        imagePath: imagePath,
        capturedAt: capturedAt,
        source: source,
        issues: [ProcessingFailed(e.message)],
      );
    }
    final issues = checkDetection(
      detection,
      minBlurScore: _minBlurScore,
      allowSpareWorksheet: source.allowsSpareWorksheet,
    );
    if (issues.isNotEmpty) {
      return ScanRejected(
        imagePath: imagePath,
        capturedAt: capturedAt,
        detection: detection,
        source: source,
        issues: issues,
      );
    }
    return _afterDetection(imagePath, capturedAt, detection, source);
  }

  /// The teacher keeps a photo that only failed the blur check.
  Future<ScanAnalysis> acceptDespiteBlur(ScanRejected rejected) {
    final detection = rejected.detection;
    if (!rejected.canOverride || detection == null) {
      return Future.value(rejected);
    }
    return _afterDetection(
      rejected.imagePath,
      rejected.capturedAt,
      detection,
      rejected.source,
    );
  }

  Future<ScanAnalysis> _afterDetection(
    String imagePath,
    DateTime capturedAt,
    PageDetection detection,
    ScanSource source,
  ) async {
    final qr = WorksheetQr.tryParse(detection.qrPayload)!;
    ScanRejected reject(ScanIssue issue) => ScanRejected(
      imagePath: imagePath,
      capturedAt: capturedAt,
      detection: detection,
      source: source,
      issues: [issue],
    );

    final lookup = await lookupLayout(qr);
    if (lookup is LayoutRejected) return reject(lookup.issue);
    final student = await findStudent(qr);
    switch (lookup) {
      case LayoutRejected(:final issue):
        return reject(issue);
      case LayoutUnavailable(:final reason):
        return ScanNeedsLayout(
          imagePath: imagePath,
          capturedAt: capturedAt,
          detection: detection,
          source: source,
          qr: qr,
          student: student,
          reason: reason,
        );
      case LayoutFound(:final layout):
        final PageCrops crops;
        try {
          crops = await _crop(imagePath, detection, layout);
        } on ScanPipelineException catch (e) {
          return reject(ProcessingFailed(e.message));
        } on MissingCropException {
          return reject(
            const ProcessingFailed('ตัดภาพได้ไม่ครบทุกข้อ ลองถ่ายใหม่'),
          );
        }
        return ScanReady(
          imagePath: imagePath,
          capturedAt: capturedAt,
          detection: detection,
          source: source,
          qr: qr,
          layout: layout,
          crops: crops,
          student: student,
          alreadyQueued: await _hasOpenScanFor(qr, source),
        );
    }
  }

  Future<PageCrops> _crop(
    String imagePath,
    PageDetection detection,
    PageLayout layout,
  ) async {
    final crops = await _pipeline.cropPage(
      imagePath,
      detection,
      layout.encode(),
    );
    final ids = {for (final c in crops.regions) c.regionId};
    for (final id in layout.expectedCropIds) {
      if (!ids.contains(id)) {
        await _files.discard([
          crops.warpedPagePath,
          for (final c in crops.regions) c.imagePath,
        ]);
        throw MissingCropException(id);
      }
    }
    return crops;
  }

  /// Layout page of [qr]: from drift, else from the server (and cached).
  Future<LayoutLookup> lookupLayout(WorksheetQr qr) async {
    final cached = await _offlineCache.layoutPage(
      assignmentId: qr.assignmentId,
      version: qr.layoutVersion,
      page: qr.page,
    );
    if (cached != null) {
      try {
        return LayoutFound(PageLayout.fromJson(cached));
      } on FormatException {
        // A broken cache row: ask the server again below.
      }
    }

    final List<Map<String, dynamic>> pages;
    try {
      final versions = await _assignments.layouts(
        qr.assignmentId,
        version: qr.layoutVersion,
      );
      final match = versions.where((v) => v.version == qr.layoutVersion);
      if (match.isEmpty) {
        return LayoutRejected(
          LayoutPageUnknown(page: qr.page, version: qr.layoutVersion),
        );
      }
      pages = match.first.pages;
      await _offlineCache.cacheLayout(qr.assignmentId, qr.layoutVersion, pages);
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == 404 && apiErrorCode(e) == 'layout_unknown') {
        // The teacher owns the assignment; only this version is gone.
        return LayoutRejected(
          LayoutPageUnknown(page: qr.page, version: qr.layoutVersion),
        );
      }
      if (status == 403 || status == 404) {
        return LayoutRejected(
          AssignmentUnknown(qr.assignmentId, apiErrorMessage(e)),
        );
      }
      return LayoutUnavailable(apiErrorMessage(e));
    } on FormatException catch (e) {
      return LayoutUnavailable(
        'ข้อมูล layout จากเซิร์ฟเวอร์ไม่ถูกต้อง (${e.message})',
      );
    }

    for (var i = 0; i < pages.length; i++) {
      final number = (pages[i]['page'] as num?)?.toInt() ?? i + 1;
      if (number != qr.page) continue;
      try {
        return LayoutFound(PageLayout.fromJson(pages[i]));
      } on FormatException {
        return const LayoutRejected(
          ProcessingFailed(
            'layout ของใบงานนี้ไม่ถูกต้อง ให้สร้าง layout ใหม่แล้วพิมพ์ใบงานอีกครั้ง',
          ),
        );
      }
    }
    return LayoutRejected(
      LayoutPageUnknown(page: qr.page, version: qr.layoutVersion),
    );
  }

  /// Name from the cached roster (the QR carries only the id, DESIGN §5.4).
  /// When the student is not cached, the assignment's roster is fetched
  /// once per session if the server is reachable. A spare worksheet
  /// (`student_id = 0`) names nobody: the server matches it through the
  /// Classroom submission (§18.3).
  Future<RosterStudent?> findStudent(WorksheetQr qr) async {
    if (qr.studentId == 0) return null;
    final cached = await _offlineCache.findStudent(qr.studentId);
    if (cached != null || !_rosterFetched.add(qr.assignmentId)) return cached;
    try {
      final assignment = await _assignments.get(qr.assignmentId);
      final roster = await _classrooms.roster(assignment.classroomId);
      await _offlineCache.replaceRoster(assignment.classroomId, roster);
      return _offlineCache.findStudent(
        qr.studentId,
        classroomId: assignment.classroomId,
      );
    } catch (e) {
      debugPrint('roster lookup failed: $e');
      return null;
    }
  }

  Future<bool> _hasOpenScanFor(WorksheetQr qr, ScanSource source) async {
    for (final scan in await _queue.listAll()) {
      if (scan.state == ScanState.done) continue;
      final other = scan.qr;
      if (other != null &&
          other.assignmentId == qr.assignmentId &&
          other.studentId == qr.studentId &&
          other.page == qr.page &&
          // Spare worksheets of different students share student_id 0;
          // only the same Classroom submission is the same page.
          (qr.studentId != 0 || ScanSource.fromMeta(scan.meta) == source)) {
        return true;
      }
    }
    return false;
  }

  /// Confirmed by the teacher: moves the files out of the cache, queues the
  /// scan as `pending` and starts the uploader. Returns the client scan id.
  Future<String> confirm(ScanReady ready) async {
    final id = _newId();
    final upload = buildScanUpload(
      clientScanId: id,
      qrPayload: ready.detection.qrPayload!,
      scannedAt: ready.capturedAt,
      blurScore: ready.detection.blurScore,
      layout: ready.layout,
      crops: ready.crops,
      cnn: await _readDigits(ready.crops),
      extraMeta: ready.source.toMeta(),
    );
    final files = await _files.adopt(id, upload.files);
    await _queue.enqueue(clientScanId: id, meta: upload.meta, files: files);
    // The photo, and the (now empty) output folder of the pipeline.
    await _files.discard([ready.imagePath, ...upload.files.values]);
    _onQueued();
    return id;
  }

  /// Keeps an offline photo as `needs_layout` until its layout arrives.
  Future<String> keepForLater(ScanNeedsLayout scan) async {
    final id = _newId();
    final files = await _files.adopt(id, {rawFileField: scan.imagePath});
    await _queue.enqueue(
      clientScanId: id,
      meta: {
        ...scanMetaHeader(
          clientScanId: id,
          qrPayload: scan.detection.qrPayload!,
          scannedAt: scan.capturedAt,
          blurScore: scan.detection.blurScore,
        ),
        // Kept so the page is cropped later with the same source.
        ...scan.source.toMeta(),
      },
      files: files,
      state: ScanState.needsLayout,
    );
    return id;
  }

  /// Retake / back: deletes the photo and any crops of [analysis].
  Future<void> discard(ScanAnalysis analysis) => _files.discard([
    analysis.imagePath,
    if (analysis case ScanReady(:final crops)) ...[
      crops.warpedPagePath,
      for (final c in crops.regions) c.imagePath,
    ],
  ]);

  /// Crops every `needs_layout` scan whose layout can now be found (cached
  /// by "เตรียมสแกนออฟไลน์" or fetched now) and queues it for upload.
  /// Stops asking the server after the first network failure.
  Future<NeedsLayoutRun> processNeedsLayout() async {
    var processed = 0, waiting = 0, failed = 0;
    var online = true;
    for (final scan in await _queue.listByState(ScanState.needsLayout)) {
      final raw = scan.files[rawFileField];
      final qr = scan.qr;
      if (raw == null || qr == null || !await File(raw).exists()) {
        await _queue.markFailed(
          scan.clientScanId,
          reason: 'ไม่พบภาพต้นฉบับในเครื่อง ต้องสแกนหน้านี้ใหม่',
        );
        failed++;
        continue;
      }
      final LayoutLookup lookup;
      if (online) {
        lookup = await lookupLayout(qr);
      } else {
        final cached = await _offlineCache.layoutPage(
          assignmentId: qr.assignmentId,
          version: qr.layoutVersion,
          page: qr.page,
        );
        lookup = _cachedLookup(cached);
      }
      switch (lookup) {
        case LayoutUnavailable():
          online = false;
          waiting++;
        case LayoutRejected(:final issue):
          await _queue.markFailed(scan.clientScanId, reason: issue.message);
          failed++;
        case LayoutFound(:final layout):
          if (await _finishNeedsLayout(scan, raw, layout)) {
            processed++;
          } else {
            failed++;
          }
      }
    }
    if (processed > 0) _onQueued();
    return NeedsLayoutRun(
      processed: processed,
      waiting: waiting,
      failed: failed,
    );
  }

  static LayoutLookup _cachedLookup(Map<String, dynamic>? cached) {
    if (cached == null) return const LayoutUnavailable('offline');
    try {
      return LayoutFound(PageLayout.fromJson(cached));
    } on FormatException {
      return const LayoutUnavailable('offline');
    }
  }

  Future<bool> _finishNeedsLayout(
    QueuedScan scan,
    String raw,
    PageLayout layout,
  ) async {
    final source = ScanSource.fromMeta(scan.meta);
    try {
      final detection = await _pipeline.detectPage(raw);
      final issues = checkDetection(
        detection,
        minBlurScore: 0,
        allowSpareWorksheet: source.allowsSpareWorksheet,
      );
      if (issues.isNotEmpty) {
        await _queue.markFailed(
          scan.clientScanId,
          reason: issues.first.message,
        );
        return false;
      }
      final crops = await _crop(raw, detection, layout);
      final upload = buildScanUpload(
        clientScanId: scan.clientScanId,
        qrPayload: scan.qrPayload ?? detection.qrPayload!,
        scannedAt:
            DateTime.tryParse(scan.meta['scanned_at'] as String? ?? '') ??
            scan.createdAt,
        blurScore:
            (scan.meta['blur_score'] as num?)?.toDouble() ??
            detection.blurScore,
        layout: layout,
        crops: crops,
        cnn: await _readDigits(crops),
        extraMeta: source.toMeta(),
      );
      final files = await _files.adopt(scan.clientScanId, upload.files);
      final updated = await _queue.completeLayout(
        scan.clientScanId,
        meta: upload.meta,
        files: {...files},
      );
      if (!updated) {
        // Discarded meanwhile: drop what was just made.
        await _files.discard(files.values);
        return false;
      }
      await _files.discard([raw, ...upload.files.values]);
      return true;
    } on ScanPipelineException catch (e) {
      await _queue.markFailed(scan.clientScanId, reason: e.message);
      return false;
    } on MissingCropException {
      await _queue.markFailed(
        scan.clientScanId,
        reason: 'ตัดภาพได้ไม่ครบทุกข้อ ต้องสแกนหน้านี้ใหม่',
      );
      return false;
    }
  }

  Future<Map<String, CnnReading>> _readDigits(PageCrops crops) async {
    final reader = _digitReader;
    if (reader == null) return const {};
    try {
      return await reader(crops);
    } catch (e) {
      // The digit reader is a second opinion only (DESIGN §12.1).
      debugPrint('digit reader failed: $e');
      return const {};
    }
  }
}

/// Starts uploading right after a scan is queued. A foreground drain sends
/// it immediately when online; anything left is handed to WorkManager.
final scanUploadTriggerProvider = Provider<void Function()>((ref) {
  return () {
    unawaited(
      ref
          .read(uploadQueueActionsProvider.notifier)
          .uploadNow()
          .then<void>((_) {})
          .catchError((Object e) => debugPrint('upload after scan: $e')),
    );
  };
});

/// The on-device digit reader (DESIGN §12); null until a model is loaded.
final digitReaderProvider = Provider<DigitReader?>((ref) => null);

final scanProcessorProvider = Provider<ScanProcessor>(
  (ref) => ScanProcessor(
    pipeline: ref.watch(scanPipelineProvider),
    offlineCache: ref.watch(offlineCacheRepositoryProvider),
    assignments: ref.watch(assignmentsRepositoryProvider),
    classrooms: ref.watch(classroomsRepositoryProvider),
    queue: ref.watch(scanQueueRepositoryProvider),
    files: ref.watch(scanFileStoreProvider),
    onQueued: ref.watch(scanUploadTriggerProvider),
    digitReader: ref.watch(digitReaderProvider),
  ),
);
