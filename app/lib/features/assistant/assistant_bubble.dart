import 'package:flutter/material.dart';

import '../../core/api/error_codes.dart';
import '../../core/router/app_router.dart';
import 'assistant.dart';
import 'assistant_student_data.dart';

class AssistantBubble extends StatelessWidget {
  const AssistantBubble({
    super.key,
    required this.message,
    required this.maxWidth,
    this.labels = const {},
    this.onNavigate,
    this.onRetry,
  });

  final AssistantMessage message;
  final double maxWidth;

  /// Students by label: the answer shows names, and links under it.
  final Map<String, StudentLabel> labels;
  final ValueChanged<String>? onNavigate;
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
                  message.isUser
                      ? message.content
                      : withNames(message.content, labels),
                  style: theme.textTheme.bodyMedium?.copyWith(color: onColor),
                ),
                for (final f in message.attachments)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          Icons.description_outlined,
                          size: 16,
                          color: onColor,
                        ),
                        const SizedBox(width: 4),
                        Flexible(
                          child: Text(
                            '${f.name} (${f.pageCount} หน้า)',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: onColor,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                if (!message.isUser && onNavigate != null)
                  if (mentioned(message.content, labels) case final students
                      when students.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          for (final s in students)
                            if (s.classroomId != null)
                              ActionChip(
                                key: ValueKey('assistant_student_${s.label}'),
                                avatar: const Icon(Icons.person_outline),
                                label: Text(s.name ?? s.label),
                                onPressed: () => onNavigate!(
                                  AppRoutes.studentMastery(
                                    s.classroomId!,
                                    s.studentId,
                                  ),
                                ),
                              ),
                        ],
                      ),
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
class AssistantThinkingBubble extends StatefulWidget {
  const AssistantThinkingBubble({super.key, required this.maxWidth});

  final double maxWidth;

  @override
  State<AssistantThinkingBubble> createState() =>
      _AssistantThinkingBubbleState();
}

class _AssistantThinkingBubbleState extends State<AssistantThinkingBubble>
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
