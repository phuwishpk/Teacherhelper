import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import 'google_providers.dart';

/// "ต้องเชื่อมบัญชี Google ใหม่" (DESIGN §19.3): the refresh token stopped
/// working (`invalid_grant`, or a scope was taken back), so the sync skips
/// this teacher until they connect again. With the OAuth app in Testing
/// mode this happens every 7 days. Shown on the home page and the Google
/// Classroom screens; renders nothing while the connection works.
///
/// [needsReconnect] lets a caller that already knows (the home card's
/// `GET /teacher/attention`) show it before the status arrives.
class GoogleReconnectBanner extends ConsumerWidget {
  const GoogleReconnectBanner({super.key, this.needsReconnect = false});

  final bool needsReconnect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(googleClassroomEnabledProvider)) {
      return const SizedBox.shrink();
    }
    final status = ref.watch(googleStatusProvider).value;
    // A fresh status wins over the caller's count (the teacher may have
    // just connected again in settings).
    final show = status != null
        ? status.connected && status.needsReconnect
        : needsReconnect;
    if (!show) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    return Card(
      key: const ValueKey('google_reconnect_banner'),
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.link_off, color: scheme.onErrorContainer),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'ต้องเชื่อมบัญชี Google ใหม่',
                        style: TextStyle(
                          color: scheme.onErrorContainer,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'สิทธิ์ที่ให้ Google ไว้หมดอายุหรือถูกยกเลิก แอปจึงหยุดซิงก์งาน '
                        'งานที่ส่ง และคะแนนกับ Google Classroom จนกว่าจะเชื่อมใหม่ '
                        '(ช่วงทดสอบ Google ให้สิทธิ์ได้ครั้งละ 7 วัน)',
                        style: TextStyle(color: scheme.onErrorContainer),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                key: const ValueKey('google_reconnect_open'),
                style: TextButton.styleFrom(
                  foregroundColor: scheme.onErrorContainer,
                ),
                onPressed: () => context.push(AppRoutes.settings),
                child: const Text('เชื่อมใหม่'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
