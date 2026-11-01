import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/live/app_settings.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../../core/widgets/mascot.dart';
import '../../core/widgets/mascot_mood.dart';
import 'assistant.dart';
import 'assistant_chat_view.dart';
import 'assistant_conversation_list.dart';
import 'assistant_mood.dart';
import 'assistant_notices.dart';

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
  final _inputFocus = FocusNode();
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
    _inputFocus.dispose();
    super.dispose();
  }

  void _send([String? text]) {
    if (ref.read(assistantAttachmentsProvider).uploading) return;
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
    final mascotOn = ref.watch(mascotEnabledProvider);

    // The input is locked while the AI answers, which takes its focus. On a
    // desktop the teacher types on; a phone's keyboard would hide the answer.
    ref.listen(assistantChatProvider.select((c) => c.sending), (was, sending) {
      if (was != true || sending) return;
      if (context.windowSize != WindowSize.expanded) return;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _inputFocus.requestFocus();
      });
    });

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
          : AssistantChatView(
              chat: chat,
              status: s,
              input: _input,
              inputFocus: _inputFocus,
              suggestions: _suggestions,
              onSend: _send,
              onNavigate: widget.onNavigate,
            );
    } else if (status.hasError) {
      body = AssistantCentered(
        illustration:
            mascotOn ? const Mascot(mood: MascotMood.sorry, size: 96) : null,
        icon: mascotOn ? null : Icons.cloud_off_outlined,
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
              if (mascotOn)
                Mascot(mood: moodForChat(chat), size: 40)
              else
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

