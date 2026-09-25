import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../../core/push/push_messaging.dart';
import 'ai_key_section.dart';

/// Teacher settings (DESIGN §10.1). The Google Classroom card of §18.7
/// joins this page in Phase 7.
class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
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
                  if (user != null)
                    Card(
                      child: ListTile(
                        leading: const Icon(Icons.person_outline),
                        title: Text(user.name),
                        subtitle: Text(
                          [?user.email, ?user.schoolName].join(' · '),
                        ),
                      ),
                    ),
                  const SizedBox(height: 8),
                  const AiKeySection(),
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
