import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/error_codes.dart';
import '../../core/live/app_settings.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/mascot.dart';
import '../../core/widgets/mascot_mood.dart';
import 'assistant.dart';
import 'assistant_student_data.dart';

class AssistantBubble extends ConsumerWidget {
  const AssistantBubble({
    super.key,
    required this.message,
    required this.maxWidth,
    this.labels = const {},
    this.onNavigate,
    this.onRetry,
    this.mood,
  });

  final AssistantMessage message;
  final double maxWidth;

  /// Students by label: the answer shows names, and links under it.
  final Map<String, StudentLabel> labels;
  final ValueChanged<String>? onNavigate;
  final VoidCallback? onRetry;

  /// Mascot mood when shown beside an assistant answer or error (M6).
  final MascotMood? mood;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mascotOn = ref.watch(mascotEnabledProvider);
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final (color, onColor) = message.isUser
        ? (scheme.primaryContainer, scheme.onPrimaryContainer)
        : message.isError
        ? (scheme.errorContainer, scheme.onErrorContainer)
        : (scheme.surfaceContainerHigh, scheme.onSurface);
    final code = message.code;

    final card = Card(
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
                    Flexible(
                      child: Text(
                        'ผู้ช่วยตอบไม่สำเร็จ',
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: onColor,
                        ),
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
            if (message.errorCode == ErrorCode.keyInvalid &&
                onNavigate != null)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  key: const ValueKey('assistant_error_settings'),
                  onPressed: () => onNavigate!(AppRoutes.settings),
                  icon: const Icon(Icons.key_outlined),
                  label: const Text('ไปที่ตั้งค่า'),
                ),
              ),
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
    );

    if (message.isUser || !mascotOn) {
      return Align(
        alignment: message.isUser ? Alignment.centerRight : Alignment.centerLeft,
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: maxWidth),
          child: card,
        ),
      );
    }

    final mascotMood =
        mood ?? (message.isError ? MascotMood.sorry : MascotMood.idle);
    return Align(
      alignment: Alignment.centerLeft,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Mascot(mood: mascotMood, size: 28),
          const SizedBox(width: 8),
          Flexible(
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: maxWidth - 36),
              child: card,
            ),
          ),
        ],
      ),
    );
  }
}

/// "ผู้ช่วยกำลังคิด…" with three dots that pulse in turn.
class AssistantThinkingBubble extends ConsumerStatefulWidget {
  const AssistantThinkingBubble({super.key, required this.maxWidth});

  final double maxWidth;

  @override
  ConsumerState<AssistantThinkingBubble> createState() =>
      _AssistantThinkingBubbleState();
}

class _AssistantThinkingBubbleState
    extends ConsumerState<AssistantThinkingBubble>
    with SingleTickerProviderStateMixin {
  late final _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // The dots rest when the device asks for less motion.
    if (MediaQuery.disableAnimationsOf(context)) {
      _pulse.stop();
    } else if (!_pulse.isAnimating) {
      _pulse.repeat();
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mascotOn = ref.watch(mascotEnabledProvider);
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final bubble = ConstrainedBox(
      constraints: BoxConstraints(
        maxWidth: mascotOn ? widget.maxWidth - 36 : widget.maxWidth,
      ),
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
    );

    if (!mascotOn) {
      return Align(
        alignment: Alignment.centerLeft,
        child: bubble,
      );
    }

    return Align(
      alignment: Alignment.centerLeft,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          const Mascot(mood: MascotMood.thinking, size: 28),
          const SizedBox(width: 8),
          bubble,
        ],
      ),
    );
  }

  /// Each dot is brightest a third of a cycle after the one before.
  double _dot(int i) {
    final phase = (_pulse.value - i / 3) % 1;
    return 0.3 + 0.7 * (phase < 0.5 ? 1 - phase * 2 : 0);
  }
}
