import 'package:flutter/material.dart';

import '../assistant.dart';
import '../assistant_plan.dart';
import 'plan_card_common.dart';

String _weight(double w) => w == w.roundToDouble() ? '${w.toInt()}' : '$w';

class GradebookPlanOpen extends StatelessWidget {
  const GradebookPlanOpen({super.key, required this.message});

  final AssistantMessage message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final plan = message.plan!;
    final g = plan.gradebook ?? const GradebookProposal();
    String rows(List<GradebookCategoryRow> list) => list
        .map(
          (c) =>
              '${c.name} ${_weight(c.weight)}%'
              '${c.dropLowest > 0 ? ' (ตัด ${c.dropLowest} ต่ำสุด)' : ''}'
              '${c.homeworkDefault ? ' · หมวดตั้งต้นของการบ้าน' : ''}',
        )
        .join('\n');
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(Icons.tune, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'ตั้งค่าสมุดคะแนน',
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        Text(plan.target.title ?? plan.target.error ?? '', style: muted),
        const Divider(height: 16),
        if (g.categories case final categories?) ...[
          Text('หมวดคะแนนใหม่', style: theme.textTheme.labelLarge),
          Text(rows(categories), style: theme.textTheme.bodyMedium),
          if (g.currentCategories.isNotEmpty)
            Text(
              'เดิม: ${rows(g.currentCategories).replaceAll('\n', ' · ')}',
              style: muted,
            ),
          for (final r in g.removed)
            Text(
              'หมวด «${r.name}» จะถูกเอาออก'
              '${r.itemCount > 0 ? ' งาน ${r.itemCount} ชิ้นในหมวดนี้จะเป็น "ยังไม่ระบุหมวด"' : ''}',
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
            ),
          const SizedBox(height: 8),
        ],
        if (g.cutoffs case final cutoffs?) ...[
          Text('เกณฑ์เกรด (4 ถึง 1)', style: theme.textTheme.labelLarge),
          Text(cutoffs.join(' / '), style: theme.textTheme.bodyMedium),
          if (g.currentCutoffs.isNotEmpty)
            Text('เดิม: ${g.currentCutoffs.join(' / ')}', style: muted),
        ],
        if (plan.problem case final problem?)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              problem,
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
            ),
          )
        else
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'ถ้าประกาศเกรดไว้แล้ว หลังบันทึกต้องประกาศอีกครั้ง',
              style: muted,
            ),
          ),
        PlanCardFooter(
          message: message,
          label: 'บันทึกการตั้งค่า',
          icon: Icons.save_outlined,
          enabled: plan.problem == null && plan.target.error == null,
        ),
      ],
    );
  }
}

