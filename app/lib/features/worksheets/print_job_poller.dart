import 'dart:async';

import 'print_job.dart';

class PrintJobTimeout implements Exception {
  const PrintJobTimeout();

  @override
  String toString() => 'สร้าง PDF ใช้เวลานานเกินไป ลองดูสถานะใหม่ภายหลัง';
}

class PrintJobFailed implements Exception {
  const PrintJobFailed(this.job);

  final PrintJob job;

  @override
  String toString() => job.error ?? 'สร้าง PDF ไม่สำเร็จ';
}

/// Polls [fetch] until the job is ready. The queue worker on shared hosting
/// runs once a minute (CLAUDE.md), so the default window is generous.
Future<PrintJob> waitForPrintJob(
  Future<PrintJob> Function() fetch, {
  Duration interval = const Duration(seconds: 3),
  Duration timeout = const Duration(minutes: 5),
  void Function(PrintJob job)? onUpdate,
  Future<void> Function(Duration) delay = Future.delayed,
}) async {
  final deadline = DateTime.now().add(timeout);
  while (true) {
    final job = await fetch();
    onUpdate?.call(job);
    if (job.isReady) return job;
    if (job.isFailed) throw PrintJobFailed(job);
    if (DateTime.now().isAfter(deadline)) throw const PrintJobTimeout();
    await delay(interval);
  }
}
