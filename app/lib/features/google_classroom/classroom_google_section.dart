import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import 'google_config.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// Explains the grade-return limit of the Classroom API (DESIGN §18.2);
/// shown when linking a course and when posting.
const gradeReturnNote =
    'ส่งคะแนนกลับ Classroom ได้เฉพาะงานที่สั่งผ่านปุ่ม "โพสต์ลง Classroom" ในแอป '
    'งานที่สร้างในเว็บ Classroom เองดึงรูปมาสแกนได้ แต่ส่งคะแนนกลับไม่ได้';

/// "Google Classroom" card on the classroom detail (DESIGN §18.7): link a
/// course, match students, unlink. Hidden without GOOGLE_SERVER_CLIENT_ID.
class ClassroomGoogleSection extends ConsumerStatefulWidget {
  const ClassroomGoogleSection({super.key, required this.classroom});

  final Classroom classroom;

  @override
  ConsumerState<ClassroomGoogleSection> createState() =>
      _ClassroomGoogleSectionState();
}

class _ClassroomGoogleSectionState
    extends ConsumerState<ClassroomGoogleSection> {
  bool _busy = false;

  Future<void> _unlink(ClassroomGoogleLink link) async {
    final ok = await confirm(
      context,
      title: 'เลิกผูกกับ ${link.courseName}?',
      message:
          'การบ้านที่โพสต์ไปแล้วยังอยู่ใน Classroom แต่แอปจะดึงงานที่ส่งและส่งคะแนนกลับไม่ได้ '
          'จนกว่าจะผูกใหม่ การจับคู่นักเรียนจะถูกลบ',
      confirmLabel: 'เลิกผูก',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(googleClassroomRepositoryProvider)
          .unlink(widget.classroom.id);
      ref
          .read(classroomsProvider.notifier)
          .setGoogleLink(widget.classroom.id, null);
      if (mounted) showMessage(context, 'เลิกผูกกับ Google Classroom แล้ว');
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(googleClassroomEnabledProvider)) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    final statusValue = ref.watch(googleStatusProvider);
    final status = statusValue.value;
    final link = widget.classroom.googleLink;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Card(
      key: const ValueKey('classroom_google_section'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.school_outlined, color: theme.colorScheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Google Classroom',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (link != null)
                  StatusChip(label: 'ผูกแล้ว', color: Colors.green.shade700),
              ],
            ),
            const SizedBox(height: 8),
            if (link != null) ...[
              Text('ผูกกับคอร์ส: ${link.courseName}'),
              const SizedBox(height: 4),
              Text(
                'จับคู่บัญชี Google ของนักเรียนกับเลขที่ในห้อง เพื่อให้รู้ว่างานที่ส่งมาเป็นของใคร '
                'และส่งคะแนนกลับได้',
                style: muted,
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  FilledButton.tonalIcon(
                    onPressed: _busy
                        ? null
                        : () => context.push(
                            AppRoutes.classroomGoogleRoster(
                              widget.classroom.id,
                            ),
                          ),
                    icon: const Icon(Icons.people_alt_outlined),
                    label: const Text('จับคู่นักเรียน'),
                  ),
                  OutlinedButton.icon(
                    onPressed: _busy ? null : () => _unlink(link),
                    icon: const Icon(Icons.link_off),
                    label: const Text('เลิกผูก'),
                  ),
                ],
              ),
            ] else ...[
              const Text(
                'ผูกห้องนี้กับคอร์สใน Google Classroom เพื่อโพสต์การบ้าน '
                'ดึงรูปที่นักเรียนส่งมาสแกน และส่งคะแนนกลับ',
              ),
              const SizedBox(height: 4),
              Text(gradeReturnNote, style: muted),
              const SizedBox(height: 12),
              if (status == null && statusValue.hasError)
                Row(
                  children: [
                    const Expanded(
                      child: Text('ตรวจสอบการเชื่อมบัญชี Google ไม่ได้'),
                    ),
                    TextButton(
                      onPressed: () => ref.invalidate(googleStatusProvider),
                      child: const Text('ลองใหม่'),
                    ),
                  ],
                )
              else if (status != null && !status.ready) ...[
                Text(
                  status.connected
                      ? 'ต้องเชื่อมบัญชี Google ใหม่ก่อน'
                      : 'เชื่อมบัญชี Google ที่หน้าตั้งค่าก่อน',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
                const SizedBox(height: 8),
                FilledButton.tonalIcon(
                  onPressed: () => context.push(AppRoutes.settings),
                  icon: const Icon(Icons.settings_outlined),
                  label: const Text('ไปที่ตั้งค่า'),
                ),
              ] else
                FilledButton.tonalIcon(
                  key: const ValueKey('google_link_course'),
                  onPressed: status == null
                      ? null
                      : () => context.push(
                          AppRoutes.classroomGoogleLink(widget.classroom.id),
                        ),
                  icon: const Icon(Icons.add_link),
                  label: const Text('ผูกกับ Google Classroom'),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
