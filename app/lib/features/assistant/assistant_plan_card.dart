import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../../core/widgets/small_spinner.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_repository.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_repository.dart';
import '../courses/course_import_screen.dart';
import '../courses/course_models.dart';
import '../courses/courses_repository.dart';
import 'assistant.dart';
import 'assistant_plan.dart';

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
          (AssistantMessage.planReplaced, _, _) => _Replaced(plan: plan),
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
            _SimpleApplied(
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
            _SimpleApplied(
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
          (AssistantMessage.planApplied, _, final r?) => _Applied(
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
          _ => _Open(message: message),
        },
      ),
    );
  }
}

class _Replaced extends StatelessWidget {
  const _Replaced({required this.plan});

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

class _Applied extends StatelessWidget {
  const _Applied({
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
    final exam = result.kind == PlanKind.exam;
    final rubric = plan.questions.any(
      (q) => q.type == 'open' || q.type == 'show_work',
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.check_circle, color: theme.colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'สร้างแล้ว ${result.created} ข้อใน «${result.title}»',
                key: const ValueKey('assistant_plan_done'),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        for (final s in result.skipped)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text('ข้ามข้อ ${s.index + 1}: ${s.reason}', style: muted),
          ),
        if (result.kind == PlanKind.freeform)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'ตรวจเฉลยแล้วกด «อนุมัติเฉลย» ในหน้าการบ้านก่อนเปิดให้ส่ง',
              style: muted,
            ),
          )
        else if (!exam && rubric)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'ข้อแสดงวิธีทำและอัตนัยต้องอนุมัติ rubric ก่อนสร้าง layout',
              style: muted,
            ),
          ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton.icon(
            key: const ValueKey('assistant_plan_open'),
            onPressed: () => onNavigate(
              exam
                  ? AppRoutes.exam(result.assignmentId!)
                  : AppRoutes.assignment(result.assignmentId!),
            ),
            icon: const Icon(Icons.open_in_new),
            label: Text(exam ? 'เปิดข้อสอบ' : 'เปิดการบ้าน'),
          ),
        ),
      ],
    );
  }
}

class _Open extends ConsumerWidget {
  const _Open({required this.message});

  final AssistantMessage message;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final plans = ref.watch(assistantPlansProvider.notifier);
    ref.watch(assistantPlansProvider);
    final ui = plans.of(message);
    final target = plans.targetOf(message);
    final plan = message.plan!;
    final busy = ui.applying || ref.watch(assistantChatProvider).sending;
    final canApply = !busy && ui.selected.isNotEmpty && target.error == null;
    final id = message.id;

    final questions = [
      for (final (i, q) in plan.questions.indexed)
        _QuestionTile(
          key: ValueKey('assistant_plan_q_${id}_$i'),
          number: i + 1,
          question: q,
          kind: target.kind,
          selected: ui.selected.contains(i),
          onToggle: q.usableFor(target.kind) && !busy
              ? () => plans.toggle(message, i)
              : null,
        ),
    ];
    final counts = <String, int>{};
    for (final q in plan.questions) {
      counts[q.typeLabel] = (counts[q.typeLabel] ?? 0) + 1;
    }
    final summary =
        'ข้อ 1–${plan.questions.length} · '
        '${counts.entries.map((e) => '${e.key} ${e.value} ข้อ').join(', ')}';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(Icons.playlist_add, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'ข้อที่เสนอ ${plan.questions.length} ข้อ',
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    target.title == null
                        ? 'ยังไม่ได้เลือกงาน'
                        : 'ไปที่: «${target.title}»',
                    key: ValueKey('assistant_plan_target_$id'),
                    style: theme.textTheme.bodyMedium,
                  ),
                  Text(
                    [
                      ?target.classroom,
                      PlanKind.label(target.kind),
                      if (target.isNew) 'งานใหม่ (ร่าง)',
                    ].join(' · '),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            TextButton(
              key: ValueKey('assistant_plan_change_$id'),
              onPressed: busy
                  ? null
                  : () async {
                      final picked = await showDialog<PlanTarget>(
                        context: context,
                        builder: (_) => _TargetDialog(current: target),
                      );
                      if (picked != null) plans.setTarget(message, picked);
                    },
              child: const Text('เปลี่ยน'),
            ),
          ],
        ),
        if (target.error case final error?)
          Text(
            error,
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
          )
        else if (target.isReady && target.kind != PlanKind.exam)
          Text(
            'งานนี้พร้อมแล้ว เพิ่มข้อแล้วงานจะกลับเป็นร่าง และต้องสร้าง layout ใหม่',
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        const Divider(height: 16),
        if (context.windowSize == WindowSize.compact)
          ExpansionTile(
            key: ValueKey('assistant_plan_fold_$id'),
            tilePadding: EdgeInsets.zero,
            childrenPadding: EdgeInsets.zero,
            title: Text(summary, style: theme.textTheme.bodyMedium),
            children: questions,
          )
        else
          ...questions,
        if (ui.error case final error?)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              error,
              key: ValueKey('assistant_plan_error_$id'),
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
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
              onPressed: canApply ? () => plans.apply(id!) : null,
              icon: const Icon(Icons.add_task),
              label: Text('สร้างเลย (${ui.selected.length} ข้อ)'),
            ),
          ],
        ),
      ],
    );
  }
}

class _QuestionTile extends StatelessWidget {
  const _QuestionTile({
    super.key,
    required this.number,
    required this.question,
    required this.kind,
    required this.selected,
    required this.onToggle,
  });

  final int number;
  final PlanQuestion question;
  final String? kind;
  final bool selected;
  final VoidCallback? onToggle;

  static const _thaiLetters = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ'];
  static const _latinLetters = ['A', 'B', 'C', 'D', 'E', 'F'];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final q = question;
    final letters = kind == PlanKind.exam ? _thaiLetters : _latinLetters;
    final unsupported = q.unsupportedFor(kind);
    final answer = switch (q.type) {
      'true_false' when q.correctOptions.isNotEmpty =>
        q.correctOptions.first == 1 ? 'ถูก' : 'ผิด',
      'numeric' => q.numericValue,
      'short' || 'show_work' =>
        q.acceptedAnswers.isNotEmpty
            ? q.acceptedAnswers.join(' หรือ ')
            : q.numericValue,
      _ => null,
    };

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Checkbox.adaptive(
            value: selected,
            onChanged: (_) => onToggle?.call(),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'ข้อ $number · ${q.typeLabel} · ${pointsLabel(q.points)}',
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                SelectableText(q.promptText, style: theme.textTheme.bodyMedium),
                for (final (i, option) in q.options.indexed)
                  _Option(
                    label: '${letters[i]}. $option',
                    correct: q.correctOptions.contains(i + 1),
                  ),
                if (answer != null)
                  Text(
                    'เฉลย: $answer',
                    style: theme.textTheme.titleSmall?.copyWith(
                      color: scheme.primary,
                    ),
                  ),
                if (q.referenceSteps.isNotEmpty)
                  Text(
                    'ขั้นตอน: ${q.referenceSteps.join(' → ')}',
                    style: muted,
                  ),
                if (q.modelAnswer case final model?)
                  Text('แนวคำตอบ: $model', style: muted),
                if (q.explanation.isNotEmpty)
                  Text('ทำไมถูก: ${q.explanation}', style: muted),
                if (q.indicatorCodes.isNotEmpty ||
                    q.needsFigure ||
                    q.incompleteKey ||
                    unsupported != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      children: [
                        for (final code in q.indicatorCodes)
                          Chip(
                            visualDensity: VisualDensity.compact,
                            label: Text(code),
                          ),
                        if (q.needsFigure)
                          const Chip(
                            visualDensity: VisualDensity.compact,
                            avatar: Icon(Icons.image_outlined),
                            label: Text('ต้องใส่รูปเอง'),
                          ),
                        if (q.incompleteKey)
                          Chip(
                            visualDensity: VisualDensity.compact,
                            avatar: Icon(
                              Icons.warning_amber,
                              color: scheme.error,
                            ),
                            label: const Text('เฉลยไม่ครบ'),
                          ),
                      ],
                    ),
                  ),
                if (unsupported != null)
                  Text(
                    unsupported,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: scheme.error,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Option extends StatelessWidget {
  const _Option({required this.label, required this.correct});

  final String label;
  final bool correct;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Padding(
      padding: const EdgeInsets.only(top: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            correct ? Icons.check_circle : Icons.circle_outlined,
            size: 16,
            color: correct ? scheme.primary : scheme.outline,
          ),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              label,
              style: correct
                  ? theme.textTheme.titleSmall?.copyWith(color: scheme.primary)
                  : theme.textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }
}

/// «เปลี่ยน»: an assignment that is not closed, or a new one.
class _TargetDialog extends ConsumerStatefulWidget {
  const _TargetDialog({required this.current});

  final PlanTarget current;

  @override
  ConsumerState<_TargetDialog> createState() => _TargetDialogState();
}

class _TargetDialogState extends ConsumerState<_TargetDialog> {
  late final Future<(List<Assignment>, List<Classroom>)> _load = () async {
    final assignments = await ref.read(assignmentsRepositoryProvider).list();
    final classrooms = await ref.read(classroomsRepositoryProvider).list();
    return (
      assignments.where((a) => a.status != 'closed').toList(),
      classrooms.where((c) => !c.isClosed).toList(),
    );
  }();

  late bool _new = widget.current.isNew;
  late final _title = TextEditingController(
    text: widget.current.isNew ? widget.current.title : '',
  );
  late int? _classroomId = widget.current.newClassroomId;
  late String _kind = widget.current.kind ?? PlanKind.worksheet;

  @override
  void dispose() {
    _title.dispose();
    super.dispose();
  }

  static String _kindOf(Assignment a) => a.isExam
      ? PlanKind.exam
      : a.mode == AssignmentMode.freeform
      ? PlanKind.freeform
      : PlanKind.worksheet;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AlertDialog(
      title: const Text('เพิ่มข้อไปที่งานไหน'),
      scrollable: true,
      content: SizedBox(
        width: 480,
        child: FutureBuilder(
          future: _load,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return Text(apiErrorMessage(snapshot.error!));
            }
            final data = snapshot.data;
            if (data == null) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator.adaptive()),
              );
            }
            final (assignments, classrooms) = data;
            return Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SegmentedButton<bool>(
                  segments: const [
                    ButtonSegment(value: false, label: Text('งานที่มีอยู่')),
                    ButtonSegment(value: true, label: Text('งานใหม่')),
                  ],
                  selected: {_new},
                  onSelectionChanged: (s) => setState(() => _new = s.first),
                ),
                const SizedBox(height: 12),
                if (!_new) ...[
                  if (assignments.isEmpty)
                    const Text('ยังไม่มีงานที่เพิ่มข้อได้'),
                  for (final a in assignments)
                    ListTile(
                      key: ValueKey('assistant_target_${a.id}'),
                      contentPadding: EdgeInsets.zero,
                      selected: a.id == widget.current.assignmentId,
                      title: Text(a.title),
                      subtitle: Text(
                        [
                          ?a.classroomName,
                          PlanKind.label(_kindOf(a)),
                          if (a.status == 'ready') 'พร้อมแล้ว' else 'ร่าง',
                        ].join(' · '),
                      ),
                      onTap: () => Navigator.of(context).pop(
                        PlanTarget(
                          assignmentId: a.id,
                          title: a.title,
                          kind: _kindOf(a),
                          classroom: a.classroomName,
                          status: a.status,
                        ),
                      ),
                    ),
                ] else ...[
                  DropdownButtonFormField<int>(
                    key: const ValueKey('assistant_target_classroom'),
                    initialValue: classrooms.any((c) => c.id == _classroomId)
                        ? _classroomId
                        : null,
                    decoration: const InputDecoration(labelText: 'ห้อง'),
                    items: [
                      for (final c in classrooms)
                        DropdownMenuItem(value: c.id, child: Text(c.name)),
                    ],
                    onChanged: (v) => setState(() => _classroomId = v),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    key: const ValueKey('assistant_target_title'),
                    controller: _title,
                    maxLength: 255,
                    decoration: const InputDecoration(labelText: 'ชื่องาน'),
                  ),
                  Text('ชนิด', style: theme.textTheme.labelLarge),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    children: [
                      for (final kind in [
                        PlanKind.worksheet,
                        PlanKind.freeform,
                        PlanKind.exam,
                      ])
                        ChoiceChip(
                          label: Text(PlanKind.label(kind)),
                          selected: _kind == kind,
                          onSelected: (_) => setState(() => _kind = kind),
                        ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'งานใหม่เป็นร่าง ยังไม่มีวันส่ง และยังไม่ส่งไป Google Classroom',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ],
            );
          },
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        if (_new)
          FilledButton(
            key: const ValueKey('assistant_target_use'),
            onPressed: () async {
              final classrooms = (await _load).$2;
              final title = _title.text.trim();
              final id = _classroomId;
              if (id == null || title.isEmpty || !context.mounted) return;
              Navigator.of(context).pop(
                PlanTarget(
                  newClassroomId: id,
                  title: title,
                  kind: _kind,
                  classroom: classrooms
                      .where((c) => c.id == id)
                      .firstOrNull
                      ?.name,
                ),
              );
            },
            child: const Text('ใช้งานนี้'),
          ),
      ],
    );
  }
}

/// The error, the spinner and the button at the bottom of an open card.
class _Footer extends ConsumerWidget {
  const _Footer({
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

String _points(double p) => p == p.roundToDouble() ? '${p.toInt()}' : '$p';

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
            '${_points(q.maxPoints)} คะแนน',
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
                  '${_points(c.points)} คะแนน',
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
        _Footer(
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
        _Footer(
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

/// After a gradebook or practice card: what was saved and where to look.
class _SimpleApplied extends StatelessWidget {
  const _SimpleApplied({
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
        _Footer(
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
        _Footer(
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
        _Footer(
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
