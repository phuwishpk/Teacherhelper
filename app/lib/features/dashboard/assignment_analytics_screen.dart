import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../charts/score_distribution_chart.dart';
import '../exams/exam_option_analysis.dart';
import '../exams/exam_option_analysis_card.dart';
import '../review/review_labels.dart';
import 'analytics_models.dart';
import 'analytics_repository.dart';
import 'heatmap.dart';

/// Short column labels of the error types (full names in the tooltip).
String errorTypeShortLabel(ErrorType t) => switch (t) {
  ErrorType.concept => 'แนวคิด',
  ErrorType.procedure => 'วิธีทำ',
  ErrorType.calculation => 'คำนวณ',
  ErrorType.careless => 'สะเพร่า',
  ErrorType.incomplete => 'ไม่ครบ',
  ErrorType.misreadQuestion => 'ตีความโจทย์',
  ErrorType.spellingGrammar => 'สะกดคำ',
  ErrorType.noAnswer => 'ไม่ตอบ',
  ErrorType.other => 'อื่นๆ',
};

/// Item analysis of one assignment for the teacher (DESIGN §9.6, §14.3):
/// the most-missed questions, p and r per question, and the skill x error
/// type heatmap. Built from published results only. An exam shows the
/// option analysis of §22.13 in place of the heatmap (exam answers carry no
/// error types).
class AssignmentAnalyticsScreen extends ConsumerWidget {
  const AssignmentAnalyticsScreen({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final analytics = ref.watch(assignmentAnalyticsProvider(assignmentId));
    final assignment = ref.watch(assignmentDetailProvider(assignmentId)).value;
    final title = assignment?.title;
    final isExam = assignment?.isExam ?? false;
    return Scaffold(
      appBar: AppBar(
        title: Text(title == null ? 'วิเคราะห์ผล' : 'วิเคราะห์ผล: $title'),
      ),
      body: AsyncView(
        value: analytics,
        onRetry: () =>
            ref.invalidate(assignmentAnalyticsProvider(assignmentId)),
        data: (a) => RefreshIndicator(
          onRefresh: () {
            if (isExam) {
              ref.invalidate(examOptionAnalysisProvider(assignmentId));
            }
            return ref.refresh(
              assignmentAnalyticsProvider(assignmentId).future,
            );
          },
          child: a.publishedCount == 0 || a.items.isEmpty
              ? ListView(
                  children: const [
                    SizedBox(height: 48),
                    EmptyView(
                      icon: Icons.analytics_outlined,
                      title: 'ยังไม่มีผลที่เผยแพร่',
                      message:
                          'ค่าความยาก อำนาจจำแนก และข้อที่ผิดบ่อยคำนวณจากผลที่เผยแพร่แล้วเท่านั้น',
                    ),
                  ],
                )
              : ContentColumn(
                  maxWidth: 900,
                  child: ListView(
                    children: [
                      _SummaryCard(analytics: a),
                      const SizedBox(height: 16),
                      ScoreDistributionCard(assignmentId: assignmentId),
                      const SizedBox(height: 16),
                      _Section(
                        title: 'ข้อที่ทั้งห้องผิดมากที่สุด',
                        subtitle:
                            'เรียงจากค่า p น้อยไปมาก (สัดส่วนคะแนนเฉลี่ยของข้อ)',
                        child: _MostMissed(items: a.mostMissed()),
                      ),
                      const SizedBox(height: 16),
                      _Section(
                        title: 'ค่าความยากง่าย (p) และอำนาจจำแนก (r)',
                        subtitle: a.hasDiscrimination
                            ? 'p ที่เหมาะสม 0.20–0.80 · r ≥ 0.20 ถือว่าใช้ได้'
                            : 'p ที่เหมาะสม 0.20–0.80 · r แสดงเมื่อเผยแพร่แล้ว '
                                  '${a.minCountForR} คนขึ้นไป (ตอนนี้ ${a.publishedCount} คน)',
                        child: _ItemTable(analytics: a),
                      ),
                      const SizedBox(height: 16),
                      if (isExam)
                        ExamOptionAnalysisCard(examId: assignmentId)
                      else
                        _Section(
                          title: 'ทักษะ × ประเภทข้อผิดพลาด',
                          subtitle:
                              'จำนวนข้อที่ครูยืนยันประเภทข้อผิดพลาดนั้น แตะค้างที่ช่องเพื่อดูรายละเอียด',
                          child: _ErrorHeatmap(analytics: a),
                        ),
                      const SizedBox(height: 24),
                    ],
                  ),
                ),
        ),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({
    required this.title,
    required this.subtitle,
    required this.child,
  });

  final String title;
  final String subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            const SizedBox(height: 2),
            Text(
              subtitle,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.analytics});

  final AssignmentAnalytics analytics;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final a = analytics;
    final flagged = a.items.where((i) => i.notes.isNotEmpty).length;
    Widget fact(String label, String value) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(value, style: theme.textTheme.headlineSmall),
        Text(label, style: theme.textTheme.bodySmall),
      ],
    );
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Wrap(
          spacing: 32,
          runSpacing: 12,
          children: [
            fact('นักเรียนที่เผยแพร่ผลแล้ว', '${a.publishedCount} คน'),
            fact('จำนวนข้อ', '${a.items.length} ข้อ'),
            fact('ข้อที่ควรทบทวนคุณภาพ', '$flagged ข้อ'),
          ],
        ),
      ),
    );
  }
}

/// Horizontal bars of p, lowest first, value written at the bar end.
class _MostMissed extends StatelessWidget {
  const _MostMissed({required this.items});

  final List<ItemStat> items;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    if (items.isEmpty) return const Text('ยังไม่มีข้อมูล');
    return Column(
      children: [
        for (final i in items)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              children: [
                SizedBox(
                  width: 52,
                  child: Text(
                    'ข้อ ${i.position}',
                    style: theme.textTheme.labelLarge,
                  ),
                ),
                Expanded(
                  child: Tooltip(
                    message:
                        'ข้อ ${i.position} (${questionTypeLabel(i.type)}): '
                        'ได้คะแนนเฉลี่ย ${(i.p! * 100).round()}% ของคะแนนเต็ม '
                        'จาก ${i.n} คน',
                    child: LayoutBuilder(
                      builder: (context, box) => Stack(
                        alignment: Alignment.centerLeft,
                        children: [
                          Container(
                            height: 14,
                            decoration: BoxDecoration(
                              color: scheme.surfaceContainerHighest,
                              borderRadius: BorderRadius.circular(4),
                            ),
                          ),
                          Container(
                            height: 14,
                            width: (box.maxWidth * i.p!).clamp(
                              4.0,
                              box.maxWidth,
                            ),
                            decoration: BoxDecoration(
                              color: scheme.primary,
                              borderRadius: BorderRadius.circular(4),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                SizedBox(
                  width: 56,
                  child: Text(
                    'p ${stat2(i.p)}',
                    textAlign: TextAlign.end,
                    style: theme.textTheme.labelMedium,
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

/// p / r per question, in question order. Fits a phone: the prompt is
/// under the number, notes are words (never color alone).
class _ItemTable extends StatelessWidget {
  const _ItemTable({required this.analytics});

  final AssignmentAnalytics analytics;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final head = theme.textTheme.labelMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    const numStyle = TextStyle(fontFeatures: [FontFeature.tabularFigures()]);
    // §14.3: below the minimum the r column says "ข้อมูลน้อย".
    final rWidth = analytics.hasDiscrimination ? 48.0 : 72.0;
    return Column(
      children: [
        Row(
          children: [
            Expanded(child: Text('ข้อ', style: head)),
            SizedBox(
              width: 48,
              child: Text('p', style: head, textAlign: TextAlign.end),
            ),
            SizedBox(
              width: rWidth,
              child: Text('r', style: head, textAlign: TextAlign.end),
            ),
            SizedBox(
              width: 44,
              child: Text('คน', style: head, textAlign: TextAlign.end),
            ),
          ],
        ),
        const Divider(),
        for (final i in analytics.byPosition) ...[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'ข้อ ${i.position} · ${questionTypeLabel(i.type)}',
                      style: theme.textTheme.bodyMedium,
                    ),
                    if (i.promptText.isNotEmpty)
                      Text(
                        i.promptText,
                        style: muted,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    if (i.notes.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Row(
                          children: [
                            Icon(
                              Icons.warning_amber_rounded,
                              size: 14,
                              color: Colors.orange.shade800,
                            ),
                            const SizedBox(width: 4),
                            Flexible(
                              child: Text(
                                i.notes.join(' · '),
                                style: theme.textTheme.labelSmall?.copyWith(
                                  color: Colors.orange.shade800,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
              SizedBox(
                width: 48,
                child: Text(
                  stat2(i.p),
                  style: numStyle,
                  textAlign: TextAlign.end,
                ),
              ),
              SizedBox(
                width: rWidth,
                child: Text(
                  analytics.hasDiscrimination ? stat2(i.r) : 'ข้อมูลน้อย',
                  style: numStyle,
                  textAlign: TextAlign.end,
                ),
              ),
              SizedBox(
                width: 44,
                child: Text(
                  '${i.n}',
                  style: numStyle,
                  textAlign: TextAlign.end,
                ),
              ),
            ],
          ),
          const Divider(height: 16),
        ],
      ],
    );
  }
}

class _ErrorHeatmap extends StatelessWidget {
  const _ErrorHeatmap({required this.analytics});

  final AssignmentAnalytics analytics;

  @override
  Widget build(BuildContext context) {
    final skills = analytics.heatmapSkills;
    final types = analytics.heatmapErrorTypes;
    if (skills.isEmpty || types.isEmpty) {
      return const Text('ยังไม่มีข้อผิดพลาดที่ครูยืนยันในผลที่เผยแพร่');
    }
    var max = 0;
    for (final s in skills) {
      for (final t in types) {
        final c = analytics.count(s.id, t);
        if (c > max) max = c;
      }
    }
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        HeatmapGrid(
          key: const ValueKey('error_heatmap'),
          corner: const Text('ทักษะ'),
          rows: [
            for (final s in skills)
              Tooltip(
                message: s.name,
                child: Text(s.code.isEmpty ? s.name : s.code),
              ),
          ],
          columns: [
            for (final t in types)
              HeatmapColumn(label: errorTypeShortLabel(t), tooltip: t.label),
          ],
          cell: (r, c) {
            final count = analytics.count(skills[r].id, types[c]);
            return HeatmapCell(
              text: count == 0 ? '–' : '$count',
              fill: CountRamp.of(context, count, max),
              textColor: count == 0 ? theme.colorScheme.onSurfaceVariant : null,
              tooltip:
                  '${skills[r].code} ${skills[r].name}: ${types[c].label} $count ข้อ',
            );
          },
        ),
        const SizedBox(height: 8),
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          runSpacing: 4,
          children: [
            Text('น้อย', style: theme.textTheme.labelSmall),
            const SizedBox(width: 6),
            for (final color in CountRamp.legend(context))
              Container(
                width: 18,
                height: 10,
                margin: const EdgeInsets.only(right: 2),
                decoration: BoxDecoration(
                  color: color,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            const SizedBox(width: 4),
            Text('มาก (สูงสุด $max)', style: theme.textTheme.labelSmall),
          ],
        ),
      ],
    );
  }
}
