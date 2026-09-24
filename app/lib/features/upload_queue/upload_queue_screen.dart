import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/db/app_database.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'queued_scan.dart';
import 'upload_queue_providers.dart';

String scanStateLabel(ScanState state) => switch (state) {
  ScanState.needsLayout => 'รอ layout',
  ScanState.pending => 'รออัปโหลด',
  ScanState.uploading => 'กำลังอัปโหลด',
  ScanState.done => 'ส่งแล้ว',
  ScanState.conflict => 'รอครูยืนยัน',
  ScanState.failed => 'ถูกปฏิเสธ',
};

Color scanStateColor(BuildContext context, ScanState state) {
  final scheme = Theme.of(context).colorScheme;
  return switch (state) {
    ScanState.done => Colors.green.shade700,
    ScanState.failed => scheme.error,
    ScanState.conflict => Colors.orange.shade800,
    ScanState.uploading => scheme.primary,
    _ => scheme.secondary,
  };
}

/// Lists queued scans with their upload state and the actions a teacher can
/// take on each (DESIGN §6.4, §9.4).
class UploadQueueScreen extends ConsumerWidget {
  const UploadQueueScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final queue = ref.watch(uploadQueueProvider);
    final busy = ref.watch(uploadQueueActionsProvider);
    final actions = ref.read(uploadQueueActionsProvider.notifier);

    return Scaffold(
      appBar: AppBar(
        title: const Text('คิวอัปโหลด'),
        actions: [
          IconButton(
            tooltip: 'ลบรายการที่ส่งแล้ว',
            icon: const Icon(Icons.cleaning_services_outlined),
            onPressed: () => actions.clearDone(),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: busy
            ? null
            : () async {
                final result = await actions.uploadNow();
                if (!context.mounted) return;
                showMessage(
                  context,
                  'ส่งแล้ว ${result.done} รายการ'
                  '${result.conflict > 0 ? ' รอยืนยัน ${result.conflict}' : ''}'
                  '${result.failed > 0 ? ' ถูกปฏิเสธ ${result.failed}' : ''}'
                  '${result.retry > 0 ? ' จะลองใหม่ ${result.retry}' : ''}',
                );
              },
        icon: busy
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.cloud_upload_outlined),
        label: const Text('อัปโหลดตอนนี้'),
      ),
      body: AsyncView(
        value: queue,
        data: (scans) {
          if (scans.isEmpty) {
            return const EmptyView(
              icon: Icons.cloud_done_outlined,
              title: 'ไม่มีงานค้างในคิว',
              message: 'ใบงานที่สแกนแล้วจะรออยู่ที่นี่จนกว่าจะอัปโหลดสำเร็จ',
            );
          }
          return ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
            child: ListView.separated(
              itemCount: scans.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) => _ScanTile(scan: scans[i]),
            ),
          );
        },
      ),
    );
  }
}

class _ScanTile extends ConsumerWidget {
  const _ScanTile({required this.scan});

  final QueuedScan scan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final qr = scan.qr;
    final title = qr == null
        ? 'สแกน ${scan.clientScanId.substring(0, 8)}'
        : 'การบ้าน #${qr.assignmentId} นักเรียน #${qr.studentId} หน้า ${qr.page}';
    final actions = ref.read(uploadQueueActionsProvider.notifier);

    Future<void> run(Future<void> Function() f, String okMessage) async {
      try {
        await f();
        if (context.mounted) showMessage(context, okMessage);
      } catch (e) {
        if (context.mounted) showMessage(context, apiErrorMessage(e));
      }
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                StatusChip(
                  label: scanStateLabel(scan.state),
                  color: scanStateColor(context, scan.state),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'สแกนเมื่อ ${formatThaiDateTime(scan.createdAt)}'
              '${scan.attempts > 0 ? ' · ลองแล้ว ${scan.attempts} ครั้ง' : ''}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (scan.lastError case final err?) ...[
              const SizedBox(height: 4),
              Text(
                err,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            if (scan.state == ScanState.conflict)
              const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text(
                  'ผลของนักเรียนคนนี้เผยแพร่ไปแล้ว ยืนยันเพื่อแทนที่ด้วยสแกนใหม่และตรวจซ้ำ',
                ),
              ),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (scan.state == ScanState.conflict)
                  TextButton.icon(
                    onPressed: () => run(
                      () => actions.confirmReplace(scan),
                      'ยืนยันแทนที่แล้ว',
                    ),
                    icon: const Icon(Icons.published_with_changes),
                    label: const Text('ยืนยันแทนที่'),
                  ),
                if (scan.state == ScanState.failed ||
                    scan.state == ScanState.pending)
                  TextButton.icon(
                    onPressed: () =>
                        run(() => actions.retry(scan), 'เริ่มอัปโหลดใหม่'),
                    icon: const Icon(Icons.refresh),
                    label: const Text('ลองใหม่'),
                  ),
                if (scan.state != ScanState.uploading)
                  TextButton.icon(
                    onPressed: () async {
                      final ok = await confirm(
                        context,
                        title: 'ลบสแกนนี้?',
                        message: scan.state == ScanState.done
                            ? 'ลบออกจากรายการในเครื่อง (ภาพส่งไปแล้ว)'
                            : 'ภาพที่สแกนไว้จะถูกลบและไม่ถูกส่งขึ้นเซิร์ฟเวอร์',
                        confirmLabel: 'ลบ',
                        destructive: true,
                      );
                      if (ok) await run(() => actions.discard(scan), 'ลบแล้ว');
                    },
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('ลบ'),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
