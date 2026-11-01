import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../assistant.dart';
import 'plan_card_common.dart';

class PracticePlanOpen extends ConsumerWidget {
  const PracticePlanOpen({super.key, required this.message});

  final AssistantMessage message;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final plans = ref.watch(assistantPlansProvider.notifier);
    final ui =
        ref.watch(assistantPlansProvider)[message.id] ?? plans.of(message);
    final busy = ui.applying || ref.watch(assistantChatProvider).sending;
    final plan = message.plan!;
    final id = message.id;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(Icons.fitness_center, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'แบบฝึกที่เสนอ ${plan.practiceItems.length} ข้อ',
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        Text(
          plan.target.title ?? plan.target.error ?? '',
          style: plan.target.error == null
              ? muted
              : theme.textTheme.bodySmall?.copyWith(color: scheme.error),
        ),
        const Divider(height: 16),
        for (final (i, item) in plan.practiceItems.indexed)
          Padding(
            key: ValueKey('assistant_plan_q_${id}_$i'),
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Checkbox.adaptive(
                  value: ui.selected.contains(i),
                  onChanged: item.error != null || busy
                      ? null
                      : (_) => plans.toggle(message, i),
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'ข้อ ${i + 1} · ${item.typeLabel}',
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                      Text(item.promptText, style: theme.textTheme.bodyMedium),
                      for (final o in item.options)
                        Text(
                          '${o.key}. ${o.text}',
                          style: o.key == item.answer
                              ? theme.textTheme.titleSmall?.copyWith(
                                  color: scheme.primary,
                                )
                              : theme.textTheme.bodyMedium,
                        ),
                      if (item.answerType != 'mcq' && item.answer != null)
                        Text(
                          'เฉลย: ${item.answer}',
                          style: theme.textTheme.titleSmall?.copyWith(
                            color: scheme.primary,
                          ),
                        ),
                      if (item.explanation.isNotEmpty)
                        Text('คำอธิบาย: ${item.explanation}', style: muted),
                      if (item.error case final error?)
                        Text(
                          error,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: scheme.error,
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        Text('บันทึกเป็นร่าง แล้วไปอนุมัติใน «คลังแบบฝึก»', style: muted),
        PlanCardFooter(
          message: message,
          label: 'บันทึกเป็นร่าง (${ui.selected.length})',
          icon: Icons.save_outlined,
          enabled: ui.selected.isNotEmpty && plan.target.error == null,
        ),
      ],
    );
  }
}

