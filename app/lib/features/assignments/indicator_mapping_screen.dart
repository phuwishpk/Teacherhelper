import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/ai_guidance_field.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../courses/indicator_widgets.dart';
import '../settings/ai_key_errors.dart';
import 'assignments_providers.dart';
import 'indicator_mapping.dart';
import 'question.dart';
import 'skills_picker.dart';

/// "จับคู่ข้อกับตัวชี้วัด" (DESIGN §20.3): every question with its
/// indicators and what Gemini suggests from the linked lesson plan. The
/// teacher accepts, removes or picks indicators, then saves the changed
/// questions (`PUT …/indicator-mapping`). Questions left without an
/// indicator are a warning only: their scores are not counted in the charts.
class IndicatorMappingScreen extends ConsumerStatefulWidget {
  const IndicatorMappingScreen({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  ConsumerState<IndicatorMappingScreen> createState() =>
      _IndicatorMappingScreenState();
}

class _IndicatorMappingScreenState
    extends ConsumerState<IndicatorMappingScreen> {
  /// The teacher's unsaved choices by question id; questions not in here
  /// keep what the server has.
  final Map<int, List<Skill>> _draft = {};
  bool _requesting = false;
  bool _saving = false;

  int get _id => widget.assignmentId;

  List<Skill> _selected(QuestionIndicators q) =>
      _draft[q.questionId] ?? q.skills;

  bool _changed(QuestionIndicators q) {
    final draft = _draft[q.questionId];
    if (draft == null) return false;
    final a = {for (final s in draft) s.id};
    final b = {for (final s in q.skills) s.id};
    return a.length != b.length || !a.containsAll(b);
  }

  List<QuestionIndicators> _changedQuestions(IndicatorSuggestions data) =>
      data.questions.where(_changed).toList();

  void _set(QuestionIndicators q, List<Skill> skills) {
    setState(() => _draft[q.questionId] = skills);
  }

  void _accept(QuestionIndicators q, Skill skill) {
    final current = _selected(q);
    if (current.any((s) => s.id == skill.id)) return;
    _set(q, [...current, skill]);
  }

  /// "ใช้ข้อเสนอทั้งหมด": adds every suggestion to its question (keeps what
  /// the teacher already chose).
  void _acceptAll(IndicatorSuggestions data) {
    setState(() {
      for (final q in data.questions) {
        if (q.suggestions.isEmpty) continue;
        final current = _selected(q);
        final ids = {for (final s in current) s.id};
        _draft[q.questionId] = [
          ...current,
          for (final s in q.suggestions)
            if (ids.add(s.skill.id)) s.skill,
        ];
      }
    });
  }

  /// Asks for the guidance first (prefilled with the last round's), then
  /// queues the suggestion.
  Future<void> _request(IndicatorSuggestions data) async {
    final choice = await showGuidanceDialog(
      context,
      title: 'ให้ AI เสนอตัวชี้วัด',
      message: 'AI เลือกได้เฉพาะตัวชี้วัดของแผนนี้ เพิ่มคำแนะนำได้ถ้าต้องการ',
      hintText: kGuidanceHintIndicators,
      initial: data.state.guidance,
    );
    if (choice == null || !mounted) return;
    setState(() => _requesting = true);
    try {
      final state = await ref
          .read(indicatorSuggestionsProvider(_id).notifier)
          .request(guidance: choice.guidance);
      if (mounted && state.queued && state.guidance != choice.guidance) {
        showMessage(
          context,
          'AI กำลังเสนอรอบก่อนอยู่ รอบนี้ใช้คำแนะนำของรอบนั้น '
          'รอให้เสร็จแล้วส่งใหม่ได้',
        );
      }
    } catch (e) {
      if (mounted) showAiError(context, e);
    } finally {
      if (mounted) setState(() => _requesting = false);
    }
  }

  Future<void> _save(IndicatorSuggestions data) async {
    final changed = _changedQuestions(data);
    if (changed.isEmpty) return;
    setState(() => _saving = true);
    try {
      final saved = await ref
          .read(indicatorSuggestionsProvider(_id).notifier)
          .save({
            for (final q in changed)
              q.questionId: [for (final s in _selected(q)) s.id],
          });
      if (!mounted) return;
      setState(_draft.clear);
      showMessage(
        context,
        'บันทึกตัวชี้วัดแล้ว ${saved.changedQuestionCount ?? changed.length} ข้อ',
      );
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _pick(QuestionIndicators q, IndicatorSuggestions data) async {
    final assignment = ref.read(assignmentDetailProvider(_id)).value;
    final classroomId = assignment?.classroomId;
    final grade = classroomId == null
        ? null
        : ref.read(classroomProvider(classroomId)).value?.gradeLevel;
    final picked = await showSkillsPicker(
      context,
      subjectId:
          assignment?.subjectId ?? data.planIndicators.firstOrNull?.subjectId,
      grade: grade ?? data.planIndicators.firstOrNull?.gradeLevel,
      selected: _selected(q),
    );
    if (picked != null && mounted) _set(q, picked);
  }

  Future<void> _leave(bool didPop) async {
    if (didPop) return;
    final ok = await confirm(
      context,
      title: 'ทิ้งการแก้ไข?',
      message: 'ตัวชี้วัดที่เลือกไว้ยังไม่ได้บันทึก',
      confirmLabel: 'ทิ้ง',
      destructive: true,
    );
    if (ok && mounted) {
      setState(_draft.clear);
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final value = ref.watch(indicatorSuggestionsProvider(_id));
    // Loaded for the subject and grade of the indicator search.
    final assignment = ref.watch(assignmentDetailProvider(_id)).value;
    if (assignment != null) {
      ref.watch(classroomProvider(assignment.classroomId));
    }
    final data = value.value;
    final dirty = data != null && _changedQuestions(data).isNotEmpty;

    return PopScope(
      canPop: !dirty,
      onPopInvokedWithResult: (didPop, _) => _leave(didPop),
      child: Scaffold(
        appBar: AppBar(
          title: const Text('จับคู่ตัวชี้วัด'),
          actions: [
            TextButton(
              key: const ValueKey('mapping_save'),
              onPressed: dirty && !_saving ? () => _save(data) : null,
              child: Text(_saving ? 'กำลังบันทึก…' : 'บันทึก'),
            ),
          ],
        ),
        body: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(indicatorSuggestionsProvider(_id)),
          data: (data) => RefreshIndicator(
            onRefresh: () =>
                ref.read(indicatorSuggestionsProvider(_id).notifier).refresh(),
            child: ContentColumn(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              child: ListView(
                children: [
                  _PlanCard(
                    data: data,
                    requesting: _requesting,
                    onRequest: () => _request(data),
                    onAcceptAll: () => _acceptAll(data),
                  ),
                  if (unmappedWarningText(
                        data.questions
                            .where((q) => _selected(q).isEmpty)
                            .length,
                      )
                      case final warning?) ...[
                    const SizedBox(height: 8),
                    UnmappedWarning(
                      key: const ValueKey('mapping_unmapped'),
                      text: warning,
                    ),
                  ],
                  const SizedBox(height: 8),
                  if (data.questions.isEmpty)
                    const Card(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: Text(
                          'การบ้านนี้ยังไม่มีข้อ',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                  for (final q in data.questions)
                    _QuestionCard(
                      key: ValueKey('mapping_q_${q.questionId}'),
                      question: q,
                      selected: _selected(q),
                      changed: _changed(q),
                      onAccept: (s) => _accept(q, s),
                      onRemove: (s) => _set(q, [
                        for (final i in _selected(q))
                          if (i.id != s.id) i,
                      ]),
                      onPick: () => _pick(q, data),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The warning of DESIGN §20.3 ("มี n ข้อยังไม่ผูกตัวชี้วัด …"): shown on the
/// assignment and on the mapping screen; it never blocks anything.
class UnmappedWarning extends StatelessWidget {
  const UnmappedWarning({super.key, required this.text, this.onTap});

  final String text;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: scheme.errorContainer,
      child: ListTile(
        leading: Icon(
          Icons.warning_amber_rounded,
          color: scheme.onErrorContainer,
        ),
        title: Text(text, style: TextStyle(color: scheme.onErrorContainer)),
        trailing: onTap == null
            ? null
            : Icon(Icons.chevron_right, color: scheme.onErrorContainer),
        onTap: onTap,
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.data,
    required this.requesting,
    required this.onRequest,
    required this.onAcceptAll,
  });

  final IndicatorSuggestions data;
  final bool requesting;
  final VoidCallback onRequest;
  final VoidCallback onAcceptAll;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final plan = data.lessonPlan;
    final state = data.state;
    final busy = requesting || state.queued;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              plan == null ? 'ยังไม่ผูกแผนการสอน' : 'แผน: ${plan.title}',
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 8),
            if (plan == null)
              const Text(
                'เลือกตัวชี้วัดของแต่ละข้อเองได้เลย หรือเลือกแผนการสอนที่หน้าแก้ไขการบ้าน '
                'แล้วให้ AI เสนอตัวชี้วัดจากแผนนั้น',
              )
            else if (data.planIndicators.isEmpty)
              const Text(
                'แผนนี้ยังไม่มีตัวชี้วัด เพิ่มตัวชี้วัดของแผนที่หน้ารายวิชาก่อน '
                'จึงให้ AI เสนอได้',
              )
            else ...[
              Text(
                'AI เลือกได้เฉพาะตัวชี้วัดของแผนนี้',
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 6),
              IndicatorChips(indicators: data.planIndicators),
            ],
            if (state.queued) ...[
              const SizedBox(height: 12),
              const LinearProgressIndicator(),
              const SizedBox(height: 6),
              const Text(
                'AI กำลังเสนอตัวชี้วัด อาจใช้เวลาหนึ่งถึงสองนาที',
                key: ValueKey('mapping_queued'),
              ),
            ] else if (state.status == SuggestStatus.failed) ...[
              const SizedBox(height: 12),
              Text(
                state.errorMessage ?? 'AI เสนอตัวชี้วัดไม่สำเร็จ',
                key: const ValueKey('mapping_failed'),
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ] else if (state.status == SuggestStatus.done) ...[
              const SizedBox(height: 12),
              Text(
                [
                  'AI เสนอตัวชี้วัดให้ ${state.suggestedQuestionCount ?? 0} ข้อ',
                  if ((state.droppedCodeCount ?? 0) > 0)
                    'ตัดรหัสที่ไม่อยู่ในแผนออก ${state.droppedCodeCount} รหัส',
                ].join(' · '),
                key: const ValueKey('mapping_done'),
              ),
            ],
            if (state.status != SuggestStatus.none &&
                state.guidance != null) ...[
              const SizedBox(height: 6),
              GuidanceUsedNote(guidance: state.guidance!),
            ],
            if (plan != null) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  FilledButton.tonalIcon(
                    key: const ValueKey('mapping_suggest'),
                    onPressed: data.canSuggest && !busy ? onRequest : null,
                    icon: const Icon(Icons.auto_awesome_outlined),
                    label: Text(
                      state.status == SuggestStatus.none
                          ? 'ให้ AI เสนอตัวชี้วัด'
                          : 'ให้ AI เสนอใหม่',
                    ),
                  ),
                  if (data.hasSuggestions)
                    OutlinedButton.icon(
                      key: const ValueKey('mapping_accept_all'),
                      onPressed: onAcceptAll,
                      icon: const Icon(Icons.done_all),
                      label: const Text('ใช้ข้อเสนอทั้งหมด'),
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _QuestionCard extends StatelessWidget {
  const _QuestionCard({
    super.key,
    required this.question,
    required this.selected,
    required this.changed,
    required this.onAccept,
    required this.onRemove,
    required this.onPick,
  });

  final QuestionIndicators question;
  final List<Skill> selected;
  final bool changed;
  final ValueChanged<Skill> onAccept;
  final ValueChanged<Skill> onRemove;
  final VoidCallback onPick;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final q = question;
    final ids = {for (final s in selected) s.id};
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(radius: 14, child: Text('${q.position}')),
                const SizedBox(width: 8),
                if (q.type != null)
                  Text(q.type!.label, style: theme.textTheme.labelMedium),
                const Spacer(),
                if (changed)
                  StatusChip(
                    label: 'ยังไม่บันทึก',
                    color: theme.colorScheme.primary,
                  )
                else if (selected.isEmpty)
                  StatusChip(
                    label: 'ยังไม่ผูกตัวชี้วัด',
                    color: theme.colorScheme.error,
                  ),
              ],
            ),
            if (q.promptText.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(q.promptText, maxLines: 3, overflow: TextOverflow.ellipsis),
            ],
            const SizedBox(height: 8),
            IndicatorChips(indicators: selected, onRemove: onRemove),
            if (q.suggestions.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('AI เสนอ', style: theme.textTheme.labelLarge),
              for (final s in q.suggestions)
                ListTile(
                  key: ValueKey('suggestion_${q.questionId}_${s.skill.id}'),
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  leading: const Icon(Icons.auto_awesome_outlined, size: 20),
                  title: Text('${s.skill.code} ${s.skill.name}'),
                  subtitle: s.reason == null || s.reason!.isEmpty
                      ? null
                      : Text(s.reason!),
                  trailing: ids.contains(s.skill.id)
                      ? Icon(
                          Icons.check_circle,
                          color: Colors.green.shade700,
                          semanticLabel: 'ใช้แล้ว',
                        )
                      : TextButton(
                          onPressed: () => onAccept(s.skill),
                          child: const Text('ใช้'),
                        ),
                ),
            ],
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                key: ValueKey('mapping_pick_${q.questionId}'),
                onPressed: onPick,
                icon: const Icon(Icons.checklist),
                label: const Text('เลือกตัวชี้วัดเอง'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
