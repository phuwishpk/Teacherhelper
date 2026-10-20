import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_repository.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_repository.dart';
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
        child: switch (message.planStatus) {
          AssistantMessage.planApplied when plan.result != null => _Applied(
            plan: plan,
            result: plan.result!,
            onNavigate: onNavigate,
          ),
          AssistantMessage.planReplaced => _Replaced(plan: plan),
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
            'การ์ด ${plan.questions.length} ข้อนี้ถูกแทนด้วยการ์ดใหม่',
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
                  ? AppRoutes.exam(result.assignmentId)
                  : AppRoutes.assignment(result.assignmentId),
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
                child: SizedBox.square(
                  dimension: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
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
          Checkbox(value: selected, onChanged: (_) => onToggle?.call()),
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
                child: Center(child: CircularProgressIndicator()),
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
