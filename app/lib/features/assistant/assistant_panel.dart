import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/api/error_codes.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import 'assistant.dart';

/// "ผู้ช่วย Krucheck" (DESIGN §37): the chat, or the list of the teacher's
/// conversations. The desktop shows it as a panel on the right
/// ([AssistantPanel.width]); a phone or a tablet as its own page
/// ([AssistantScreen]).
class AssistantPanel extends ConsumerStatefulWidget {
  const AssistantPanel({
    super.key,
    required this.onClose,
    required this.onOpenSettings,
  });

  static const width = 420.0;

  final VoidCallback onClose;

  /// To enter a Gemini key; the panel is closed first.
  final VoidCallback onOpenSettings;

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
          ? _NoKey(onOpenSettings: widget.onOpenSettings)
          : _showList
          ? _ConversationList(
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
            );
    } else if (status.hasError) {
      body = _Centered(
        icon: Icons.cloud_off_outlined,
        text: apiErrorMessage(status.error!),
        action: OutlinedButton(
          onPressed: () => ref.invalidate(assistantStatusProvider),
          child: const Text('ลองใหม่'),
        ),
      );
    } else {
      body = const Center(child: CircularProgressIndicator());
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
  });

  final AssistantChatState chat;
  final AssistantStatus status;
  final TextEditingController input;
  final List<String> suggestions;
  final void Function([String? text]) onSend;

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
      list = const Center(child: CircularProgressIndicator());
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
      final items = [
        if (chat.sending) null,
        for (final (i, m) in messages.indexed.toList().reversed)
          (i: i, message: m),
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
            if (item == null) return _ThinkingBubble(maxWidth: maxWidth);
            final last = item.i == messages.length - 1;
            return _Bubble(
              message: item.message,
              maxWidth: maxWidth,
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
          child: Text(
            'อย่าพิมพ์ข้อมูลส่วนตัวของนักเรียน เช่น ชื่อ เลขประจำตัว หรือคะแนนรายคน',
            style: muted,
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
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
            '${tokens > 0 ? ' · บทสนทนานี้ใช้ ${_thousands(tokens)} โทเค็น' : ''}',
            key: const ValueKey('assistant_usage'),
            style: muted,
          ),
        ),
      ],
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message, required this.maxWidth, this.onRetry});

  final AssistantMessage message;
  final double maxWidth;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final (color, onColor) = message.isUser
        ? (scheme.primaryContainer, scheme.onPrimaryContainer)
        : message.isError
        ? (scheme.errorContainer, scheme.onErrorContainer)
        : (scheme.surfaceContainerHigh, scheme.onSurface);
    final code = message.code;

    return Align(
      alignment: message.isUser ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: Card(
          key: message.isError ? const ValueKey('assistant_error') : null,
          color: color,
          margin: const EdgeInsets.symmetric(vertical: 4),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (message.isError)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.error_outline, size: 18, color: onColor),
                        const SizedBox(width: 6),
                        Text(
                          'ผู้ช่วยตอบไม่สำเร็จ',
                          style: theme.textTheme.titleSmall?.copyWith(
                            color: onColor,
                          ),
                        ),
                      ],
                    ),
                  ),
                SelectableText(
                  message.content,
                  style: theme.textTheme.bodyMedium?.copyWith(color: onColor),
                ),
                if (code != null) ErrorCodeLine(code),
                if (onRetry != null)
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      key: const ValueKey('assistant_retry'),
                      onPressed: onRetry,
                      icon: const Icon(Icons.refresh),
                      label: const Text('ลองใหม่'),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// "ผู้ช่วยกำลังคิด…" with three dots that pulse in turn.
class _ThinkingBubble extends StatefulWidget {
  const _ThinkingBubble({required this.maxWidth});

  final double maxWidth;

  @override
  State<_ThinkingBubble> createState() => _ThinkingBubbleState();
}

class _ThinkingBubbleState extends State<_ThinkingBubble>
    with SingleTickerProviderStateMixin {
  late final _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  )..repeat();

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Align(
      alignment: Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: widget.maxWidth),
        child: Card(
          key: const ValueKey('assistant_thinking'),
          color: scheme.surfaceContainerHigh,
          margin: const EdgeInsets.symmetric(vertical: 4),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                AnimatedBuilder(
                  animation: _pulse,
                  builder: (context, _) => Row(
                    children: [
                      for (var i = 0; i < 3; i++)
                        Padding(
                          padding: const EdgeInsets.only(right: 4),
                          child: Opacity(
                            opacity: _dot(i),
                            child: Icon(
                              Icons.circle,
                              size: 8,
                              color: scheme.primary,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  'ผู้ช่วยกำลังคิด…',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Each dot is brightest a third of a cycle after the one before.
  double _dot(int i) {
    final phase = (_pulse.value - i / 3) % 1;
    return 0.3 + 0.7 * (phase < 0.5 ? 1 - phase * 2 : 0);
  }
}

class _ConversationList extends ConsumerWidget {
  const _ConversationList({required this.onOpen});

  final ValueChanged<int> onOpen;

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    AssistantConversation c,
  ) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ลบบทสนทนานี้?'),
        content: Text('«${c.title}» จะถูกลบถาวร'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('ยกเลิก'),
          ),
          FilledButton(
            key: const ValueKey('assistant_delete_confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('ลบ'),
          ),
        ],
      ),
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
            child: _Centered(
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
                  if (c.tokens > 0) '${_thousands(c.tokens)} โทเค็น',
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
      AsyncValue(:final error?) => _Centered(
        icon: Icons.cloud_off_outlined,
        text: apiErrorMessage(error),
        action: OutlinedButton(
          onPressed: () => ref.invalidate(assistantConversationsProvider),
          child: const Text('ลองใหม่'),
        ),
      ),
      _ => const Center(child: CircularProgressIndicator()),
    };
  }
}

class _NoKey extends StatelessWidget {
  const _NoKey({required this.onOpenSettings});

  final VoidCallback onOpenSettings;

  @override
  Widget build(BuildContext context) => _Centered(
    icon: Icons.key_outlined,
    text:
        'ผู้ช่วยใช้ Gemini API key ของครูเอง ยังไม่ได้ใส่ key '
        'ใส่ที่หน้าตั้งค่าก่อน แล้วกลับมาถามได้เลย',
    action: FilledButton(
      key: const ValueKey('assistant_settings'),
      onPressed: onOpenSettings,
      child: const Text('ไปที่ตั้งค่า'),
    ),
  );
}

class _Centered extends StatelessWidget {
  const _Centered({required this.icon, required this.text, this.action});

  final IconData icon;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(height: 12),
            Text(text, textAlign: TextAlign.center),
            if (action case final a?) ...[const SizedBox(height: 16), a],
          ],
        ),
      ),
    );
  }
}

/// 41230 -> "41,230".
String _thousands(int n) =>
    n.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');

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
          onOpenSettings: () => context.push(AppRoutes.settings),
        ),
      ),
    );
  }
}
