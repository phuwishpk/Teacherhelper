import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/breakpoints.dart';
import '../../core/util/thai_date.dart';
import '../onboarding/onboarding_store.dart';
import 'server_status_card.dart';

String _formatDeviceName(String device) => switch (device.toLowerCase()) {
      'android' => 'มือถือ Android',
      'ios' => 'iPhone/iPad',
      'web' => 'เว็บเบราว์เซอร์',
      _ => device,
    };

class AboutScreen extends ConsumerWidget {
  const AboutScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final textTheme = theme.textTheme;
    final infoAsync = ref.watch(onboardingInfoProvider);
    final info = infoAsync.value;

    return Scaffold(
      appBar: AppBar(
        title: const Text('เกี่ยวกับ Krucheck'),
      ),
      body: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 720),
          child: ListView(
            padding: EdgeInsets.all(context.pageGutter),
            children: [
              const ServerStatusCard(),
              const SizedBox(height: 24),
              Text(
                'Krucheck',
                style: textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'ระบบตรวจการบ้าน ข้อสอบ และผู้ช่วยจัดการชั้นเรียนอัจฉริยะ สำหรับครูและนักเรียน',
                style: textTheme.bodyLarge,
              ),
              if (info != null) ...[
                const SizedBox(height: 8),
                Text(
                  'คุณดูวิธีใช้งานครั้งแรกเมื่อ ${formatThaiDateTime(info.at.toLocal())} จาก${_formatDeviceName(info.device)}',
                  style: textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
              const SizedBox(height: 24),
              Text(
                'ทีมผู้พัฒนา',
                style: textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 8),
              Card(
                margin: EdgeInsets.zero,
                child: Column(
                  children: const [
                    ListTile(
                      leading: CircleAvatar(child: Icon(Icons.person_outline)),
                      title: Text('ภูวิชญ์ เกตุแก้ว'),
                      subtitle: Text('สถาปัตยกรรมระบบ, Backend & DevOps (phuwishpk)'),
                    ),
                    Divider(height: 1),
                    ListTile(
                      leading: CircleAvatar(child: Icon(Icons.person_outline)),
                      title: Text('กรณ์ แดงทอง'),
                      subtitle: Text('Mobile App & AI Assistant Mascot (korn-daengthong)'),
                    ),
                    Divider(height: 1),
                    ListTile(
                      leading: CircleAvatar(child: Icon(Icons.person_outline)),
                      title: Text('สุวิมล ศรีประไหม'),
                      subtitle: Text('Live Class Feed & Classroom Sync (Suwimon249)'),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
              Text(
                'รายวิชา',
                style: textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 8),
              Card(
                margin: EdgeInsets.zero,
                child: const ListTile(
                  leading: Icon(Icons.school_outlined),
                  title: Text('03376132 & 03376133 โครงงานวิศวกรรมคอมพิวเตอร์'),
                  subtitle: Text(
                    'ภาควิชาวิศวกรรมคอมพิวเตอร์ คณะวิศวกรรมศาสตร์\nสถาบันเทคโนโลยีพระจอมเกล้าเจ้าคุณทหารลาดกระบัง (KMITL)',
                  ),
                ),
              ),
              const SizedBox(height: 24),
              Text(
                'เทคโนโลยี',
                style: textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: const [
                  Chip(label: Text('Flutter')),
                  Chip(label: Text('Laravel')),
                  Chip(label: Text('MariaDB')),
                  Chip(label: Text('Firestore')),
                  Chip(label: Text('Gemini')),
                  Chip(label: Text('Google Classroom API')),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

