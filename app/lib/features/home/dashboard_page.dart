import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/user.dart';
import '../../core/router/app_router.dart';
import '../assignments/assignments_providers.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import '../dashboard/teacher_overview.dart';
import '../google_classroom/google_reconnect_banner.dart';
import '../review/review_providers.dart';
import 'ai_key_card.dart';
import 'teacher_attention.dart';

/// Teacher landing page: greeting, the Gemini API key card (DESIGN §10.1),
/// the Google reconnect banner and the "รอดำเนินการ" card (§19.11), live
/// overview counts (classrooms, assignments, answers waiting for
/// review, open appeals, practice drafts), shortcuts to the Phase 6
/// dashboards (§14.3) and the getting-started steps of DESIGN §4.
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
    final classrooms = ref.watch(classroomsProvider);
    final assignments = ref.watch(assignmentsProvider);
    final awaitingReview = ref.watch(awaitingReviewCountProvider);
    final appeals = ref.watch(openAppealsProvider);
    final drafts = ref.watch(draftPracticeCountProvider);
    final studentCount = classrooms.value?.fold<int?>(
      0,
      (sum, c) =>
          sum == null || c.studentCount == null ? null : sum + c.studentCount!,
    );
    final openAssignments = assignments.value
        ?.where((a) => !a.isDraft && a.status != 'closed')
        .length;
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
            const SizedBox(height: 12),
            GoogleReconnectBanner(
              needsReconnect:
                  ref.watch(teacherAttentionProvider).value?.needsReconnect ??
                  false,
            ),
            AiKeyCard(onOpenSettings: () => context.push(AppRoutes.settings)),
            const SizedBox(height: 12),
            TeacherAttentionCard(
              onOpen: (target) => onNavigate(switch (target) {
                AttentionTarget.assignments => 2,
                AttentionTarget.review => 3,
              }),
            ),
            const SizedBox(height: 12),
            Card(
              child: ListTile(
                key: const ValueKey('dashboard_teacher_upload'),
                leading: const Icon(Icons.upload_file),
                title: const Text('อัปโหลดรูปเพื่อตรวจ'),
                subtitle: const Text(
                  'เลือกวิชา การบ้าน และนักเรียน แล้วแนบรูปหรือ PDF ของงาน',
                ),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(AppRoutes.teacherUpload),
              ),
            ),
            const SizedBox(height: 12),
            Card(
              child: ListTile(
                key: const ValueKey('dashboard_courses'),
                leading: const Icon(Icons.menu_book_outlined),
                title: const Text('รายวิชาและแผนการสอน'),
                subtitle: const Text(
                  'สร้างรายวิชาจากฟอร์มหรือเอกสาร ผูกกับห้อง แล้วเพิ่มหน่วย แผน และตัวชี้วัด',
                ),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(AppRoutes.courses),
              ),
            ),
            const SizedBox(height: 24),
            Text('ภาพรวม', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            LayoutBuilder(
              builder: (context, box) {
                final columns = box.maxWidth >= 600 ? 5 : 3;
                const gap = 8.0;
                final width = (box.maxWidth - gap * (columns - 1)) / columns;
                return Wrap(
                  spacing: gap,
                  runSpacing: gap,
                  children: [
                    for (final tile in [
                      _StatTile(
                        key: const ValueKey('stat_classrooms'),
                        icon: Icons.groups_outlined,
                        label: 'ห้องเรียน',
                        value: classrooms.whenData((l) => l.length),
                        detail: studentCount == null
                            ? null
                            : 'นักเรียน $studentCount คน',
                        onTap: () => onNavigate(1),
                      ),
                      _StatTile(
                        key: const ValueKey('stat_assignments'),
                        icon: Icons.assignment_outlined,
                        label: 'การบ้าน',
                        value: assignments.whenData((l) => l.length),
                        detail: openAssignments == null
                            ? null
                            : 'เปิดอยู่ $openAssignments',
                        onTap: () => onNavigate(2),
                      ),
                      _StatTile(
                        key: const ValueKey('stat_review'),
                        icon: Icons.rate_review_outlined,
                        label: 'ข้อรอตรวจทาน',
                        value: awaitingReview,
                        onTap: () => onNavigate(3),
                      ),
                      _StatTile(
                        key: const ValueKey('stat_appeals'),
                        icon: Icons.feedback_outlined,
                        label: 'คำขอตรวจใหม่',
                        value: appeals.whenData((l) => l.length),
                        onTap: () => context.push(AppRoutes.appeals),
                      ),
                      if (drafts.value != null || drafts.isLoading)
                        _StatTile(
                          key: const ValueKey('stat_practice_drafts'),
                          icon: Icons.fitness_center_outlined,
                          label: 'ข้อฝึกรออนุมัติ',
                          value: drafts.whenData((n) => n ?? 0),
                          onTap: () => context.push(AppRoutes.practiceBank),
                        ),
                    ])
                      SizedBox(width: width, child: tile),
                  ],
                );
              },
            ),
            const SizedBox(height: 24),
            Text('ติดตามผลการเรียน', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Card(
              clipBehavior: Clip.antiAlias,
              child: Column(
                children: [
                  ListTile(
                    leading: const Icon(Icons.fitness_center_outlined),
                    title: const Text('คลังแบบฝึกและลิงก์ทบทวน'),
                    subtitle: const Text(
                      'ให้ AI ร่างข้อฝึกตามทักษะ ตรวจแล้วอนุมัติให้นักเรียนใช้',
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push(AppRoutes.practiceBank),
                  ),
                  for (final c in classrooms.value ?? const <Classroom>[]) ...[
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.grid_on_outlined),
                      title: Text('ทักษะของห้อง ${c.name}'),
                      subtitle: const Text(
                        'heatmap นักเรียน × ทักษะ และจุดอ่อนรายคน',
                      ),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () =>
                          context.push(AppRoutes.classroomMastery(c.id)),
                    ),
                  ],
                ],
              ),
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
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
    this.detail,
  });

  final IconData icon;
  final String label;
  final AsyncValue<int> value;
  final String? detail;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final text = switch (value) {
      AsyncData(:final value) => '$value',
      AsyncError() => '–',
      _ => '…',
    };
    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
          child: Column(
            children: [
              Icon(icon, color: theme.colorScheme.primary),
              const SizedBox(height: 6),
              Text(
                text,
                style: theme.textTheme.headlineMedium?.copyWith(
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
              Text(
                label,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                textAlign: TextAlign.center,
                maxLines: 2,
              ),
              if (detail != null)
                Text(
                  detail!,
                  style: muted,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
            ],
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
