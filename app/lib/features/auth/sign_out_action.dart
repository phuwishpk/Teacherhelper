import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/local_user_data.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/content_column.dart';

/// Sign-out button handler for both shells. Signing out deletes the local
/// scan queue, so when scans are still unsent the teacher confirms first.
Future<void> confirmSignOut(BuildContext context, WidgetRef ref) async {
  var unsent = 0;
  try {
    unsent = await ref.read(localUserDataProvider).unsentScanCount();
  } catch (_) {
    // No local database (web preview): nothing can be lost.
  }
  if (unsent > 0) {
    if (!context.mounted) return;
    final ok = await confirm(
      context,
      title: 'ยังมีสแกนที่ยังไม่ได้ส่ง $unsent รายการ',
      message:
          'ถ้าออกจากระบบตอนนี้ ภาพที่สแกนไว้จะถูกลบจากเครื่องนี้และไม่ถูกส่งขึ้นเซิร์ฟเวอร์ '
          'ควรเปิดคิวอัปโหลดแล้วส่งให้เสร็จก่อน',
      confirmLabel: 'ลบและออกจากระบบ',
      destructive: true,
    );
    if (!ok) return;
  }
  await ref.read(sessionProvider.notifier).signOut();
}
