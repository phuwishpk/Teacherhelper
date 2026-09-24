import 'dart:convert';

import '../../core/db/app_database.dart';

/// Parsed form of a worksheet QR payload `EV1.{assignment}.{student}.{page}.{layout_version}.{sig}`.
class WorksheetQr {
  const WorksheetQr({
    required this.assignmentId,
    required this.studentId,
    required this.page,
    required this.layoutVersion,
    required this.signature,
  });

  final int assignmentId;
  final int studentId;
  final int page;
  final int layoutVersion;
  final String signature;

  static WorksheetQr? tryParse(String? payload) {
    if (payload == null) return null;
    final parts = payload.split('.');
    if (parts.length != 6 || parts[0] != 'EV1') return null;
    final nums = parts.sublist(1, 5).map(int.tryParse).toList();
    if (nums.any((n) => n == null)) return null;
    return WorksheetQr(
      assignmentId: nums[0]!,
      studentId: nums[1]!,
      page: nums[2]!,
      layoutVersion: nums[3]!,
      signature: parts[5],
    );
  }
}

/// A row of `scan_queue` with its JSON columns decoded.
class QueuedScan {
  QueuedScan({
    required this.clientScanId,
    required this.state,
    required this.meta,
    required this.files,
    required this.attempts,
    required this.lastError,
    required this.serverScanId,
    required this.nextAttemptAt,
    required this.createdAt,
    required this.updatedAt,
  });

  factory QueuedScan.fromRow(ScanQueueData row) => QueuedScan(
    clientScanId: row.clientScanId,
    state: row.state,
    meta: (jsonDecode(row.metaJson) as Map).cast<String, dynamic>(),
    files: (jsonDecode(row.filesJson) as Map).cast<String, String>(),
    attempts: row.attempts,
    lastError: row.lastError,
    serverScanId: row.serverScanId,
    nextAttemptAt: row.nextAttemptAt,
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
  );

  final String clientScanId;
  final ScanState state;

  /// The `meta` object of DESIGN §9.4.
  final Map<String, dynamic> meta;

  /// Multipart field name -> local file path.
  final Map<String, String> files;
  final int attempts;
  final String? lastError;
  final int? serverScanId;
  final DateTime? nextAttemptAt;
  final DateTime createdAt;
  final DateTime updatedAt;

  String? get qrPayload => meta['qr'] as String?;
  WorksheetQr? get qr => WorksheetQr.tryParse(qrPayload);

  bool get isTerminal => state == ScanState.done || state == ScanState.failed;
}
