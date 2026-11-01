import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../courses/course_import_screen.dart';
import '../courses/course_models.dart';
import '../courses/courses_repository.dart';
import 'assistant.dart';
import 'assistant_plan.dart';
import 'plan_cards/plan_card_common.dart';
import 'plan_cards/question_plan_card.dart';

/// The plan card under an assistant answer (DESIGN §37.6): the proposed
/// questions with ticks, where they go («เปลี่ยน»), and «สร้างเลย»; after
/// that, what was created and a way to open it. A phone shows the questions
/// folded into one line.
class AssistantPlanCard extends ConsumerWidget {
  const AssistantPlanCard({
    super.key,
    required this.message,
    required this.onNavigate,
  });

  final AssistantMessage message;

  /// Opens an app route (the desktop panel closes first).
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plan = message.plan!;
    return Card(
      key: ValueKey('assistant_plan_${message.id}'),
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
        child: switch ((message.planStatus, plan.kind, plan.result)) {
          (AssistantMessage.planReplaced, _, _) => PlanCardReplaced(plan: plan),
          (AssistantMessage.planApplied, AssistantPlan.rubricCard, final r?) =>
            _RubricApplied(result: r, onNavigate: onNavigate),
          (
            AssistantMessage.planApplied,
            AssistantPlan.indicatorsCard,
            final r?,
          ) =>
            _IndicatorsApplied(plan: plan, result: r, onNavigate: onNavigate),
          (
            AssistantMessage.planApplied,
            AssistantPlan.gradebookCard,
            final r?,
          ) =>
            PlanCardSimpleApplied(
              text: 'บันทึกการตั้งค่าสมุดคะแนนของ «${r.title}» แล้ว',
              note: 'ถ้าประกาศเกรดไว้แล้ว ต้องประกาศอีกครั้งให้เป็นค่าใหม่',
              button: 'เปิดตั้งค่าหมวดคะแนน',
              route: AppRoutes.gradebookSettings(r.courseId!),
              onNavigate: onNavigate,
            ),
          (
            AssistantMessage.planApplied,
            AssistantPlan.practiceCard,
            final r?,
          ) =>
            PlanCardSimpleApplied(
              text: 'บันทึกแบบฝึก ${r.created} ข้อเป็นร่างแล้ว',
              note: [
                'ไปตรวจและกดอนุมัติใน «คลังแบบฝึก» นักเรียนจึงจะเห็น',
                for (final s in r.skipped)
                  'ข้ามข้อ ${s.index + 1}: ${s.reason}',
              ].join('\n'),
              button: 'เปิดคลังแบบฝึก',
              route: AppRoutes.practiceBank,
              onNavigate: onNavigate,
            ),
          (AssistantMessage.planApplied, AssistantPlan.fileCard, final r?) =>
            _FileApplied(plan: plan, result: r, onNavigate: onNavigate),
          (AssistantMessage.planApplied, _, final r?) => QuestionPlanApplied(
            plan: plan,
            result: r,
            onNavigate: onNavigate,
          ),
          (_, AssistantPlan.fileCard, _) => _OpenFile(message: message),
          (_, AssistantPlan.rubricCard, _) => _OpenRubric(message: message),
          (_, AssistantPlan.gradebookCard, _) => _OpenGradebook(
            message: message,
          ),
          (_, AssistantPlan.practiceCard, _) => _OpenPractice(message: message),
          (_, AssistantPlan.indicatorsCard, _) => _OpenIndicators(
            message: message,
          ),
          _ => QuestionPlanOpen(message: message),
        },
      ),
    );
  }
}

class _OpenRubric extends StatelessWidget {
  const _OpenRubric({required this.message});

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

class _RubricApplied extends StatelessWidget {
  const _RubricApplied({required this.result, required this.onNavigate});

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

class _OpenIndicators extends ConsumerWidget {
  const _OpenIndicators({required this.message});

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

class _IndicatorsApplied extends StatelessWidget {
  const _IndicatorsApplied({
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

String _weight(double w) => w == w.roundToDouble() ? '${w.toInt()}' : '$w';

class _OpenGradebook extends StatelessWidget {
  const _OpenGradebook({required this.message});

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

class _OpenPractice extends ConsumerWidget {
  const _OpenPractice({required this.message});

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

/// What a file card does with the files, in the teacher's words.
String _fileActionLabel(String? action) => switch (action) {
  'answer_key' => 'อ่านเฉลยจากไฟล์',
  'exam' => 'อ่านข้อสอบจากไฟล์',
  'course' => 'อ่านคำอธิบายหรือโครงสร้างรายวิชาจากไฟล์',
  'lesson_plan' => 'อ่านแผนการสอนจากไฟล์',
  _ => 'อ่านไฟล์',
};

class _OpenFile extends StatelessWidget {
  const _OpenFile({required this.message});

  final AssistantMessage message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final plan = message.plan!;
    final target = plan.target;
    final where = switch (plan.fileAction) {
      'course' => 'รายวิชาใหม่ (ตรวจแล้วนำเข้าเอง)',
      _ when target.title != null =>
        '«${target.title}»${target.isNew ? ' (งานใหม่ เป็นร่าง)' : ''}',
      _ => null,
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(Icons.find_in_page_outlined, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                _fileActionLabel(plan.fileAction),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        if (where != null) Text('ไปที่: $where', style: muted),
        if (target.error case final error?)
          Text(
            '$error พิมพ์บอกผู้ช่วยว่าจะใช้กับงานหรือรายวิชาไหน',
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
          ),
        const Divider(height: 16),
        for (final d in plan.documents)
          Row(
            children: [
              Icon(Icons.description_outlined, size: 18, color: scheme.outline),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  '${d.name} (${d.pageCount} หน้า)',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
            ],
          ),
        const SizedBox(height: 6),
        Text(
          'ระบบอ่านไฟล์อยู่เบื้องหลัง แล้วครูตรวจผลในหน้าปกติก่อนใช้',
          style: muted,
        ),
        PlanCardFooter(
          message: message,
          label: 'ส่งไฟล์ไปอ่าน',
          icon: Icons.send_outlined,
          enabled: target.error == null,
        ),
      ],
    );
  }
}

class _FileApplied extends ConsumerWidget {
  const _FileApplied({
    required this.plan,
    required this.result,
    required this.onNavigate,
  });

  final AssistantPlan plan;
  final PlanResult result;
  final ValueChanged<String> onNavigate;

  /// A course read opens the import screen once it is done.
  Future<void> _openImport(BuildContext context, WidgetRef ref) async {
    final repo = ref.read(coursesRepositoryProvider);
    final messenger = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);
    try {
      final extraction = await repo.extraction(result.extractionId!);
      if (extraction.status != 'done') {
        messenger.showSnackBar(
          SnackBar(
            content: Text(
              extraction.status == 'failed'
                  ? (extraction.error ?? 'อ่านไฟล์ไม่สำเร็จ')
                  : 'ยังอ่านไฟล์ไม่เสร็จ ลองอีกครั้งในอีกสักครู่',
            ),
          ),
        );
        return;
      }
      final lessonPlan = plan.fileAction == 'lesson_plan';
      final course = lessonPlan && result.courseId != null
          ? await repo.get(result.courseId!)
          : null;
      await navigator.push(
        MaterialPageRoute<void>(
          builder: (_) => CourseImportScreen(
            extraction: extraction,
            purpose: lessonPlan
                ? CourseDocumentPurpose.lessonPlan
                : CourseDocumentPurpose.course,
            course: course,
          ),
        ),
      );
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final (button, open) = switch (plan.fileAction) {
      'answer_key' => (
        'เปิดหน้าเฉลย',
        () => onNavigate(AppRoutes.answerKey(result.assignmentId!)),
      ),
      'exam' => (
        'เปิดข้อสอบ',
        () => onNavigate(AppRoutes.exam(result.assignmentId!)),
      ),
      _ => ('ตรวจและนำเข้า', () => _openImport(context, ref)),
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.check_circle, color: theme.colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                result.cached
                    ? 'อ่านไฟล์นี้ไว้แล้ว ได้ผลทันที'
                    : 'ส่งไฟล์ไปอ่านแล้ว',
                key: const ValueKey('assistant_plan_done'),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        Text(
          '${_fileActionLabel(plan.fileAction)} ใช้เวลาไม่กี่นาที ผลเป็นร่างให้ครูตรวจก่อนใช้',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton.icon(
            key: const ValueKey('assistant_plan_open'),
            onPressed: open,
            icon: const Icon(Icons.open_in_new),
            label: Text(button),
          ),
        ),
      ],
    );
  }
}
