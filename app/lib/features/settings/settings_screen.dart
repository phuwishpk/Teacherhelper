import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/push/push_messaging.dart';
import '../google_classroom/google_classroom_card.dart';
import '../google_signin/google_identity_card.dart';
import 'ai_key_section.dart';
import 'profile_section.dart';

/// Teacher settings: the own profile (§29.1), the Google account for sign-in (DESIGN §24.9.5), the
/// Gemini key (§10.1) and the Google Classroom connection (§18.7).
class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final push = ref.watch(pushMessagingProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('ตั้งค่า')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 640),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const ProfileSection(),
                  const SizedBox(height: 8),
                  const GoogleIdentityCard(),
                  const SizedBox(height: 8),
                  const AiKeySection(),
                  const SizedBox(height: 8),
                  const GoogleClassroomCard(),
                  const SizedBox(height: 8),
                  Card(
                    child: ListTile(
                      leading: Icon(
                        push.enabled
                            ? Icons.notifications_active_outlined
                            : Icons.notifications_off_outlined,
                      ),
                      title: const Text('การแจ้งเตือน'),
                      subtitle: Text(
                        push.enabled
                            ? 'เปิดใช้แล้ว แจ้งเมื่อตรวจเสร็จและเมื่อมีคำขอให้ตรวจใหม่'
                            : 'แอปรุ่นนี้ยังไม่ได้ตั้งค่า Firebase จึงไม่มีการแจ้งเตือน',
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
