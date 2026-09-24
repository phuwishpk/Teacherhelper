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

/// Delay before attempt number [attempts] + 1 (30 s, 1 min, 2 min … 1 h).
Duration backoffFor(int attempts) {
  const base = Duration(seconds: 30);
  const cap = Duration(hours: 1);
  final factor = pow(2, min(attempts, 12)).toInt();
  final d = base * factor;
  return d > cap ? cap : d;
}

enum UploadOutcome { done, conflict, failed, retry }

class DrainResult {
  const DrainResult({
    required this.done,
    required this.conflict,
    required this.failed,
    required this.retry,
  });

  final int done;
  final int conflict;
  final int failed;
  final int retry;

  /// True when nothing is left that a later attempt could still fix.
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
    var done = 0, conflict = 0, failed = 0, retry = 0;
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
      }
    }
    return DrainResult(
      done: done,
      conflict: conflict,
      failed: failed,
      retry: retry,
    );
  }

  Future<UploadOutcome> uploadOne(QueuedScan scan) async {
    await _repo.markUploading(scan.clientScanId);
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

    switch (status) {
      case 200:
      case 201:
        if (body['state'] == 'pending_confirm' && serverScanId != null) {
          await _repo.markConflict(
            scan.clientScanId,
            serverScanId: serverScanId,
          );
          return UploadOutcome.conflict;
        }
        await _repo.markDone(scan.clientScanId, serverScanId: serverScanId);
        return UploadOutcome.done;
      case 202:
        if (serverScanId == null) {
          return _scheduleRetry(scan, 'เซิร์ฟเวอร์ตอบ 202 โดยไม่มี scan_id');
        }
        await _repo.markConflict(scan.clientScanId, serverScanId: serverScanId);
        return UploadOutcome.conflict;
      case 422:
        final code = body['code'] as String?;
        final message = body['message'] as String? ?? 'ถูกปฏิเสธ';
        await _repo.markFailed(
          scan.clientScanId,
          reason: code == null ? message : '$message ($code)',
        );
        return UploadOutcome.failed;
      case 401:
        return _scheduleRetry(scan, 'ต้องเข้าสู่ระบบใหม่ก่อนอัปโหลด');
      default:
        return _scheduleRetry(
          scan,
          body['message'] as String? ?? 'เซิร์ฟเวอร์ตอบกลับผิดพลาด ($status)',
        );
    }
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
