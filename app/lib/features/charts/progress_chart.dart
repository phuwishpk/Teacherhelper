import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import '../assignments/question.dart';
import 'chart_models.dart';
import 'chart_style.dart';
import 'charts_repository.dart';

const _thaiMonths = [
  'ม.ค.',
  'ก.พ.',
  'มี.ค.',
  'เม.ย.',
  'พ.ค.',
  'มิ.ย.',
  'ก.ค.',
  'ส.ค.',
  'ก.ย.',
  'ต.ค.',
  'พ.ย.',
  'ธ.ค.',
];

/// "3 ก.ย." of a Bangkok calendar day.
String shortThaiDay(DateTime day) => '${day.day} ${_thaiMonths[day.month - 1]}';

/// Which indicators the progress chart shows, and the color slot each
/// keeps while others are added or removed (color follows the entity).
class ProgressSelection {
  /// Null until the first answer: the server picks the lines.
  List<int>? ids;
  final Map<int, int> _slots = {};

  /// The server's pick (sorted comma list), asked for with no ids.
  String? _serverPick;

  /// Adopts the server's pick once, and gives new ids the lowest free slot.
  void adopt(List<int> serverIds) {
    if (ids == null) {
      ids = [...serverIds];
      _serverPick = _join(serverIds);
    }
    for (final id in ids!) {
      slotOf(id);
    }
  }

  int slotOf(int skillId) {
    final existing = _slots[skillId];
    if (existing != null) return existing;
    final used = {
      for (final e in _slots.entries)
        if (ids?.contains(e.key) ?? false) e.value,
    };
    var slot = 0;
    while (used.contains(slot)) {
      slot++;
    }
    _slots[skillId] = slot;
    return slot;
  }

  bool get isFull => (ids?.length ?? 0) >= kMaxProgressSkills;

  /// Adds [skillId]; false when already [kMaxProgressSkills] lines.
  bool add(int skillId) {
    final list = ids ??= [];
    if (list.contains(skillId)) return true;
    if (list.length >= kMaxProgressSkills) return false;
    _slots.remove(skillId);
    list.add(skillId);
    slotOf(skillId);
    return true;
  }

  void remove(int skillId) {
    ids?.remove(skillId);
    _slots.remove(skillId);
  }

  void replace(List<int> next) {
    ids = [...next.take(kMaxProgressSkills)];
    _slots.removeWhere((k, _) => !ids!.contains(k));
    for (final id in ids!) {
      slotOf(id);
    }
  }

  /// The provider key: a sorted comma list, or null for the server's pick
  /// (also while the lines are still that pick, so nothing loads twice).
  String? get key {
    final list = ids;
    if (list == null) return null;
    final joined = _join(list);
    return joined == _serverPick ? null : joined;
  }

  static String _join(List<int> ids) => ([...ids]..sort()).join(',');
}

/// Chart (1) of DESIGN §20.4 in a card: one line per indicator (at most 5),
/// mastery after each day's observations, x = date (Asia/Bangkok). A legend
/// under the chart names each line with its latest value; the indicator
/// picker changes the lines. [studentId] null = the student's own.
class IndicatorProgressCard extends ConsumerWidget {
  const IndicatorProgressCard({
    super.key,
    required this.selection,
    required this.onChanged,
    this.studentId,
  });

  final int? studentId;
  final ProgressSelection selection;

  /// Called after [selection] changed (the parent calls setState).
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final query = (studentId: studentId, skillIds: selection.key);
    final progress = ref.watch(indicatorProgressProvider(query));
    return ChartCard(
      title: 'พัฒนาการตามเวลา',
      subtitle:
          'ความเข้าใจหลังการบ้านหรือแบบฝึกแต่ละครั้ง '
          '(เลือกได้ไม่เกิน $kMaxProgressSkills ตัวชี้วัด)',
      action: progress.value == null
          ? null
          : IconButton(
              key: const ValueKey('progress_pick'),
              tooltip: 'เลือกตัวชี้วัด',
              icon: const Icon(Icons.tune),
              onPressed: () => _pick(context, progress.value!),
            ),
      child: AsyncView(
        value: progress,
        onRetry: () => ref.invalidate(indicatorProgressProvider(query)),
        data: (p) {
          selection.adopt(p.skillIds);
          final series = [
            for (final s in p.series)
              if (selection.ids!.contains(s.skill.id)) s,
          ];
          if (series.isEmpty) {
            return const ChartEmpty(
              message: 'ยังไม่มีข้อมูลพัฒนาการ จะแสดงหลังครูเผยแพร่ผลการบ้าน',
            );
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ProgressLineChart(
                series: series,
                colorOf: (s) =>
                    ChartColors.series(context, selection.slotOf(s.skill.id)),
              ),
              const SizedBox(height: 8),
              for (final s in series)
                _LegendRow(
                  series: s,
                  color: ChartColors.series(
                    context,
                    selection.slotOf(s.skill.id),
                  ),
                  onRemove: series.length > 1
                      ? () {
                          selection.remove(s.skill.id);
                          onChanged();
                        }
                      : null,
                ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _pick(BuildContext context, IndicatorProgress p) async {
    final picked = await showDialog<List<int>>(
      context: context,
      builder: (_) => _ProgressPicker(
        options: p.skills,
        selected: selection.ids ?? p.skillIds,
      ),
    );
    if (picked == null || picked.isEmpty) return;
    selection.replace(picked);
    onChanged();
  }
}

class _LegendRow extends StatelessWidget {
  const _LegendRow({required this.series, required this.color, this.onRemove});

  final ProgressSeries series;
  final Color color;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final last = series.points.isEmpty ? null : series.points.last;
    return Row(
      children: [
        Expanded(
          child: LegendItem(
            color: color,
            label: '${series.skill.code} ${series.skill.name}',
          ),
        ),
        Text(
          last == null
              ? 'ยังไม่มีข้อมูล'
              : '${pct(last.value)} · ${series.points.length} ครั้ง',
          style: Theme.of(context).textTheme.labelMedium,
        ),
        if (onRemove != null)
          IconButton(
            tooltip: 'เอาเส้นนี้ออก',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.close, size: 18),
            onPressed: onRemove,
          ),
      ],
    );
  }
}

class _ProgressPicker extends StatefulWidget {
  const _ProgressPicker({required this.options, required this.selected});

  final List<ProgressSkillOption> options;
  final List<int> selected;

  @override
  State<_ProgressPicker> createState() => _ProgressPickerState();
}

class _ProgressPickerState extends State<_ProgressPicker> {
  late final _picked = [...widget.selected];

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('เลือกตัวชี้วัด'),
    content: SizedBox(
      width: 420,
      child: widget.options.isEmpty
          ? const Text('ยังไม่มีตัวชี้วัดที่ประเมินแล้ว')
          : ListView(
              shrinkWrap: true,
              children: [
                Text(
                  'เลือกแล้ว ${_picked.length}/$kMaxProgressSkills',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                for (final o in widget.options)
                  CheckboxListTile(
                    key: ValueKey('progress_option_${o.skill.id}'),
                    dense: true,
                    value: _picked.contains(o.skill.id),
                    onChanged:
                        !_picked.contains(o.skill.id) &&
                            _picked.length >= kMaxProgressSkills
                        ? null
                        : (v) => setState(
                            () => v == true
                                ? _picked.add(o.skill.id)
                                : _picked.remove(o.skill.id),
                          ),
                    title: Text('${o.skill.code} ${o.skill.name}'),
                    subtitle: Text('${pct(o.value)} · ${o.nObs} ครั้ง'),
                  ),
              ],
            ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('ยกเลิก'),
      ),
      FilledButton(
        onPressed: _picked.isEmpty
            ? null
            : () => Navigator.of(context).pop(_picked),
        child: const Text('แสดง'),
      ),
    ],
  );
}

/// The line chart itself: 0–100% on y, days on x, 2px lines with markers.
class ProgressLineChart extends StatelessWidget {
  const ProgressLineChart({
    super.key,
    required this.series,
    required this.colorOf,
  });

  final List<ProgressSeries> series;
  final Color Function(ProgressSeries) colorOf;

  static double _x(DateTime day) =>
      day.millisecondsSinceEpoch / Duration.millisecondsPerDay;

  static DateTime _day(double x) => DateTime.fromMillisecondsSinceEpoch(
    (x * Duration.millisecondsPerDay).round(),
    isUtc: true,
  );

  @override
  Widget build(BuildContext context) {
    final days = [
      for (final s in series)
        for (final p in s.daily) _x(p.date),
    ];
    var minX = days.reduce((a, b) => a < b ? a : b);
    var maxX = days.reduce((a, b) => a > b ? a : b);
    if (maxX - minX < 2) {
      minX -= 1;
      maxX += 1;
    }
    final span = maxX - minX;
    final interval = span <= 7 ? 1.0 : (span / 5).ceilToDouble();
    final axis = ChartColors.axisText(context);
    final surface = Theme.of(context).colorScheme.surface;
    return ChartViewport(
      key: const ValueKey('progress_chart'),
      height: 240,
      semanticsLabel: [
        for (final s in series)
          '${s.skill.code} ${s.points.isEmpty ? '' : pct(s.points.last.value)}',
      ].join(', '),
      child: LineChart(
        LineChartData(
          minY: 0,
          maxY: 100,
          minX: minX,
          maxX: maxX,
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
                reservedSize: 24,
                interval: interval,
                getTitlesWidget: (v, meta) => SideTitleWidget(
                  meta: meta,
                  child: Text(shortThaiDay(_day(v)), style: axis),
                ),
              ),
            ),
          ),
          lineTouchData: LineTouchData(
            touchTooltipData: LineTouchTooltipData(
              getTooltipColor: (_) =>
                  Theme.of(context).colorScheme.inverseSurface,
              getTooltipItems: (spots) => [
                for (final spot in spots)
                  LineTooltipItem(
                    '${series[spot.barIndex].skill.code} '
                    '${spot.y.round()}% · ${shortThaiDay(_day(spot.x))}',
                    TextStyle(
                      color: Theme.of(context).colorScheme.onInverseSurface,
                      fontSize: 12,
                    ),
                  ),
              ],
            ),
          ),
          lineBarsData: [
            for (final s in series)
              LineChartBarData(
                spots: [
                  for (final p in s.daily) FlSpot(_x(p.date), p.value * 100),
                ],
                color: colorOf(s),
                barWidth: 2,
                isCurved: false,
                dotData: FlDotData(
                  getDotPainter: (_, _, _, _) => FlDotCirclePainter(
                    radius: 4,
                    color: colorOf(s),
                    strokeWidth: 2,
                    strokeColor: surface,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Adds [skill] to the progress chart from a drill-down; tells the user
/// when the chart already has [kMaxProgressSkills] lines.
void addProgressSkill(
  BuildContext context,
  ProgressSelection selection,
  Skill skill,
  VoidCallback onChanged,
) {
  if (!selection.add(skill.id)) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text(
          'แสดงได้ไม่เกิน $kMaxProgressSkills ตัวชี้วัด เอาเส้นอื่นออกก่อน',
        ),
      ),
    );
    return;
  }
  onChanged();
}
