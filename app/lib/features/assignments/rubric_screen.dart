import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'assignment.dart';
import 'assignments_providers.dart';
import 'assignments_repository.dart';
import 'question.dart';

/// Rubric of a show_work / open question: ask Gemini for a draft
/// (`POST /questions/{id}/rubric/draft`, then poll), edit the criteria, and
/// approve them with `PUT /questions/{id}/rubric` (DESIGN §9.3, §10.4).
class RubricScreen extends ConsumerStatefulWidget {
  const RubricScreen({
    super.key,
    required this.assignmentId,
    required this.questionId,
  });

  final int assignmentId;
  final int questionId;

  @override
  ConsumerState<RubricScreen> createState() => _RubricScreenState();
}

class _RubricScreenState extends ConsumerState<RubricScreen> {
  List<_CriterionRow> _rows = [];
  final _steps = TextEditingController();
  int? _loadedForQuestion;
  bool _drafting = false;
  bool _saving = false;
  String? _error;
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    for (final r in _rows) {
      r.dispose();
    }
    _steps.dispose();
    super.dispose();
  }

  void _loadFrom(Question q) {
    for (final r in _rows) {
      r.dispose();
    }
    _rows = [for (final c in q.rubricCriteria) _CriterionRow.from(c)];
    _steps.text = q.referenceSteps.join('\n');
    _loadedForQuestion = q.id;
  }

  Question? _question(Assignment? a) {
    if (a == null) return null;
    for (final q in a.questions) {
      if (q.id == widget.questionId) return q;
    }
    return null;
  }

  Future<void> _requestDraft() async {
    setState(() {
      _drafting = true;
      _error = null;
    });
    try {
      await ref
          .read(assignmentsRepositoryProvider)
          .requestRubricDraft(widget.questionId);
      final notifier = ref.read(
        assignmentDetailProvider(widget.assignmentId).notifier,
      );
      final before =
          _question(
            ref.read(assignmentDetailProvider(widget.assignmentId)).value,
          )?.rubricCriteria.length ??
          0;
      final deadline = DateTime.now().add(const Duration(minutes: 3));
      _poll?.cancel();
      _poll = Timer.periodic(const Duration(seconds: 4), (timer) async {
        if (!mounted) {
          timer.cancel();
          return;
        }
        try {
          final a = await notifier.refresh();
          final q = _question(a);
          final ready =
              q != null &&
              q.rubricCriteria.isNotEmpty &&
              (q.rubricStatus != RubricStatus.notNeeded ||
                  q.rubricCriteria.length != before);
          if (ready) {
            timer.cancel();
            setState(() {
              _loadFrom(q);
              _drafting = false;
            });
            if (mounted) {
              showMessage(context, 'AI ร่าง rubric แล้ว ตรวจและอนุมัติได้เลย');
            }
          } else if (DateTime.now().isAfter(deadline)) {
            timer.cancel();
            setState(() {
              _drafting = false;
              _error =
                  'ยังไม่ได้ร่างจาก AI (คิวอาจยังไม่ทำงาน) ลองกดใหม่ภายหลัง';
            });
          }
        } catch (e) {
          timer.cancel();
          setState(() {
            _drafting = false;
            _error = apiErrorMessage(e);
          });
        }
      });
    } catch (e) {
      setState(() {
        _drafting = false;
        _error = apiErrorMessage(e);
      });
    }
  }

  Future<void> _approve(Question q) async {
    final criteria = <RubricCriterion>[];
    for (var i = 0; i < _rows.length; i++) {
      final r = _rows[i];
      final desc = r.description.text.trim();
      final pts = double.tryParse(r.points.text.trim());
      if (desc.isEmpty || pts == null || pts < 0) {
        setState(() => _error = 'เกณฑ์ข้อ ${i + 1} ต้องมีคำอธิบายและคะแนน');
        return;
      }
      criteria.add(
        RubricCriterion(
          id: r.id,
          position: i + 1,
          description: desc,
          points: pts,
          isCore: r.isCore,
          source: r.source,
        ),
      );
    }
    if (criteria.isEmpty) {
      setState(() => _error = 'ต้องมีเกณฑ์อย่างน้อย 1 ข้อ');
      return;
    }
    final total = criteria.fold<double>(0, (s, c) => s + c.points);
    if ((total - q.maxPoints).abs() > 0.001) {
      final ok = await confirm(
        context,
        title: 'คะแนนรวมไม่เท่ากับคะแนนเต็ม',
        message:
            'เกณฑ์รวมได้ $total คะแนน แต่ข้อนี้เต็ม ${q.maxPoints} คะแนน ต้องการอนุมัติต่อหรือไม่',
        confirmLabel: 'อนุมัติ',
      );
      if (!ok) return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await ref
          .read(assignmentsRepositoryProvider)
          .saveRubric(
            widget.questionId,
            criteria: criteria,
            referenceSteps: q.type == QuestionType.showWork
                ? _steps.text
                      .split('\n')
                      .map((s) => s.trim())
                      .where((s) => s.isNotEmpty)
                      .toList()
                : null,
          );
      await ref
          .read(assignmentDetailProvider(widget.assignmentId).notifier)
          .refresh();
      if (!mounted) return;
      showMessage(context, 'อนุมัติ rubric แล้ว');
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(assignmentDetailProvider(widget.assignmentId));
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Rubric')),
      body: AsyncView(
        value: detail,
        onRetry: () =>
            ref.invalidate(assignmentDetailProvider(widget.assignmentId)),
        data: (a) {
          final q = _question(a);
          if (q == null) {
            return const ErrorView(message: 'ไม่พบคำถามนี้ในการบ้าน');
          }
          if (_loadedForQuestion != q.id) _loadFrom(q);
          return FormColumn(
            maxWidth: 680,
            children: [
              Text(
                'ข้อ ${q.position} · ${q.type.label} · เต็ม ${q.maxPoints} คะแนน',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(height: 4),
              Text(q.promptText, style: theme.textTheme.bodyMedium),
              const SizedBox(height: 8),
              Row(
                children: [
                  StatusChip(
                    label: q.rubricStatus.label,
                    color: q.rubricStatus == RubricStatus.approved
                        ? Colors.green.shade700
                        : null,
                  ),
                  const Spacer(),
                  FilledButton.tonalIcon(
                    onPressed: _drafting ? null : _requestDraft,
                    icon: _drafting
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.auto_awesome),
                    label: Text(
                      _drafting ? 'รอ AI ร่าง…' : 'ให้ AI ร่าง rubric',
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Text('เกณฑ์การให้คะแนน', style: theme.textTheme.titleMedium),
              const SizedBox(height: 8),
              for (var i = 0; i < _rows.length; i++)
                _CriterionCard(
                  index: i,
                  row: _rows[i],
                  onChanged: () => setState(() {}),
                  onRemove: () => setState(() => _rows.removeAt(i).dispose()),
                ),
              OutlinedButton.icon(
                onPressed: () =>
                    setState(() => _rows.add(_CriterionRow.empty())),
                icon: const Icon(Icons.add),
                label: const Text('เพิ่มเกณฑ์'),
              ),
              if (q.type == QuestionType.showWork) ...[
                const SizedBox(height: 16),
                TextField(
                  controller: _steps,
                  minLines: 3,
                  maxLines: 8,
                  decoration: const InputDecoration(
                    labelText: 'ขั้นตอนอ้างอิง (บรรทัดละขั้น)',
                    alignLabelWithHint: true,
                  ),
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
              ],
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: _saving ? null : () => _approve(q),
                icon: const Icon(Icons.check),
                label: const Text('บันทึกและอนุมัติ'),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _CriterionRow {
  _CriterionRow({
    this.id,
    required String description,
    required String points,
    required this.isCore,
    required this.source,
  }) : description = TextEditingController(text: description),
       points = TextEditingController(text: points);

  factory _CriterionRow.from(RubricCriterion c) => _CriterionRow(
    id: c.id,
    description: c.description,
    points: c.points == c.points.roundToDouble()
        ? c.points.toInt().toString()
        : c.points.toString(),
    isCore: c.isCore,
    source: c.source,
  );

  factory _CriterionRow.empty() => _CriterionRow(
    description: '',
    points: '1',
    isCore: false,
    source: 'teacher',
  );

  final int? id;
  final TextEditingController description;
  final TextEditingController points;
  bool isCore;
  final String source;

  void dispose() {
    description.dispose();
    points.dispose();
  }
}

class _CriterionCard extends StatelessWidget {
  const _CriterionCard({
    required this.index,
    required this.row,
    required this.onChanged,
    required this.onRemove,
  });

  final int index;
  final _CriterionRow row;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 4),
        child: Column(
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: Text('${index + 1}.'),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: row.description,
                    minLines: 1,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: 'เกณฑ์'),
                  ),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 84,
                  child: TextField(
                    controller: row.points,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(labelText: 'คะแนน'),
                  ),
                ),
                IconButton(
                  tooltip: 'ลบเกณฑ์',
                  icon: const Icon(Icons.close),
                  onPressed: onRemove,
                ),
              ],
            ),
            Row(
              children: [
                Checkbox(
                  value: row.isCore,
                  onChanged: (v) {
                    row.isCore = v ?? false;
                    onChanged();
                  },
                ),
                const Text('เกณฑ์หลัก (แก่นของคำตอบ)'),
                const Spacer(),
                Text(
                  row.source == 'ai' ? 'ร่างโดย AI' : 'โดยครู',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(width: 8),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
