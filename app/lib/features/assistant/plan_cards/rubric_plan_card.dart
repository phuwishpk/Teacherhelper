import 'package:flutter/material.dart';

import '../../../core/router/app_router.dart';
import '../assistant.dart';
import '../assistant_plan.dart';
import 'plan_card_common.dart';

class RubricPlanOpen extends StatelessWidget {
  const RubricPlanOpen({super.key, required this.message});

  final AssistantMessage message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final plan = message.plan!;
    final q = plan.question;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(Icons.rule, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                q == null ? 'ร่าง rubric' : 'ร่าง rubric ข้อ ${q.position}',
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        if (q != null) ...[
          const SizedBox(height: 4),
          Text(
            'ใน «${plan.target.title ?? ''}» · '
            '${q.type == 'open' ? 'อัตนัย' : 'แสดงวิธีทำ'} · '
            '${planPoints(q.maxPoints)} คะแนน',
            style: muted,
          ),
          Text(q.prompt, style: theme.textTheme.bodyMedium),
          if (q.rubricStatus == 'approved')
            Text(
              'ข้อนี้มี rubric ที่อนุมัติแล้ว ร่างนี้จะแทนที่ และต้องอนุมัติใหม่',
              style: muted,
            ),
        ],
        const Divider(height: 16),
        for (final c in plan.criteria)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Text(c.description, style: theme.textTheme.bodyMedium),
                ),
                if (c.isCore)
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: Text(
                      'เกณฑ์หลัก',
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: scheme.primary,
                      ),
                    ),
                  ),
                const SizedBox(width: 8),
                Text(
                  '${planPoints(c.points)} คะแนน',
                  style: theme.textTheme.labelMedium,
                ),
              ],
            ),
          ),
        for (final (i, step) in plan.referenceSteps.indexed)
          Text('${i + 1}. $step', style: theme.textTheme.bodyMedium),
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
              'บันทึกเป็นร่าง แล้วไปตรวจและกด «อนุมัติ» ในหน้า rubric',
              style: muted,
            ),
          ),
        PlanCardFooter(
          message: message,
          label: 'บันทึกเป็นร่าง',
          icon: Icons.save_outlined,
          enabled: plan.problem == null && q != null,
        ),
      ],
    );
  }
}

class RubricPlanApplied extends StatelessWidget {
  const RubricPlanApplied({
    super.key,
    required this.result,
    required this.onNavigate,
  });

  final PlanResult result;
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
                'บันทึก rubric ร่างของข้อ ${result.position} แล้ว',
                key: const ValueKey('assistant_plan_done'),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        Text(
          'ไปตรวจและกด «อนุมัติ» ในหน้า rubric ก่อนใช้ตรวจงาน',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton.icon(
            key: const ValueKey('assistant_plan_open'),
            onPressed: () => onNavigate(
              AppRoutes.rubric(result.assignmentId!, result.questionId!),
            ),
            icon: const Icon(Icons.open_in_new),
            label: const Text('เปิดหน้า rubric'),
          ),
        ),
      ],
    );
  }
}

