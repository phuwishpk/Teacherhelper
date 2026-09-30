import 'package:flutter/material.dart';

/// Colors of the Phase 9 charts (DESIGN §20.4), from one validated palette
/// (categorical order checked for color-vision deficiency in light and dark;
/// the dark column is its own stepped set, not an inversion). Series color
/// follows the entity: a line keeps its slot while others come and go.
abstract final class ChartColors {
  static const _seriesLight = [
    Color(0xFF2A78D6), // blue
    Color(0xFFEB6834), // orange
    Color(0xFF1BAF7A), // aqua
    Color(0xFFEDA100), // yellow
    Color(0xFFE87BA4), // magenta
  ];
  static const _seriesDark = [
    Color(0xFF3987E5),
    Color(0xFFD95926),
    Color(0xFF199E70),
    Color(0xFFC98500),
    Color(0xFFD55181),
  ];

  static bool _dark(BuildContext context) =>
      Theme.of(context).colorScheme.brightness == Brightness.dark;

  /// Categorical slot [i] (0-based, at most 5 lines at once).
  static Color series(BuildContext context, int i) {
    final list = _dark(context) ? _seriesDark : _seriesLight;
    return list[i % list.length];
  }

  /// The one data color of a single-series chart.
  static Color primary(BuildContext context) => series(context, 0);

  /// A placeholder mark for "not assessed yet": recessive, never a hue.
  static Color faint(BuildContext context) =>
      Theme.of(context).colorScheme.surfaceContainerHighest;

  /// Hairline grid.
  static Color grid(BuildContext context) =>
      Theme.of(context).colorScheme.outlineVariant.withValues(alpha: 0.6);

  /// Ordinal blue steps of chart (5), most done first: assessed, taught
  /// but not assessed, not taught. The step nearest the surface still
  /// clears 2:1 against it.
  static List<Color> planSteps(BuildContext context) => _dark(context)
      ? const [Color(0xFF86B6EF), Color(0xFF3987E5), Color(0xFF184F95)]
      : const [Color(0xFF184F95), Color(0xFF3987E5), Color(0xFF86B6EF)];

  /// Axis and tick text: muted ink, never a series color.
  static TextStyle? axisText(BuildContext context) =>
      Theme.of(context).textTheme.labelSmall?.copyWith(
        color: Theme.of(context).colorScheme.onSurfaceVariant,
        fontFeatures: const [FontFeature.tabularFigures()],
      );
}

/// "72%" of a 0–1 value.
String pct(double v) => '${(v * 100).round()}%';

/// An indicator code on two lines for a narrow axis label: "ค 1.1 ป.5/1"
/// -> "ค 1.1\nป.5/1" (split at the last space).
String twoLineCode(String code) {
  final i = code.lastIndexOf(' ');
  return i <= 0 ? code : '${code.substring(0, i)}\n${code.substring(i + 1)}';
}

/// A titled card around one chart, with the coverage line under it
/// (DESIGN §20.4: every chart shows its coverage).
class ChartCard extends StatelessWidget {
  const ChartCard({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.footer,
    this.action,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  /// Usually "ประเมินแล้ว x/y ตัวชี้วัด".
  final String? footer;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(title, style: theme.textTheme.titleMedium),
                ),
                ?action,
              ],
            ),
            if (subtitle != null) ...[
              const SizedBox(height: 2),
              Text(subtitle!, style: muted),
            ],
            const SizedBox(height: 12),
            child,
            if (footer != null) ...[
              const SizedBox(height: 8),
              Text(footer!, style: muted),
            ],
          ],
        ),
      ),
    );
  }
}

/// A color swatch and its label, for legends (identity never by color alone).
class LegendItem extends StatelessWidget {
  const LegendItem({super.key, required this.color, required this.label});

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(
        width: 12,
        height: 12,
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(3),
        ),
      ),
      const SizedBox(width: 6),
      Flexible(
        child: Text(
          label,
          style: Theme.of(context).textTheme.bodySmall,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    ],
  );
}

/// The chart area of a card: fixed height; wider than the screen when
/// [minWidth] asks for it (many bars), then it scrolls sideways.
class ChartViewport extends StatelessWidget {
  const ChartViewport({
    super.key,
    required this.height,
    required this.child,
    this.minWidth = 0,
    this.semanticsLabel,
  });

  final double height;
  final double minWidth;
  final Widget child;
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) => Semantics(
    label: semanticsLabel,
    child: LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        if (minWidth <= width) {
          return SizedBox(height: height, child: child);
        }
        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: SizedBox(width: minWidth, height: height, child: child),
        );
      },
    ),
  );
}

/// Shown in a chart card while there is nothing to draw.
class ChartEmpty extends StatelessWidget {
  const ChartEmpty({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 24),
    child: Text(
      message,
      textAlign: TextAlign.center,
      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    ),
  );
}

/// The radar / bar axis toggle of the spider chart (DESIGN §20.4).
class AxisToggle<T> extends StatelessWidget {
  const AxisToggle({
    super.key,
    required this.values,
    required this.selected,
    required this.label,
    required this.onChanged,
  });

  final List<T> values;
  final T selected;
  final String Function(T) label;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) => SegmentedButton<T>(
    showSelectedIcon: false,
    segments: [
      for (final v in values) ButtonSegment(value: v, label: Text(label(v))),
    ],
    selected: {selected},
    onSelectionChanged: (s) => onChanged(s.first),
  );
}
