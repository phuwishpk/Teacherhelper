import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/user.dart';
import '../../core/router/app_router.dart';
import '../assignments/assignments_providers.dart';
import '../classrooms/classrooms_providers.dart';

/// Teacher landing page: greeting, overview counts and the getting-started
/// steps that mirror the main flow in DESIGN §4. The review count stays 0
/// until Phase 4 adds the review queue.
class DashboardPage extends ConsumerWidget {
  const DashboardPage({
    super.key,
    required this.user,
    required this.onNavigate,
  });

  final User? user;

  /// Switches the shell to the given destination index.
  final ValueChanged<int> onNavigate;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final classroomCount = ref.watch(classroomsProvider).value?.length ?? 0;
    final assignmentCount = ref.watch(assignmentsProvider).value?.length ?? 0;
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Card(
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 20,
                  vertical: 12,
                ),
                leading: CircleAvatar(
                  child: Text(
                    user?.name.isNotEmpty == true ? user!.name[0] : '?',
                  ),
                ),
                title: Text(
                  'สวัสดี คุณครู ${user?.name ?? ''}',
                  style: theme.textTheme.titleLarge,
                ),
                subtitle: Text(user?.schoolName ?? 'ยังไม่ระบุโรงเรียน'),
              ),
            ),
            const SizedBox(height: 24),
            Text('ภาพรวม', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Row(
              children: [
                _StatTile(
                  icon: Icons.groups_outlined,
                  label: 'ห้องเรียน',
                  value: classroomCount,
                  onTap: () => onNavigate(1),
                ),
                const SizedBox(width: 12),
                _StatTile(
                  icon: Icons.assignment_outlined,
                  label: 'การบ้าน',
                  value: assignmentCount,
                  onTap: () => onNavigate(2),
                ),
                const SizedBox(width: 12),
                _StatTile(
                  icon: Icons.rate_review_outlined,
                  label: 'รอตรวจทาน',
                  value: 0,
                  onTap: () => onNavigate(3),
                ),
              ],
            ),
            const SizedBox(height: 24),
            Text('เริ่มต้นใช้งาน', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Card(
              clipBehavior: Clip.antiAlias,
              child: Column(
                children: [
                  _StepTile(
                    number: 1,
                    title: 'สร้างห้องเรียนและเพิ่มนักเรียน',
                    subtitle: 'พิมพ์บัตร QR ให้นักเรียนใช้เข้าสู่ระบบ',
                    onTap: () => onNavigate(1),
                  ),
                  const Divider(height: 1),
                  _StepTile(
                    number: 2,
                    title: 'สร้างการบ้านและพิมพ์ใบงาน',
                    subtitle:
                        'เลือกตัวชี้วัด ใส่เฉลยหรือ rubric แล้วพิมพ์แยกรายคน',
                    onTap: () => onNavigate(2),
                  ),
                  const Divider(height: 1),
                  _StepTile(
                    number: 3,
                    title: 'สแกนใบงานที่นักเรียนทำแล้ว',
                    subtitle: 'ถ่ายด้วยกล้อง ทำได้แม้ไม่มีอินเทอร์เน็ต',
                    onTap: () => context.push(AppRoutes.scan),
                  ),
                  const Divider(height: 1),
                  _StepTile(
                    number: 4,
                    title: 'ตรวจทานคะแนนแล้วเผยแพร่',
                    subtitle:
                        'AI ตรวจให้ก่อน คุณเป็นคนตัดสินใจก่อนนักเรียนเห็นผล',
                    onTap: () => onNavigate(3),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final int value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Expanded(
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
            child: Column(
              children: [
                Icon(icon, color: theme.colorScheme.primary),
                const SizedBox(height: 8),
                Text('$value', style: theme.textTheme.headlineMedium),
                Text(
                  label,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _StepTile extends StatelessWidget {
  const _StepTile({
    required this.number,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final int number;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: CircleAvatar(radius: 16, child: Text('$number')),
      title: Text(title),
      subtitle: Text(subtitle),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
}
