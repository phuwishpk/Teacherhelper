import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'queued_scan.dart';
import 'scan_queue_repository.dart';

/// HTTP answers to `POST /scans` that a later attempt can fix: 401 (sign in
/// again), 408, 429 and every 5xx. Other 4xx are final (DESIGN §9, §9.4).
bool isRetryableStatus(int status) =>
    status == 401 || status == 408 || status == 429 || status >= 500;

/// Delay before attempt number [attempts] + 1 (30 s, 1 min, 2 min … 1 h).
Duration backoffFor(int attempts) {
  const base = Duration(seconds: 30);
  const cap = Duration(hours: 1);
  final factor = pow(2, min(attempts, 12)).toInt();
  final d = base * factor;
  return d > cap ? cap : d;
}

enum UploadOutcome {
  done,
  conflict,
  failed,
  retry,

  /// The row was already settled by another isolate (or removed) before
  /// this attempt could claim it; nothing was sent.
  skipped,
}

class DrainResult {
  const DrainResult({
    required this.done,
    required this.conflict,
    required this.failed,
    required this.retry,
    this.skipped = 0,
  });

  final int done;
  final int conflict;
  final int failed;
  final int retry;
  final int skipped;

  /// True when nothing in THIS drain is left that a later attempt could
  /// still fix. Rows whose backoff has not elapsed are not visited by a
  /// drain, so the caller must also look at the pending count before
  /// deciding that no retry is needed (see `runBackgroundDrain`).
  bool get settled => retry == 0;
}

/// Sends queued scans as `POST /scans` multipart (DESIGN §9.4) and applies
/// the response rules to the local queue.
class ScanUploader {
  ScanUploader({
    required this._dio,
    required ScanQueueRepository repository,
    DateTime Function()? clock,
  }) : _repo = repository,
       _clock = clock ?? DateTime.now;

  final Dio _dio;
  final ScanQueueRepository _repo;
  final DateTime Function() _clock;

  /// Uploads every due scan once. Backoff and retries are recorded in the
  /// queue; the caller (workmanager or the queue screen) decides when to
  /// call again.
  Future<DrainResult> drain() async {
    var done = 0, conflict = 0, failed = 0, retry = 0, skipped = 0;
    for (final scan in await _repo.dueForUpload()) {
      switch (await uploadOne(scan)) {
        case UploadOutcome.done:
          done++;
        case UploadOutcome.conflict:
          conflict++;
        case UploadOutcome.failed:
          failed++;
        case UploadOutcome.retry:
          retry++;
        case UploadOutcome.skipped:
          skipped++;
      }
    }
    return DrainResult(
      done: done,
      conflict: conflict,
      failed: failed,
      retry: retry,
      skipped: skipped,
    );
  }

  Future<UploadOutcome> uploadOne(QueuedScan scan) async {
    // Claim the row; a concurrent drain (WorkManager vs. foreground) that
    // already settled it wins and this attempt must not send anything.
    if (!await _repo.markUploading(scan.clientScanId)) {
      return UploadOutcome.skipped;
    }
    final FormData form;
    try {
      form = await _buildForm(scan);
    } on FileSystemException catch (e) {
      // A crop file vanished: nothing a retry can fix.
      await _repo.markFailed(
        scan.clientScanId,
        reason: 'ไฟล์ภาพหายไปจากเครื่อง (${e.path})',
      );
      return UploadOutcome.failed;
    }

    try {
      final res = await _dio.post<Object?>(
        '/scans',
        data: form,
        options: Options(
          // 4xx are handled below without throwing; 5xx still throw.
          validateStatus: (s) => s != null && s < 500,
          sendTimeout: const Duration(minutes: 2),
          receiveTimeout: const Duration(minutes: 2),
        ),
      );
      return _applyResponse(scan, res);
    } on DioException catch (e) {
      return _scheduleRetry(scan, apiErrorMessage(e));
    }
  }

  /// Teacher confirmed replacing a published scan (DESIGN §9.4).
  Future<void> confirmReplace(QueuedScan scan) async {
    final id = scan.serverScanId;
    if (id == null) {
      throw StateError('conflict scan without server id');
    }
    await _dio.post<Object?>('/scans/$id/confirm-replace');
    await _repo.markDone(scan.clientScanId, serverScanId: id);
  }

  Future<FormData> _buildForm(QueuedScan scan) async {
    final form = FormData();
    form.fields.add(MapEntry('meta', jsonEncode(scan.meta)));
    for (final entry in scan.files.entries) {
      final file = File(entry.value);
      if (!await file.exists()) {
        throw FileSystemException('missing crop', entry.value);
      }
      form.files.add(
        MapEntry(
          entry.key,
          await MultipartFile.fromFile(
            entry.value,
            filename: p.basename(entry.value),
          ),
        ),
      );
    }
    return form;
  }

  Future<UploadOutcome> _applyResponse(
    QueuedScan scan,
    Response<Object?> res,
  ) async {
    final status = res.statusCode ?? 0;
    final body = res.data is Map<String, dynamic>
        ? unwrapJson(res.data)
        : const <String, dynamic>{};
    final serverScanId = (body['scan_id'] as num?)?.toInt();

    // A published submission: the server keeps the scan as pending_confirm
    // until the teacher confirms (DESIGN §9.4). This can arrive as 202, or
    // as 200 when a retry replays the original answer. Never mark it done:
    // that would delete the images and hide a rescan that still needs the
    // teacher's decision.
    if (body['state'] == 'pending_confirm' || status == 202) {
      await _repo.markConflict(scan.clientScanId, serverScanId: serverScanId);
      return UploadOutcome.conflict;
    }

    if (status == 200 || status == 201) {
      await _repo.markDone(scan.clientScanId, serverScanId: serverScanId);
      return UploadOutcome.done;
    }

    if (isRetryableStatus(status)) {
      return _scheduleRetry(scan, _retryMessage(status, body));
    }

    // Any other answer is final for this scan: 422 qr_invalid /
    // layout_unknown / page_mismatch, 403 (someone else's assignment),
    // 404, 413 (upload bigger than the server accepts), ... Sending the same
    // bytes again cannot change it.
    await _repo.markFailed(
      scan.clientScanId,
      reason: _rejectionMessage(status, body),
    );
    return UploadOutcome.failed;
  }

  static String _retryMessage(int status, Map<String, dynamic> body) =>
      switch (status) {
        401 => 'ต้องเข้าสู่ระบบใหม่ก่อนอัปโหลด',
        429 => 'เซิร์ฟเวอร์รับงานไม่ทัน จะลองใหม่อัตโนมัติ',
        _ =>
          body['message'] as String? ?? 'เซิร์ฟเวอร์ตอบกลับผิดพลาด ($status)',
      };

  static String _rejectionMessage(int status, Map<String, dynamic> body) {
    final message =
        body['message'] as String? ??
        switch (status) {
          403 => 'ไม่มีสิทธิ์ส่งสแกนนี้ (อาจเป็นการบ้านของครูคนอื่น)',
          404 => 'ไม่พบการบ้านหรือนักเรียนนี้บนเซิร์ฟเวอร์',
          413 => 'ไฟล์ภาพใหญ่เกินที่เซิร์ฟเวอร์รับได้',
          _ => 'เซิร์ฟเวอร์ปฏิเสธสแกนนี้',
        };
    final code = body['code'] as String?;
    return '$message (${code ?? status})';
  }

  Future<UploadOutcome> _scheduleRetry(QueuedScan scan, String error) async {
    await _repo.scheduleRetry(
      scan.clientScanId,
      error: error,
      nextAttemptAt: _clock().add(backoffFor(scan.attempts)),
    );
    return UploadOutcome.retry;
  }
}

final scanUploaderProvider = Provider<ScanUploader>(
  (ref) => ScanUploader(
    dio: ref.watch(dioProvider),
    repository: ref.watch(scanQueueRepositoryProvider),
  ),
);
