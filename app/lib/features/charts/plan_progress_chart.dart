import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import 'chart_models.dart';
import 'chart_style.dart';

/// Chart (5) of DESIGN §20.4: per unit, the share of its planned
/// indicators assessed, taught but not assessed, and not taught yet, as
/// one 100% stacked bar (counts in the tooltip and the list). A unit with
/// no indicator yet is an empty faint bar.
class PlanProgressChart extends StatelessWidget {
  const PlanProgressChart({super.key, required this.data});

  final PlanProgress data;

  static const partLabels = [
    'ประเมินแล้ว',
    'สอนแล้ว ยังไม่ประเมิน',
    'ยังไม่สอน',
  ];

  @override
  Widget build(BuildContext context) {
    final rows = data.units;
    if (rows.isEmpty) {
      return const ChartEmpty(message: 'รายวิชานี้ยังไม่มีหน่วยหรือตัวชี้วัด');
    }
    final steps = ChartColors.planSteps(context);
    final axis = ChartColors.axisText(context);
    final scheme = Theme.of(context).colorScheme;
    final gap = BorderSide(color: scheme.surface, width: 1);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 16,
          runSpacing: 4,
          children: [
            for (final (i, label) in partLabels.indexed)
              LegendItem(color: steps[i], label: label),
          ],
        ),
        const SizedBox(height: 12),
        ChartViewport(
          key: const ValueKey('plan_progress_chart'),
          height: 240,
          minWidth: rows.length * 64.0 + 40,
          semanticsLabel: [
            for (final r in rows)
              '${r.shortLabel}: ประเมินแล้ว ${r.assessed}, สอนแล้ว ${r.taught} จาก ${r.planned} ตัวชี้วัด',
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
                    reservedSize: 36,
                    getTitlesWidget: (v, meta) {
                      final r = rows[v.toInt()];
                      return SideTitleWidget(
                        meta: meta,
                        child: Text(
                          '${r.shortLabel}\n${r.planned} ตัวชี้วัด',
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
                touchTooltipData: BarTouchTooltipData(
                  getTooltipColor: (_) => scheme.inverseSurface,
                  getTooltipItem: (group, _, _, _) {
                    final r = rows[group.x];
                    return BarTooltipItem(
                      '${r.title}\n'
                      'ประเมินแล้ว ${r.assessed} · สอนแล้ว ${r.taught} '
                      'จาก ${r.planned} ตัวชี้วัด',
                      TextStyle(color: scheme.onInverseSurface, fontSize: 12),
                    );
                  },
                ),
              ),
              barGroups: [
                for (final (i, r) in rows.indexed)
                  BarChartGroupData(
                    x: i,
                    barRods: [_rod(r, steps, gap, context)],
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  static BarChartRodData _rod(
    PlanProgressRow r,
    List<Color> steps,
    BorderSide gap,
    BuildContext context,
  ) {
    if (r.planned == 0) {
      return BarChartRodData(
        toY: 0,
        width: 22,
        backDrawRodData: BackgroundBarChartRodData(
          show: true,
          toY: 100,
          color: ChartColors.faint(context),
        ),
      );
    }
    final parts = [r.assessed, r.taughtNotAssessed, r.notTaught];
    final items = <BarChartRodStackItem>[];
    var from = 0.0;
    for (final (i, n) in parts.indexed) {
      if (n <= 0) continue;
      final to = from + n / r.planned * 100;
      items.add(BarChartRodStackItem(from, to, steps[i], borderSide: gap));
      from = to;
    }
    return BarChartRodData(
      toY: 100,
      width: 22,
      rodStackItems: items,
      color: Colors.transparent,
      borderRadius: BorderRadius.circular(4),
    );
  }
}

/// The same counts as rows (the table view of chart 5), with lesson plans
/// marked taught.
class PlanProgressTable extends StatelessWidget {
  const PlanProgressTable({super.key, required this.data});

  final PlanProgress data;

  @override
  Widget build(BuildContext context) => ExpansionTile(
    key: const ValueKey('plan_progress_table'),
    tilePadding: EdgeInsets.zero,
    title: const Text('ดูเป็นตาราง'),
    children: [
      for (final r in data.units)
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          title: Text(
            r.type == 'unit' ? '${r.shortLabel} ${r.title}' : r.title,
          ),
          subtitle: Text('แผนสอนแล้ว ${r.plansTaught}/${r.plansTotal} แผน'),
          trailing: Text(
            'ประเมิน ${r.assessed} · สอน ${r.taught} / ${r.planned}',
          ),
        ),
    ],
  );
}
