import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/session.dart';
import 'live_connection.dart';

/// "ข้อมูลสด" on the teacher settings and the student account screen
/// (DESIGN §38.8): whether the in-app inbox updates live, why not, and a
/// button to connect again.
class LiveStatusCard extends ConsumerWidget {
  const LiveStatusCard({super.key});

  static String describe(LiveStatus status) => switch (status) {
    LiveStatus.off =>
      'แอปรุ่นนี้ไม่ได้ตั้งค่า Firebase กล่องแจ้งเตือนในแอปจึงปิดอยู่',
    LiveStatus.connecting => 'กำลังเชื่อมต่อ…',
    LiveStatus.connected =>
      'เชื่อมต่อแล้ว กล่องแจ้งเตือนในแอปอัปเดตทันทีโดยไม่ต้องรีเฟรช',
    LiveStatus.serverOff =>
      'เซิร์ฟเวอร์ยังไม่ได้เปิดข้อมูลสด (Firestore) ระบบอื่นใช้งานได้ตามปกติ',
    LiveStatus.failed => 'เชื่อมต่อไม่สำเร็จ ตรวจอินเทอร์เน็ตแล้วลองอีกครั้ง',
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    if (user == null) return const SizedBox.shrink();
    final status = ref.watch(liveStatusProvider(user.id));
    final canRetry =
        status == LiveStatus.serverOff || status == LiveStatus.failed;
    return Card(
      key: const ValueKey('live_status_card'),
      child: ListTile(
        leading: Icon(
          status == LiveStatus.connected
              ? Icons.cloud_done_outlined
              : Icons.cloud_off_outlined,
        ),
        title: const Text('ข้อมูลสด (Firestore)'),
        subtitle: Text(
          describe(status),
          key: ValueKey('live_status_${status.name}'),
        ),
        trailing: canRetry
            ? TextButton(
                key: const ValueKey('live_status_retry'),
                onPressed: () =>
                    ref.read(liveConnectionProvider.notifier).connect(user.id),
                child: const Text('ลองอีกครั้ง'),
              )
            : null,
      ),
    );
  }
}
