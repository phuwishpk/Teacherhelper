import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'pdf_files.dart';
import 'print_job.dart';
import 'print_job_poller.dart';

/// Shared "queue a PDF → wait → download → open, save or share" flow used
/// by the login-card and worksheet buttons. Shows a progress dialog
/// meanwhile. Works on the web too: the file is kept in memory there and
/// offered as a browser download (DESIGN §25.2).
Future<void> runPrintFlow(
  BuildContext context,
  WidgetRef ref, {
  required String title,
  required String fileName,
  required Future<PrintJob> Function() request,
  required Future<PrintJob> Function(PrintJob job) poll,
}) async {
  final status = ValueNotifier<String>('กำลังส่งคำขอ…');
  final navigator = Navigator.of(context, rootNavigator: true);
  var dialogOpen = true;
  showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (_) => PopScope(
      canPop: false,
      child: AlertDialog(
        title: Text(title),
        content: ValueListenableBuilder<String>(
          valueListenable: status,
          builder: (_, text, _) => Row(
            children: [
              const SizedBox(
                width: 24,
                height: 24,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
              const SizedBox(width: 16),
              Expanded(child: Text(text)),
            ],
          ),
        ),
      ),
    ),
  ).whenComplete(() => dialogOpen = false);

  try {
    var job = await request();
    if (!job.isReady) {
      status.value = printStatusLabel(job.status);
      job = await waitForPrintJob(
        () => poll(job),
        onUpdate: (j) => status.value = printStatusLabel(j.status),
      );
    }
    status.value = 'กำลังดาวน์โหลด…';
    final files = ref.read(pdfFilesProvider);
    final file = await files.fetch(job.downloadUrl!, fileName: fileName);
    if (dialogOpen) navigator.pop();
    if (!context.mounted) return;
    await showPdfFileActions(context, files: files, file: file, title: title);
  } catch (e) {
    if (dialogOpen) navigator.pop();
    if (!context.mounted) return;
    final message = switch (e) {
      PrintJobFailed() || PrintJobTimeout() => e.toString(),
      _ => apiErrorMessage(e),
    };
    showMessage(context, message);
  } finally {
    status.dispose();
  }
}
