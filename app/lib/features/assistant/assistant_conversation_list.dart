import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/message_dialog.dart';
import 'assistant.dart';
import 'assistant_notices.dart';

class AssistantConversationList extends ConsumerWidget {
  const AssistantConversationList({super.key, required this.onOpen});

  final ValueChanged<int> onOpen;

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    AssistantConversation c,
  ) async {
    final ok = await showMessageDialog<bool>(
      context,
      title: 'ลบบทสนทนานี้?',
      message: '«${c.title}» จะถูกลบถาวร',
      actions: const [
        DialogAction('ยกเลิก', value: false),
        DialogAction(
          'ลบ',
          value: true,
          key: ValueKey('assistant_delete_confirm'),
          primary: true,
          destructive: true,
        ),
      ],
    );
    if (ok != true) return;
    try {
      await ref.read(assistantChatProvider.notifier).delete(c.id);
    } catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final conversations = ref.watch(assistantConversationsProvider);
    final note = Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      child: Text(
        'บทสนทนาที่ไม่ได้ใช้ 90 วันจะถูกลบเอง ผู้ดูแลระบบเปิดอ่านได้เพื่อช่วยแก้ปัญหา',
        style: muted,
      ),
    );

    return switch (conversations) {
      AsyncValue(value: final list?) when list.isEmpty => Column(
        children: [
          const Expanded(
            child: AssistantCentered(
              icon: Icons.forum_outlined,
              text: 'ยังไม่มีบทสนทนา',
            ),
          ),
          note,
        ],
      ),
      AsyncValue(value: final list?) => ListView(
        children: [
          for (final c in list)
            ListTile(
              key: ValueKey('assistant_conversation_${c.id}'),
              title: Text(
                c.title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              subtitle: Text(
                [
                  if (c.updatedAt case final at?) formatThaiDateTime(at),
                  if (c.tokens > 0) '${thousands(c.tokens)} โทเค็น',
                ].join(' · '),
              ),
              onTap: () => onOpen(c.id),
              trailing: IconButton(
                key: ValueKey('assistant_delete_${c.id}'),
                tooltip: 'ลบบทสนทนา',
                icon: const Icon(Icons.delete_outline),
                onPressed: () => _delete(context, ref, c),
              ),
            ),
          note,
        ],
      ),
      AsyncValue(:final error?) => AssistantCentered(
        icon: Icons.cloud_off_outlined,
        text: apiErrorMessage(error),
        action: OutlinedButton(
          onPressed: () => ref.invalidate(assistantConversationsProvider),
          child: const Text('ลองใหม่'),
        ),
      ),
      _ => const Center(child: CircularProgressIndicator.adaptive()),
    };
  }
}
