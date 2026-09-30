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

/// The caption under the fallback radar of indicators (DESIGN §20.4).
const kIndicatorAxesNote =
    'แสดงรายตัวชี้วัด เพราะมีกลุ่มที่ประเมินแล้วไม่ถึง $kRadarMinAxes กลุ่ม';

/// The spider chart of a course roll-up (DESIGN §20.4), by
/// [CourseMasterySummary.chartMode]: a radar of the nodes with at least one
/// assessed indicator when there are 3–12 of them; with fewer than 3, a
/// radar of the assessed indicators of every node when there are 3–12 of
/// those (tapping an indicator opens its node); otherwise bars of every
/// planned node (the unassessed ones as faint placeholders labelled
/// "ยังไม่ประเมิน"). Tapping an axis or a bar calls [onNode] (the
/// drill-down). Values 0–100%.
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
    return switch (summary.chartMode) {
      RollupChartMode.nodes => RollupRadar(
        key: const ValueKey('rollup_radar'),
        axes: [
          for (final n in summary.assessedNodes)
            RadarAxis(
              label: nodeShortLabel(n),
              value: n.value ?? 0,
              onTap: () => onNode(n),
            ),
        ],
      ),
      RollupChartMode.indicators => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          RollupRadar(
            key: const ValueKey('rollup_radar'),
            axes: [
              for (final a in summary.assessedIndicators)
                RadarAxis(
                  label: twoLineCode(a.indicator.skill.code),
                  value: a.indicator.value ?? 0,
                  onTap: () => onNode(a.node),
                ),
            ],
          ),
          Text(
            kIndicatorAxesNote,
            key: const ValueKey('rollup_indicator_axes_note'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
      RollupChartMode.bars => _Bars(nodes: summary.nodes, onNode: onNode),
    };
  }
}

/// One axis of a [RollupRadar]: its label, its value 0–1 and what a tap
/// on it does (nothing when null).
class RadarAxis {
  const RadarAxis({required this.label, required this.value, this.onTap});

  final String label;
  final double value;
  final VoidCallback? onTap;
}

/// A radar of 3–12 [axes] on a fixed 0–100% scale, each axis titled with
/// its label and value.
class RollupRadar extends StatelessWidget {
  const RollupRadar({super.key, required this.axes, this.height = 300});

  final List<RadarAxis> axes;
  final double height;

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
      height: height,
      semanticsLabel: [
        for (final a in axes)
          '${a.label.replaceAll('\n', ' ')} ${pct(a.value)}',
      ].join(', '),
      // Axis titles are painted outside the radar; the padding keeps the
      // top and bottom titles (up to three lines) inside the chart box so
      // they do not run into the caption or the list below.
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 8),
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
              text: '${axes[i].label}\n${pct(axes[i].value)}',
            ),
            radarTouchData: RadarTouchData(
              touchSpotThreshold: 24,
              touchCallback: (event, response) {
                final spot = response?.touchedSpot;
                if (event is FlTapUpEvent && spot != null) {
                  axes[spot.touchedRadarEntryIndex].onTap?.call();
                }
              },
            ),
            dataSets: [
              scale(0),
              scale(100),
              RadarDataSet(
                dataEntries: [
                  for (final a in axes) RadarEntry(value: a.value * 100),
                ],
                fillColor: color.withValues(alpha: 0.18),
                borderColor: color,
                borderWidth: 2,
                entryRadius: 4,
              ),
            ],
          ),
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
/// a radar of its assessed indicators when there are 3–12, then every
/// indicator with its value. For a student, [onProgress] adds an
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
        if (radarFits(node.indicators.where((i) => i.assessed).length))
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: RollupRadar(
              key: const ValueKey('node_radar'),
              height: 260,
              axes: [
                for (final i in node.indicators)
                  if (i.assessed)
                    RadarAxis(
                      label: twoLineCode(i.skill.code),
                      value: i.value!,
                    ),
              ],
            ),
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
