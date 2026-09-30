import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import 'chart_models.dart';
import 'chart_style.dart';

/// Chart (2) of DESIGN §20.4: the share of the classroom's students who
/// pass each indicator (0–100%), with n assessed under every bar.
/// Indicators nobody is assessed on yet are faint placeholders.
class PassRateChart extends StatelessWidget {
  const PassRateChart({super.key, required this.data});

  final IndicatorPassRate data;

  @override
  Widget build(BuildContext context) {
    final rows = data.indicators;
    if (rows.isEmpty) {
      return const ChartEmpty(message: 'ยังไม่มีตัวชี้วัดที่ประเมินแล้ว');
    }
    final color = ChartColors.primary(context);
    final faint = ChartColors.faint(context);
    final axis = ChartColors.axisText(context);
    final scheme = Theme.of(context).colorScheme;
    return ChartViewport(
      key: const ValueKey('pass_rate_chart'),
      height: 280,
      minWidth: rows.length * 64.0 + 40,
      semanticsLabel: [
        for (final r in rows)
          '${r.skill.code} ${r.passRate == null ? 'ยังไม่ประเมิน' : 'ผ่าน ${pct(r.passRate!)} จาก ${r.assessedStudents} คน'}',
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
                reservedSize: 52,
                getTitlesWidget: (v, meta) {
                  final r = rows[v.toInt()];
                  return SideTitleWidget(
                    meta: meta,
                    child: Text(
                      '${twoLineCode(r.skill.code)}\nn=${r.assessedStudents}',
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
                final r = rows[group.x];
                return BarTooltipItem(
                  '${r.skill.code}\n'
                  '${r.passRate == null ? 'ยังไม่มีนักเรียนที่ประเมินแล้ว' : 'ผ่าน ${r.passedStudents}/${r.assessedStudents} คน (${pct(r.passRate!)})'}',
                  TextStyle(color: scheme.onInverseSurface, fontSize: 12),
                );
              },
            ),
          ),
          barGroups: [
            for (final (i, r) in rows.indexed)
              BarChartGroupData(
                x: i,
                barRods: [
                  BarChartRodData(
                    toY: (r.passRate ?? 0) * 100,
                    width: 20,
                    color: color,
                    borderRadius: const BorderRadius.vertical(
                      top: Radius.circular(4),
                    ),
                    backDrawRodData: BackgroundBarChartRodData(
                      show: r.passRate == null,
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

/// The same numbers as a list (the table view of chart 2).
class PassRateTable extends StatelessWidget {
  const PassRateTable({super.key, required this.data});

  final IndicatorPassRate data;

  @override
  Widget build(BuildContext context) => ExpansionTile(
    key: const ValueKey('pass_rate_table'),
    tilePadding: EdgeInsets.zero,
    title: const Text('ดูเป็นตาราง'),
    children: [
      for (final r in data.indicators)
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          title: Text('${r.skill.code} ${r.skill.name}'),
          trailing: Text(
            r.passRate == null
                ? 'ยังไม่ประเมิน'
                : 'ผ่าน ${r.passedStudents}/${r.assessedStudents} คน · ${pct(r.passRate!)}',
          ),
        ),
    ],
  );
}
