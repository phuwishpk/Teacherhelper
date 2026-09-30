import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import '../dashboard/analytics_models.dart';
import '../review/review_labels.dart';
import 'exam_option_analysis.dart';

/// "วิเคราะห์ตัวเลือก" of an exam (DESIGN §22.13), on the analytics screen:
/// per master question (all versions together) how many chose each option,
/// no mark and several marks, the top / bottom 27% and the distractor
/// warnings. Flags are words next to an icon, never color alone.
class ExamOptionAnalysisCard extends ConsumerStatefulWidget {
  const ExamOptionAnalysisCard({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<ExamOptionAnalysisCard> createState() =>
      _ExamOptionAnalysisCardState();
}

class _ExamOptionAnalysisCardState
    extends ConsumerState<ExamOptionAnalysisCard> {
  bool _flaggedOnly = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final value = ref.watch(examOptionAnalysisProvider(widget.examId));
    return Card(
      key: const ValueKey('exam_option_analysis'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('วิเคราะห์ตัวเลือก', style: theme.textTheme.titleMedium),
            const SizedBox(height: 2),
            Text(
              'จำนวนคนที่เลือกแต่ละตัวเลือกของข้อต้นฉบับ รวมทุกชุด '
              'จากผลที่ประกาศแล้ว',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 12),
            AsyncView(
              value: value,
              onRetry: () =>
                  ref.invalidate(examOptionAnalysisProvider(widget.examId)),
              data: _content,
            ),
          ],
        ),
      ),
    );
  }

  Widget _content(ExamOptionAnalysis a) {
    final theme = Theme.of(context);
    if (a.publishedCount == 0 || a.questions.isEmpty) {
      return const Text('ยังไม่มีผลที่ประกาศแล้ว');
    }
    final flagged = a.flagged;
    final shown = _flaggedOnly ? flagged : a.questions;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          a.groupsReady
              ? 'กลุ่มสูงและกลุ่มต่ำ (27%) กลุ่มละ ${a.groupSize ?? 0} คน '
                    'ตามคะแนนรวมที่ใช้จริง'
              : 'กลุ่มสูง/ต่ำและป้ายเตือนแสดงเมื่อประกาศผลแล้ว '
                    '${a.minCountForR} คนขึ้นไป (ตอนนี้ ${a.publishedCount} คน)',
          key: const ValueKey('option_groups_note'),
          style: theme.textTheme.bodySmall,
        ),
        if (flagged.isNotEmpty) ...[
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: FilterChip(
              key: const ValueKey('option_flagged_only'),
              label: Text('เฉพาะข้อที่มีป้ายเตือน (${flagged.length} ข้อ)'),
              selected: _flaggedOnly,
              onSelected: (v) => setState(() => _flaggedOnly = v),
            ),
          ),
        ],
        const SizedBox(height: 4),
        for (final q in shown)
          _QuestionTile(
            key: ValueKey('option_q_${q.questionId}'),
            question: q,
            groupsReady: a.groupsReady,
            // Short exams open every question; long ones only the flagged.
            expanded: a.questions.length <= 10 || q.flagCount > 0,
          ),
      ],
    );
  }
}

class _QuestionTile extends StatelessWidget {
  const _QuestionTile({
    super.key,
    required this.question,
    required this.groupsReady,
    required this.expanded,
  });

  final QuestionOptionStats question;
  final bool groupsReady;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final q = question;
    final warn = Colors.orange.shade800;
    final stats = [
      questionTypeLabel(q.type),
      'p ${stat2(q.p)}',
      if (groupsReady) 'r ${stat2(q.r)}',
    ].join(' · ');
    return Theme(
      // No divider lines around an opened tile inside the card.
      data: theme.copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        tilePadding: EdgeInsets.zero,
        childrenPadding: const EdgeInsets.only(bottom: 8),
        initiallyExpanded: expanded,
        title: Row(
          children: [
            Text('ข้อ ${q.position}', style: theme.textTheme.titleSmall),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                stats,
                style: theme.textTheme.bodySmall,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            if (q.flagCount > 0)
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.warning_amber_rounded, size: 16, color: warn),
                  const SizedBox(width: 2),
                  Text(
                    '${q.flagCount} ป้าย',
                    style: theme.textTheme.labelSmall?.copyWith(color: warn),
                  ),
                ],
              ),
          ],
        ),
        subtitle: Text(
          _summary(q),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        children: [
          if (q.promptText.trim().isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  q.promptText.trim(),
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
          for (final o in q.options)
            _OptionRow(option: o, groupsReady: groupsReady),
          _CountRow(
            label: q.hasOptions ? 'ไม่ตอบ' : 'ไม่ตอบหรืออ่านค่าไม่ได้',
            value: q.blank,
          ),
          if (q.hasOptions) _CountRow(label: 'ฝนหลายตัว', value: q.multiple),
        ],
      ),
    );
  }

  /// "ก 3 · ข 1 · ค (เฉลย) 18 · ง 0 · ไม่ตอบ 1" for the closed tile.
  static String _summary(QuestionOptionStats q) => [
    for (final o in q.options)
      '${o.label}${o.correct ? ' (เฉลย)' : ''} ${o.count}',
    'ไม่ตอบ ${q.blank.count}',
    if (q.multiple.count > 0) 'ฝนหลายตัว ${q.multiple.count}',
  ].join(' · ');
}

String _pct(double? v) => v == null ? '–' : '${v.toStringAsFixed(1)}%';

class _OptionRow extends StatelessWidget {
  const _OptionRow({required this.option, required this.groupsReady});

  final OptionStat option;
  final bool groupsReady;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final o = option;
    final warn = Colors.orange.shade800;
    final fill = o.correct
        ? Colors.green.shade600
        : (o.flags.isEmpty ? scheme.outline : warn);
    final share = ((o.pct ?? 0) / 100).clamp(0.0, 1.0);
    return Padding(
      key: ValueKey('option_${o.position}'),
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SizedBox(
                width: 64,
                child: Row(
                  children: [
                    Text(o.label, style: theme.textTheme.labelLarge),
                    if (o.correct) ...[
                      const SizedBox(width: 4),
                      Icon(
                        Icons.check_circle,
                        size: 16,
                        color: Colors.green.shade700,
                        semanticLabel: 'เฉลย',
                      ),
                    ],
                  ],
                ),
              ),
              Expanded(
                child: LayoutBuilder(
                  builder: (context, box) => Stack(
                    alignment: Alignment.centerLeft,
                    children: [
                      Container(
                        height: 12,
                        decoration: BoxDecoration(
                          color: scheme.surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(4),
                        ),
                      ),
                      if (o.count > 0)
                        Container(
                          height: 12,
                          width: (box.maxWidth * share).clamp(
                            4.0,
                            box.maxWidth,
                          ),
                          decoration: BoxDecoration(
                            color: fill,
                            borderRadius: BorderRadius.circular(4),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
              SizedBox(
                width: 92,
                child: Text(
                  '${o.count} คน · ${_pct(o.pct)}',
                  textAlign: TextAlign.end,
                  style: theme.textTheme.labelMedium?.copyWith(
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
              ),
            ],
          ),
          if (groupsReady && o.top != null && o.bottom != null)
            Padding(
              padding: const EdgeInsets.only(left: 64, top: 2),
              child: Text(
                [
                  if (o.correct) 'เฉลย',
                  'กลุ่มสูง ${o.top} · กลุ่มต่ำ ${o.bottom}',
                ].join(' · '),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
            )
          else if (o.correct)
            Padding(
              padding: const EdgeInsets.only(left: 64, top: 2),
              child: Text(
                'เฉลย',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
            ),
          for (final f in o.flags)
            Padding(
              padding: const EdgeInsets.only(left: 64, top: 2),
              child: Row(
                children: [
                  Icon(Icons.warning_amber_rounded, size: 14, color: warn),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      f.label,
                      style: theme.textTheme.labelSmall?.copyWith(color: warn),
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

class _CountRow extends StatelessWidget {
  const _CountRow({required this.label, required this.value});

  final String label;
  final CountPct value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          Text(
            '${value.count} คน · ${_pct(value.pct)}',
            style: theme.textTheme.labelMedium,
          ),
        ],
      ),
    );
  }
}
