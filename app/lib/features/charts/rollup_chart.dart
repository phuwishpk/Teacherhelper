import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../assignments/question.dart';
import '../mastery/course_mastery.dart';
import '../mastery/mastery_widgets.dart';
import 'chart_style.dart';

/// The short axis label of a roll-up node: the standard's code, "หน่วย n",
/// or the title of `other`.
String nodeShortLabel(RollupNode n) =>
    n.code ??
    (n.type == 'unit' && n.position != null ? 'หน่วย ${n.position}' : n.title);

/// The spider chart of a course roll-up (DESIGN §20.4): a radar of the
/// nodes with at least one assessed indicator when there are 3–12 of them,
/// otherwise bars of every planned node (the unassessed ones as faint
/// placeholders labelled "ยังไม่ประเมิน"). Tapping an axis or a bar calls
/// [onNode] (the drill-down). Values 0–100%.
class RollupChart extends StatelessWidget {
  const RollupChart({super.key, required this.summary, required this.onNode});

  final CourseMasterySummary summary;
  final ValueChanged<RollupNode> onNode;

  @override
  Widget build(BuildContext context) {
    if (summary.nodes.isEmpty) {
      return const ChartEmpty(
        message: 'รายวิชานี้ยังไม่มีตัวชี้วัดที่วางแผนไว้',
      );
    }
    return summary.useRadar
        ? _Radar(axes: summary.assessedNodes, onNode: onNode)
        : _Bars(nodes: summary.nodes, onNode: onNode);
  }
}

class _Radar extends StatelessWidget {
  const _Radar({required this.axes, required this.onNode});

  final List<RollupNode> axes;
  final ValueChanged<RollupNode> onNode;

  @override
  Widget build(BuildContext context) {
    final color = ChartColors.primary(context);
    final grid = BorderSide(color: ChartColors.grid(context));
    // Two invisible sets pin the scale to 0 (center) and 100 (rim).
    RadarDataSet scale(double v) => RadarDataSet(
      dataEntries: [for (final _ in axes) RadarEntry(value: v)],
      fillColor: Colors.transparent,
      borderColor: Colors.transparent,
      borderWidth: 0,
      entryRadius: 0,
    );
    return ChartViewport(
      key: const ValueKey('rollup_radar'),
      height: 300,
      semanticsLabel: [
        for (final n in axes) '${nodeShortLabel(n)} ${pct(n.value ?? 0)}',
      ].join(', '),
      child: RadarChart(
        RadarChartData(
          isMinValueAtCenter: true,
          radarShape: RadarShape.polygon,
          tickCount: 4,
          ticksTextStyle: ChartColors.axisText(
            context,
          )?.copyWith(fontSize: 9, color: Colors.transparent),
          tickBorderData: grid,
          gridBorderData: grid,
          radarBorderData: grid,
          titlePositionPercentageOffset: 0.12,
          titleTextStyle: Theme.of(context).textTheme.labelSmall,
          getTitle: (i, _) => RadarChartTitle(
            text: '${nodeShortLabel(axes[i])}\n${pct(axes[i].value ?? 0)}',
          ),
          radarTouchData: RadarTouchData(
            touchSpotThreshold: 24,
            touchCallback: (event, response) {
              final spot = response?.touchedSpot;
              if (event is FlTapUpEvent && spot != null) {
                onNode(axes[spot.touchedRadarEntryIndex]);
              }
            },
          ),
          dataSets: [
            scale(0),
            scale(100),
            RadarDataSet(
              dataEntries: [
                for (final n in axes) RadarEntry(value: (n.value ?? 0) * 100),
              ],
              fillColor: color.withValues(alpha: 0.18),
              borderColor: color,
              borderWidth: 2,
              entryRadius: 4,
            ),
          ],
        ),
      ),
    );
  }
}

class _Bars extends StatelessWidget {
  const _Bars({required this.nodes, required this.onNode});

  final List<RollupNode> nodes;
  final ValueChanged<RollupNode> onNode;

  @override
  Widget build(BuildContext context) {
    final color = ChartColors.primary(context);
    final faint = ChartColors.faint(context);
    final axis = ChartColors.axisText(context);
    return ChartViewport(
      key: const ValueKey('rollup_bars'),
      height: 260,
      minWidth: nodes.length * 64.0 + 40,
      semanticsLabel: [
        for (final n in nodes)
          '${nodeShortLabel(n)} ${n.value == null ? 'ยังไม่ประเมิน' : pct(n.value!)}',
      ].join(', '),
      child: BarChart(
        BarChartData(
          minY: 0,
          maxY: 100,
          alignment: BarChartAlignment.spaceAround,
          gridData: FlGridData(
            drawVerticalLine: false,
            horizontalInterval: 25,
            getDrawingHorizontalLine: (_) =>
                FlLine(color: ChartColors.grid(context), strokeWidth: 1),
          ),
          borderData: FlBorderData(show: false),
          titlesData: FlTitlesData(
            topTitles: const AxisTitles(),
            rightTitles: const AxisTitles(),
            leftTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 36,
                interval: 25,
                getTitlesWidget: (v, meta) =>
                    Text('${v.round()}%', style: axis),
              ),
            ),
            bottomTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 40,
                getTitlesWidget: (v, meta) {
                  final n = nodes[v.toInt()];
                  return SideTitleWidget(
                    meta: meta,
                    child: Text(
                      n.value == null
                          ? '${nodeShortLabel(n)}\nยังไม่ประเมิน'
                          : nodeShortLabel(n),
                      style: axis,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                    ),
                  );
                },
              ),
            ),
          ),
          barTouchData: BarTouchData(
            touchCallback: (event, response) {
              final i = response?.spot?.touchedBarGroupIndex;
              if (event is FlTapUpEvent && i != null) onNode(nodes[i]);
            },
            touchTooltipData: BarTouchTooltipData(
              getTooltipColor: (_) =>
                  Theme.of(context).colorScheme.inverseSurface,
              getTooltipItem: (group, _, _, _) {
                final n = nodes[group.x];
                return BarTooltipItem(
                  '${nodeShortLabel(n)}\n'
                  '${n.value == null ? 'ยังไม่ประเมิน' : pct(n.value!)}\n'
                  '${n.coverageText}',
                  TextStyle(
                    color: Theme.of(context).colorScheme.onInverseSurface,
                    fontSize: 12,
                  ),
                );
              },
            ),
          ),
          barGroups: [
            for (final (i, n) in nodes.indexed)
              BarChartGroupData(
                x: i,
                barRods: [
                  BarChartRodData(
                    toY: (n.value ?? 0) * 100,
                    width: 20,
                    color: color,
                    borderRadius: const BorderRadius.vertical(
                      top: Radius.circular(4),
                    ),
                    backDrawRodData: BackgroundBarChartRodData(
                      show: n.value == null,
                      toY: 100,
                      color: faint,
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

/// Every node of the roll-up as a row (the table view of the chart and
/// the drill-down entry that works without precise taps).
class RollupNodeList extends StatelessWidget {
  const RollupNodeList({super.key, required this.nodes, required this.onNode});

  final List<RollupNode> nodes;
  final ValueChanged<RollupNode> onNode;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      children: [
        for (final (i, n) in nodes.indexed)
          ListTile(
            key: ValueKey('rollup_node_$i'),
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: Text(
              n.code == null ? n.title : '${n.code} ${n.title}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            subtitle: Text(n.coverageText),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  n.value == null ? 'ยังไม่ประเมิน' : pct(n.value!),
                  style: theme.textTheme.labelLarge,
                ),
                const Icon(Icons.chevron_right),
              ],
            ),
            onTap: n.indicators.isEmpty ? null : () => onNode(n),
          ),
      ],
    );
  }
}

/// The drill-down of one axis (DESIGN §20.4 "แตะแกนเพื่อลงไปดูตัวชี้วัด"):
/// its indicators with their values. For a student, [onProgress] adds an
/// assessed indicator to the progress chart; the sheet pops that skill.
Future<Skill?> showNodeIndicators(
  BuildContext context,
  RollupNode node, {
  bool canShowProgress = false,
}) => showModalBottomSheet<Skill>(
  context: context,
  isScrollControlled: true,
  showDragHandle: true,
  builder: (context) => DraggableScrollableSheet(
    expand: false,
    initialChildSize: 0.6,
    maxChildSize: 0.9,
    builder: (context, controller) => ListView(
      key: const ValueKey('node_indicators'),
      controller: controller,
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        Text(
          node.code == null ? node.title : '${node.code} ${node.title}',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(
          [
            if (node.value != null) 'เฉลี่ย ${pct(node.value!)}',
            node.coverageText,
          ].join(' · '),
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        for (final ind in node.indicators)
          _IndicatorRow(
            indicator: ind,
            onProgress: canShowProgress && ind.assessed
                ? () => Navigator.of(context).pop(ind.skill)
                : null,
          ),
      ],
    ),
  ),
);

class _IndicatorRow extends StatelessWidget {
  const _IndicatorRow({required this.indicator, this.onProgress});

  final RollupIndicator indicator;
  final VoidCallback? onProgress;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final i = indicator;
    final value = i.value;
    final level = i.level;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  '${i.skill.code} ${i.skill.name}',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              if (i.skill.sourceLabel != null)
                Padding(
                  padding: const EdgeInsets.only(left: 6),
                  child: Text(
                    i.skill.sourceLabel!,
                    style: theme.textTheme.labelSmall,
                  ),
                ),
              if (onProgress != null)
                IconButton(
                  tooltip: 'ดูพัฒนาการ',
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(Icons.show_chart),
                  onPressed: onProgress,
                ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: value ?? 0,
                    minHeight: 8,
                    color: ChartColors.primary(context),
                    backgroundColor: ChartColors.faint(context),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                value == null ? 'ยังไม่ประเมิน' : pct(value),
                style: theme.textTheme.labelLarge,
              ),
            ],
          ),
          const SizedBox(height: 4),
          if (i.assessedStudents != null)
            Text(
              i.assessedStudents == 0
                  ? 'ยังไม่มีนักเรียนที่ประเมินแล้ว'
                  : 'ผ่าน ${i.passedStudents ?? 0}/${i.assessedStudents} คน',
              style: theme.textTheme.bodySmall,
            )
          else if (level != null)
            Align(
              alignment: Alignment.centerLeft,
              child: MasteryLevelChip(level: level),
            ),
        ],
      ),
    );
  }
}
