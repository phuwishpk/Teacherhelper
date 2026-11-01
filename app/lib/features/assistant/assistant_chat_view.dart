import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/live/app_settings.dart';
import '../../core/widgets/mascot.dart';
import '../../core/widgets/mascot_mood.dart';
import 'assistant.dart';
import 'assistant_attached_files.dart';
import 'assistant_bubble.dart';
import 'assistant_mood.dart';
import 'assistant_notices.dart';
import 'assistant_plan_card.dart';
import 'assistant_student_data.dart';

class AssistantChatView extends ConsumerWidget {
  const AssistantChatView({
    super.key,
    required this.chat,
    required this.status,
    required this.input,
    required this.suggestions,
    required this.onSend,
    required this.onNavigate,
  });

  final AssistantChatState chat;
  final AssistantStatus status;
  final TextEditingController input;
  final List<String> suggestions;
  final void Function([String? text]) onSend;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final busy = chat.sending || chat.loading;
    final messages = chat.messages;
    final tokens = chat.conversation?.tokens ?? 0;
    final mascotOn = ref.watch(mascotEnabledProvider);

    final Widget list;
    if (chat.loading) {
      list = const Center(child: CircularProgressIndicator.adaptive());
    } else if (messages.isEmpty) {
      list = ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (mascotOn) ...[
            const Center(
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Mascot(
                  mood: MascotMood.wave,
                  size: 96,
                  semanticLabel: Mascot.name,
                ),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'สวัสดี น้องเช็คเอง',
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 6),
          ],
          Text(
            'ถามเรื่องการใช้ Krucheck การให้คะแนน หรือการตัดเกรดได้เลย',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Text(
            'ผู้ช่วยตอบจากคู่มือของระบบ และสร้างงานให้ได้เมื่อครูกดยืนยันในการ์ด',
            style: muted,
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final (i, s) in suggestions.indexed)
                ActionChip(
                  key: ValueKey('assistant_suggestion_$i'),
                  label: Text(s),
                  onPressed: () => onSend(s),
                ),
            ],
          ),
        ],
      );
    } else {
      // Newest at the bottom, kept in view as messages arrive.
      // A plan card sits under its answer, the panel's full width.
      final items = [
        if (chat.sending) null,
        for (final (i, m) in messages.indexed.toList().reversed) ...[
          if (m.plan != null) (i: i, message: m, card: true),
          (i: i, message: m, card: false),
        ],
      ];
      list = LayoutBuilder(
        builder: (context, constraints) => ListView.builder(
          key: const ValueKey('assistant_messages'),
          reverse: true,
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
          itemCount: items.length,
          itemBuilder: (context, index) {
            final item = items[index];
            final maxWidth = constraints.maxWidth * 0.86;
            if (item == null) {
              return AssistantThinkingBubble(maxWidth: maxWidth);
            }
            if (item.card) {
              return AssistantPlanCard(
                message: item.message,
                onNavigate: onNavigate,
              );
            }
            final last = item.i == messages.length - 1;
            final mood = switch (item.message.isUser) {
              true => null,
              false when last => moodForChat(chat),
              false when item.message.isError => MascotMood.sorry,
              false => MascotMood.idle,
            };
            return AssistantBubble(
              message: item.message,
              maxWidth: maxWidth,
              labels: chat.labels,
              onNavigate: onNavigate,
              onRetry: last && chat.canRetry
                  ? () => ref.read(assistantChatProvider.notifier).retry()
                  : null,
              mood: mood,
            );
          },
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(child: list),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
          child: StudentDataChip(status: status, enabled: !busy),
        ),
        AssistantAttachedFiles(enabled: !busy),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
          child: Text(
            'ชื่อนักเรียนที่พิมพ์จะถูกแทนด้วยรหัสก่อนส่งให้ AI อย่าพิมพ์เลขประจำตัวหรือข้อมูลติดต่อ',
            style: muted,
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              IconButton(
                key: const ValueKey('assistant_attach'),
                tooltip: 'แนบเอกสารของครู (PDF หรือรูป)',
                icon: const Icon(Icons.attach_file),
                onPressed:
                    busy || ref.watch(assistantAttachmentsProvider).uploading
                    ? null
                    : () => ref
                          .read(assistantAttachmentsProvider.notifier)
                          .pick(),
              ),
              Expanded(
                child: TextField(
                  key: const ValueKey('assistant_input'),
                  controller: input,
                  enabled: !busy,
                  minLines: 1,
                  maxLines: 5,
                  maxLength: 2000,
                  textInputAction: TextInputAction.send,
                  onSubmitted: (_) => onSend(),
                  decoration: InputDecoration(
                    hintText: busy ? 'ผู้ช่วยกำลังคิด…' : 'พิมพ์คำถาม',
                    counterText: '',
                  ),
                ),
              ),
              IconButton(
                key: const ValueKey('assistant_send'),
                tooltip: 'ส่ง',
                icon: const Icon(Icons.send),
                onPressed: busy ? null : onSend,
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 12),
          child: Text(
            'วันนี้ถามแล้ว ${status.usedToday}/${status.dailyLimit} ข้อความ'
            '${tokens > 0 ? ' · บทสนทนานี้ใช้ ${thousands(tokens)} โทเค็น' : ''}',
            key: const ValueKey('assistant_usage'),
            style: muted,
          ),
        ),
      ],
    );
  }
}
