import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import 'google_models.dart';
import 'google_providers.dart';

/// Why the teacher must connect again, in Thai: the server's own reason
/// (`reconnect_message`) first, then what the scopes show (DESIGN §19.7),
/// else the expired-grant text.
String reconnectReason(GoogleStatus? status) {
  if (status?.reconnectMessage case final message?) return message;
  if (status != null && status.lacksAnnouncementsScope) {
    return announcementsReconnectMessage;
  }
  return 'สิทธิ์ที่ให้ Google ไว้หมดอายุหรือถูกยกเลิก แอปจึงหยุดซิงก์งาน '
      'งานที่ส่ง และคะแนนกับ Google Classroom จนกว่าจะเชื่อมใหม่ '
      '(ช่วงทดสอบ Google ให้สิทธิ์ได้ครั้งละ 7 วัน)';
}

/// The account was connected before the app asked for the announcements
/// scope (Phase 8 build step 6); the server sends the same text.
const announcementsReconnectMessage =
    'ต้องเชื่อมบัญชี Google ใหม่ เพื่อให้แอปส่งผลตรวจเป็นประกาศส่วนตัวถึงนักเรียนได้';

/// "ต้องเชื่อมบัญชี Google ใหม่" (DESIGN §19.3): the refresh token stopped
/// working (`invalid_grant`, or a scope was taken back), or the account
/// lacks a scope added later (the announcements of §19.7), so the sync
/// skips this teacher until they connect again. With the OAuth app in
/// Testing mode this happens every 7 days. The text says which
/// ([reconnectReason]). Shown on the home page and the Google
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
                        reconnectReason(status),
                        key: const ValueKey('google_reconnect_reason'),
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
