import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../upload_queue/upload_queue_providers.dart';

/// Entry point for scanning worksheets. The camera + native pipeline
/// (Pigeon/Kotlin, DESIGN §6.2) is implemented in step A2; this route keeps
/// the navigation and the upload queue wired up in the meantime.
class ScanScreen extends ConsumerWidget {
  const ScanScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final open = ref.watch(uploadQueueOpenCountProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('สแกนใบงาน')),
      body: EmptyView(
        icon: Icons.document_scanner_outlined,
        title: 'กล้องสแกนยังไม่เปิดใช้',
        message:
            'หน้าสแกนด้วยกล้อง (หา marker, อ่าน QR, crop ตาม layout) จะเปิดใช้ในขั้นถัดไป '
            'ระหว่างนี้ใช้ "เตรียมสแกนออฟไลน์" ในหน้าห้องเรียนเพื่อดาวน์โหลด layout และรายชื่อไว้ก่อนได้',
        action: FilledButton.tonalIcon(
          onPressed: () => context.push(AppRoutes.uploadQueue),
          icon: const Icon(Icons.cloud_upload_outlined),
          label: Text(open > 0 ? 'คิวอัปโหลด ($open)' : 'คิวอัปโหลด'),
        ),
      ),
    );
  }
}
