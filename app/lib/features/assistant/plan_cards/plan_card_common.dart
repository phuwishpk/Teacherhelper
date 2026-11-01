import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/small_spinner.dart';
import '../assistant.dart';
import '../assistant_plan.dart';

class PlanCardReplaced extends StatelessWidget {
  const PlanCardReplaced({super.key, required this.plan});

  final AssistantPlan plan;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        Icon(Icons.history, color: theme.colorScheme.onSurfaceVariant),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            '${switch (plan.kind) {
              AssistantPlan.rubricCard => 'การ์ด rubric',
              AssistantPlan.indicatorsCard => 'การ์ดตัวชี้วัด ${plan.items.length} รายการ',
              AssistantPlan.gradebookCard => 'การ์ดตั้งค่าสมุดคะแนน',
              AssistantPlan.practiceCard => 'การ์ดแบบฝึก ${plan.practiceItems.length} ข้อ',
              AssistantPlan.fileCard => 'การ์ดอ่านไฟล์',
              _ => 'การ์ด ${plan.questions.length} ข้อ',
            }}นี้ถูกแทนด้วยการ์ดใหม่',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      ],
    );
  }
}

/// The error, the spinner and the button at the bottom of an open card.
class PlanCardFooter extends ConsumerWidget {
  const PlanCardFooter({
    super.key,
    required this.message,
    required this.label,
    required this.icon,
    required this.enabled,
  });

  final AssistantMessage message;
  final String label;
  final IconData icon;
  final bool enabled;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final plans = ref.watch(assistantPlansProvider.notifier);
    ref.watch(assistantPlansProvider);
    final ui = plans.of(message);
    final busy = ui.applying || ref.watch(assistantChatProvider).sending;
    final id = message.id!;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (ui.error case final error?)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              error,
              key: ValueKey('assistant_plan_error_$id'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        const SizedBox(height: 8),
        Row(
          mainAxisAlignment: MainAxisAlignment.end,
          children: [
            if (ui.applying)
              const Padding(
                padding: EdgeInsets.only(right: 12),
                child: SmallSpinner(size: 20),
              ),
            FilledButton.icon(
              key: ValueKey('assistant_plan_apply_$id'),
              onPressed: enabled && !busy ? () => plans.apply(id) : null,
              icon: Icon(icon),
              label: Text(label),
            ),
          ],
        ),
      ],
    );
  }
}

/// After a gradebook or practice card: what was saved and where to look.
class PlanCardSimpleApplied extends StatelessWidget {
  const PlanCardSimpleApplied({
    super.key,
    required this.text,
    required this.note,
    required this.button,
    required this.route,
    required this.onNavigate,
  });

  final String text;
  final String note;
  final String button;
  final String route;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.check_circle, color: theme.colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                text,
                key: const ValueKey('assistant_plan_done'),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        Text(
          note,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton.icon(
            key: const ValueKey('assistant_plan_open'),
            onPressed: () => onNavigate(route),
            icon: const Icon(Icons.open_in_new),
            label: Text(button),
          ),
        ),
      ],
    );
  }
}

String planPoints(double p) => p == p.roundToDouble() ? '${p.toInt()}' : '$p';

