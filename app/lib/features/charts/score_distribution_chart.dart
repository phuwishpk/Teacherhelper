import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import 'chart_models.dart';
import 'chart_style.dart';
import 'charts_repository.dart';

/// "6.5", "10", "7.25": at most two decimals, no trailing zeros.
String _points(double v) => v
    .toStringAsFixed(2)
    .replaceFirst(RegExp(r'0+$'), '')
    .replaceFirst(RegExp(r'\.$'), '');

/// Chart (4) of DESIGN §20.4 in a card: the totals that count of the
/// published submissions in ten 10% bins, with the mean and the median.
class ScoreDistributionCard extends ConsumerWidget {
  const ScoreDistributionCard({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dist = ref.watch(scoreDistributionProvider(assignmentId));
    return ChartCard(
      title: 'การกระจายคะแนน',
      subtitle: 'คะแนนรวมที่ใช้จริงของผลที่เผยแพร่แล้ว ช่วงละ 10% ของคะแนนเต็ม',
      footer: dist.value == null
          ? null
          : 'มีคะแนน ${dist.value!.scoredCount} จาก ${dist.value!.publishedCount} คนที่เผยแพร่แล้ว',
      child: AsyncView(
        value: dist,
        onRetry: () => ref.invalidate(scoreDistributionProvider(assignmentId)),
        data: (d) => d.scoredCount == 0 || d.bins.isEmpty
            ? const ChartEmpty(message: 'ยังไม่มีคะแนนที่เผยแพร่แล้ว')
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Wrap(
                    spacing: 24,
                    runSpacing: 8,
                    children: [
                      _Stat(
                        label: 'เฉลี่ย',
                        value:
                            '${_points(d.mean!)}/${_points(d.maxPoints)}'
                            '${d.meanRatio == null ? '' : ' (${pct(d.meanRatio!)})'}',
                      ),
                      _Stat(
                        label: 'มัธยฐาน',
                        value:
                            '${_points(d.median!)}/${_points(d.maxPoints)}'
                            '${d.medianRatio == null ? '' : ' (${pct(d.medianRatio!)})'}',
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  ScoreHistogram(data: d),
                ],
              ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        Text(value, style: theme.textTheme.titleMedium),
      ],
    );
  }
}

/// The histogram: number of students per bin; the bin holding the median
/// is marked under its bar.
class ScoreHistogram extends StatelessWidget {
  const ScoreHistogram({super.key, required this.data});

  final ScoreDistribution data;

  @override
  Widget build(BuildContext context) {
    final bins = data.bins;
    final color = ChartColors.primary(context);
    final axis = ChartColors.axisText(context);
    final scheme = Theme.of(context).colorScheme;
    final maxCount = data.maxCount;
    final top = maxCount <= 4 ? 4.0 : (maxCount * 1.15).ceilToDouble();
    final medianBin = data.medianRatio == null
        ? -1
        : (data.medianRatio! * bins.length).floor().clamp(0, bins.length - 1);
    return ChartViewport(
      key: const ValueKey('score_histogram'),
      height: 220,
      semanticsLabel: [
        for (final b in bins)
          '${(b.fromRatio * 100).round()}–${(b.toRatio * 100).round()}% ${b.count} คน',
      ].join(', '),
      child: BarChart(
        BarChartData(
          minY: 0,
          maxY: top,
          alignment: BarChartAlignment.spaceAround,
          gridData: FlGridData(
            drawVerticalLine: false,
            horizontalInterval: top <= 4 ? 1 : (top / 4).ceilToDouble(),
            getDrawingHorizontalLine: (_) =>
                FlLine(color: ChartColors.grid(context), strokeWidth: 1),
          ),
          borderData: FlBorderData(show: false),
          titlesData: FlTitlesData(
            topTitles: const AxisTitles(),
            rightTitles: const AxisTitles(),
            leftTitles: AxisTitles(
              axisNameWidget: Text('คน', style: axis),
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 28,
                interval: top <= 4 ? 1 : (top / 4).ceilToDouble(),
                getTitlesWidget: (v, meta) => Text('${v.round()}', style: axis),
              ),
            ),
            bottomTitles: AxisTitles(
              axisNameWidget: Text('% ของคะแนนเต็ม', style: axis),
              axisNameSize: 16,
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 30,
                getTitlesWidget: (v, meta) {
                  final i = v.toInt();
                  return SideTitleWidget(
                    meta: meta,
                    child: Text(
                      '${(bins[i].fromRatio * 100).round()}'
                      '${i == medianBin ? '\nมัธยฐาน' : ''}',
                      style: axis,
                      textAlign: TextAlign.center,
                    ),
                  );
                },
              ),
            ),
          ),
          barTouchData: BarTouchData(
            touchTooltipData: BarTouchTooltipData(
              getTooltipColor: (_) => scheme.inverseSurface,
              getTooltipItem: (group, _, _, _) {
                final b = bins[group.x];
                return BarTooltipItem(
                  '${_points(b.fromPoints)}–${_points(b.toPoints)} คะแนน\n'
                  '${b.count} คน',
                  TextStyle(color: scheme.onInverseSurface, fontSize: 12),
                );
              },
            ),
          ),
          barGroups: [
            for (final (i, b) in bins.indexed)
              BarChartGroupData(
                x: i,
                barRods: [
                  BarChartRodData(
                    toY: b.count.toDouble(),
                    width: 18,
                    color: color,
                    borderRadius: const BorderRadius.vertical(
                      top: Radius.circular(4),
                    ),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}
