import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import 'assistant.dart';
import 'assistant_attached_files.dart';
import 'assistant_bubble.dart';
import 'assistant_conversation_list.dart';
import 'assistant_notices.dart';
import 'assistant_plan_card.dart';
import 'assistant_student_data.dart';

/// "ผู้ช่วย Krucheck" (DESIGN §37): the chat, or the list of the teacher's
/// conversations. The desktop shows it as a panel on the right
/// ([AssistantPanel.width]); a phone or a tablet as its own page
/// ([AssistantScreen]).
class AssistantPanel extends ConsumerStatefulWidget {
  const AssistantPanel({
    super.key,
    required this.onClose,
    required this.onNavigate,
  });

  static const width = 420.0;

  final VoidCallback onClose;

  /// Opens an app route (settings, an assignment); the desktop panel is
  /// closed first.
  final ValueChanged<String> onNavigate;

  @override
  ConsumerState<AssistantPanel> createState() => _AssistantPanelState();
}

class _AssistantPanelState extends ConsumerState<AssistantPanel> {
  final _input = TextEditingController();
  bool _showList = false;

  static const _suggestions = [
    'ตัดเกรดในสมุดคะแนนยังไง',
    'ตั้งเกณฑ์ให้คะแนนแบบไหนดี',
    'สแกนใบงานไม่ผ่าน ทำยังไง',
    'เชื่อม Google Classroom ยังไง',
    'ช่วยออกโจทย์ปรนัยเรื่องเศษส่วน 5 ข้อ ลงการบ้าน',
    'ห้องไหนส่งงานไม่ครบบ้าง',
  ];

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  void _send([String? text]) {
    final question = (text ?? _input.text).trim();
    if (question.isEmpty) return;
    _input.clear();
    ref.read(assistantChatProvider.notifier).send(question);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final chat = ref.watch(assistantChatProvider);
    final status = ref.watch(assistantStatusProvider);
    final busy = chat.sending || chat.loading;

    final Widget body;
    if (status.value case final s?) {
      body = !s.available
          ? AssistantNoKey(onOpenSettings: () => widget.onNavigate(AppRoutes.settings))
          : _showList
          ? AssistantConversationList(
              onOpen: (id) {
                setState(() => _showList = false);
                ref.read(assistantChatProvider.notifier).open(id);
              },
            )
          : _Chat(
              chat: chat,
              status: s,
              input: _input,
              suggestions: _suggestions,
              onSend: _send,
              onNavigate: widget.onNavigate,
            );
    } else if (status.hasError) {
      body = AssistantCentered(
        icon: Icons.cloud_off_outlined,
        text: apiErrorMessage(status.error!),
        action: OutlinedButton(
          onPressed: () => ref.invalidate(assistantStatusProvider),
          child: const Text('ลองใหม่'),
        ),
      );
    } else {
      body = const Center(child: CircularProgressIndicator.adaptive());
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
          child: Row(
            children: [
              Icon(Icons.smart_toy_outlined, color: theme.colorScheme.primary),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  _showList ? 'บทสนทนาทั้งหมด' : 'ผู้ช่วย Krucheck',
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              IconButton(
                key: const ValueKey('assistant_new'),
                tooltip: 'บทสนทนาใหม่',
                icon: const Icon(Icons.add_comment_outlined),
                onPressed: busy
                    ? null
                    : () {
                        setState(() => _showList = false);
                        ref.read(assistantChatProvider.notifier).startNew();
                      },
              ),
              IconButton(
                key: const ValueKey('assistant_list'),
                tooltip: _showList ? 'กลับไปที่แชท' : 'บทสนทนาทั้งหมด',
                isSelected: _showList,
                icon: const Icon(Icons.history),
                onPressed: busy
                    ? null
                    : () => setState(() => _showList = !_showList),
              ),
              IconButton(
                key: const ValueKey('assistant_close'),
                tooltip: 'ปิด',
                icon: const Icon(Icons.close),
                onPressed: widget.onClose,
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(child: body),
      ],
    );
  }
}

class _Chat extends ConsumerWidget {
  const _Chat({
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

    final Widget list;
    if (chat.loading) {
      list = const Center(child: CircularProgressIndicator.adaptive());
    } else if (messages.isEmpty) {
      list = ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'ถามเรื่องการใช้ Krucheck การให้คะแนน หรือการตัดเกรดได้เลย',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Text(
            'ผู้ช่วยตอบจากคู่มือของระบบ ตอนนี้ยังเปิดดูหรือแก้ข้อมูลในบัญชีให้ไม่ได้',
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
            return AssistantBubble(
              message: item.message,
              maxWidth: maxWidth,
              labels: chat.labels,
              onNavigate: onNavigate,
              onRetry: last && chat.canRetry
                  ? () => ref.read(assistantChatProvider.notifier).retry()
                  : null,
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



/// The assistant as its own page, on a phone or a tablet.
class AssistantScreen extends StatelessWidget {
  const AssistantScreen({super.key});

  @override
  Widget build(BuildContext context) {
    void close() =>
        context.canPop() ? context.pop() : context.go(AppRoutes.home);
    return Scaffold(
      body: SafeArea(
        child: AssistantPanel(
          onClose: close,
          onNavigate: (route) => context.push(route),
        ),
      ),
    );
  }
}

