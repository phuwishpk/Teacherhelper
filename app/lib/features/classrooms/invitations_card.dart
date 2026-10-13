import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'classrooms_providers.dart';
import 'co_teachers.dart';

/// "คำเชิญสอนร่วม" above the classroom list (DESIGN §29.13.4): the rooms
/// whose owners invited this teacher, answered here. Nothing when there is
/// none (or the list cannot load: the rooms matter more).
class InvitationsCard extends ConsumerWidget {
  const InvitationsCard({super.key});

  Future<void> _answer(
    BuildContext context,
    WidgetRef ref,
    ClassroomInvitation invitation, {
    required bool accept,
  }) async {
    final repo = ref.read(coTeachersRepositoryProvider);
    try {
      if (accept) {
        await repo.accept(invitation.id);
      } else {
        await repo.decline(invitation.id);
      }
      ref.invalidate(classroomInvitationsProvider);
      if (accept) await ref.read(classroomsProvider.notifier).refresh();
      if (context.mounted) {
        showMessage(
          context,
          accept
              ? 'ตอบรับแล้ว ห้อง ${invitation.classroomName} อยู่ในรายการห้องเรียนของคุณ'
              : 'ปฏิเสธคำเชิญห้อง ${invitation.classroomName} แล้ว',
        );
      }
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final invitations =
        ref.watch(classroomInvitationsProvider).value ?? const [];
    if (invitations.isEmpty) return const SizedBox.shrink();
    return Card(
      key: const ValueKey('invitations_card'),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            leading: const Icon(Icons.mail_outline),
            title: Text('คำเชิญสอนร่วม', style: theme.textTheme.titleMedium),
            subtitle: const Text(
              'ตอบรับแล้วห้องจะอยู่ในรายการของคุณ ผูกรายวิชาและสั่งงานได้ แต่แก้รายชื่อไม่ได้',
            ),
          ),
          for (final i in invitations) ...[
            const Divider(height: 1),
            ListTile(
              key: ValueKey('invitation_${i.id}'),
              title: Text('ห้อง ${i.classroomName}'),
              subtitle: Text(
                '${i.ownerName == null ? 'ครูประจำชั้น' : 'ครู ${i.ownerName}'} '
                'เชิญคุณร่วมสอน · ปีการศึกษา ${i.academicYear}',
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    key: ValueKey('invitation_decline_${i.id}'),
                    onPressed: () => _answer(context, ref, i, accept: false),
                    child: const Text('ปฏิเสธ'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    key: ValueKey('invitation_accept_${i.id}'),
                    onPressed: () => _answer(context, ref, i, accept: true),
                    child: const Text('ตอบรับ'),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}
