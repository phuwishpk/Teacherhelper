import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/router/app_router.dart';
import '../assistant.dart';
import '../assistant_plan.dart';
import 'plan_card_common.dart';

class IndicatorsPlanOpen extends ConsumerWidget {
  const IndicatorsPlanOpen({super.key, required this.message});

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
            Icon(Icons.checklist, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'ตัวชี้วัดที่เสนอ ${plan.items.length} รายการ',
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        if (plan.target.title case final title?)
          Text('สำหรับข้อใน «$title»', style: muted),
        if (plan.items.any((i) => i.isNew))
          Text('ตัวชี้วัดที่เพิ่มเองจะแชร์ให้ครูทุกคนในโรงเรียน', style: muted),
        const Divider(height: 16),
        for (final (i, item) in plan.items.indexed)
          Padding(
            key: ValueKey('assistant_plan_q_${id}_$i'),
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Checkbox.adaptive(
                  value: ui.selected.contains(i),
                  onChanged: item.blocked || busy
                      ? null
                      : (_) => plans.toggle(message, i),
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.isNew
                            ? 'ใหม่: ${item.code.isEmpty ? '(ระบบตั้งรหัสให้)' : item.code} ${item.name}'
                            : '${item.code} ${item.name}',
                        style: theme.textTheme.bodyMedium,
                      ),
                      Text(
                        item.isNew
                            ? 'ใต้ ${item.parentCode ?? '?'} ${item.parentName ?? ''}'
                            : 'เพิ่มลง «ตัวชี้วัดของฉัน»',
                        style: muted,
                      ),
                      if (item.questionPositions.isNotEmpty)
                        Text(
                          item.flags.contains(IndicatorItem.flagNotInScope)
                              ? 'เสนอให้ข้อ ${item.questionPositions.join(', ')} ไม่ได้: ไม่อยู่ในแผนหรือรายวิชาของงานนี้'
                              : 'เสนอให้ข้อ ${item.questionPositions.join(', ')} (ยืนยันในหน้าจับคู่ตัวชี้วัด)',
                          style: muted,
                        ),
                      if (item.reason.isNotEmpty)
                        Text('เหตุผล: ${item.reason}', style: muted),
                      for (final (flag, label) in [
                        (IndicatorItem.flagNotFound, 'ไม่พบรหัสนี้ในหลักสูตร'),
                        (IndicatorItem.flagCodeTaken, 'รหัสนี้มีอยู่แล้ว'),
                        (
                          IndicatorItem.flagAlreadyMine,
                          'อยู่ในตัวชี้วัดของฉันแล้ว',
                        ),
                      ])
                        if (item.flags.contains(flag))
                          Text(
                            label,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: flag == IndicatorItem.flagAlreadyMine
                                  ? scheme.onSurfaceVariant
                                  : scheme.error,
                            ),
                          ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        PlanCardFooter(
          message: message,
          label: 'ทำตามที่เลือก (${ui.selected.length})',
          icon: Icons.add_task,
          enabled: ui.selected.isNotEmpty,
        ),
      ],
    );
  }
}

class IndicatorsPlanApplied extends StatelessWidget {
  const IndicatorsPlanApplied({
    super.key,
    required this.plan,
    required this.result,
    required this.onNavigate,
  });

  final AssistantPlan plan;
  final PlanResult result;
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final done = [
      if (result.created > 0) 'เพิ่มตัวชี้วัดใหม่ ${result.created} ตัว',
      if (result.addedMine > 0)
        'เพิ่มลงตัวชี้วัดของฉัน ${result.addedMine} ตัว',
      if (result.mapped > 0) 'เสนอให้ข้อ ${result.mapped} รายการ',
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.check_circle, color: theme.colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                done.isEmpty ? 'ไม่มีอะไรเปลี่ยน' : done.join(' · '),
                key: const ValueKey('assistant_plan_done'),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        for (final s in result.skipped)
          Text(
            'ข้าม ${plan.items.elementAtOrNull(s.index)?.code ?? '${s.index + 1}'}: ${s.reason}',
            style: muted,
          ),
        if (result.mapped > 0)
          Text('ไปยืนยันการจับคู่ในหน้าจับคู่ตัวชี้วัด', style: muted),
        Wrap(
          alignment: WrapAlignment.end,
          children: [
            TextButton.icon(
              key: const ValueKey('assistant_plan_open'),
              onPressed: () => onNavigate(AppRoutes.myIndicators),
              icon: const Icon(Icons.open_in_new),
              label: const Text('เปิดตัวชี้วัดของฉัน'),
            ),
            if (result.mapped > 0 && result.assignmentId != null)
              TextButton.icon(
                key: const ValueKey('assistant_plan_open_mapping'),
                onPressed: () => onNavigate(
                  AppRoutes.indicatorMapping(result.assignmentId!),
                ),
                icon: const Icon(Icons.link),
                label: const Text('เปิดหน้าจับคู่ตัวชี้วัด'),
              ),
          ],
        ),
      ],
    );
  }
}

