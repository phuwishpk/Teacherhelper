import 'package:flutter/material.dart';

/// One cell of a [HeatmapGrid].
class HeatmapCell {
  const HeatmapCell({
    required this.text,
    required this.fill,
    required this.tooltip,
    this.textColor,
    this.onTap,
  });

  /// The value written in the cell, so color is never the only channel.
  final String text;
  final Color fill;
  final Color? textColor;

  /// Full description (long-press on a phone); also the semantics label.
  final String tooltip;
  final VoidCallback? onTap;
}

/// A column header: a short label and its full name.
class HeatmapColumn {
  const HeatmapColumn({required this.label, required this.tooltip});

  final String label;
  final String tooltip;
}

/// A labelled run of adjacent columns (e.g. the indicators of one
/// standard, DESIGN §20.4 chart 3).
class HeatmapColumnGroup {
  const HeatmapColumnGroup({
    required this.label,
    required this.span,
    this.tooltip,
  });

  final String label;
  final int span;
  final String? tooltip;
}

/// A row x column grid of colored cells with a fixed row-header column and
/// horizontally scrolling cells, so it stays readable on a phone (DESIGN
/// §14.3 heatmaps). Cells are separated by a 2px surface gap.
class HeatmapGrid extends StatelessWidget {
  const HeatmapGrid({
    super.key,
    required this.rows,
    required this.columns,
    required this.cell,
    this.rowHeaderWidth = 132,
    this.cellWidth = 64,
    this.cellHeight = 40,
    this.headerHeight = 44,
    this.onRowTap,
    this.corner,
    this.columnGroups = const [],
  });

  /// Row headers (e.g. student number + name, or a skill code).
  final List<Widget> rows;
  final List<HeatmapColumn> columns;
  final HeatmapCell Function(int row, int column) cell;
  final double rowHeaderWidth;
  final double cellWidth;
  final double cellHeight;
  final double headerHeight;
  final ValueChanged<int>? onRowTap;

  /// Top-left label above the row headers.
  final Widget? corner;

  /// Optional band above the column headers; spans must add up to the
  /// number of columns.
  final List<HeatmapColumnGroup> columnGroups;

  static const gap = 2.0;

  static const groupHeight = 28.0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget rowHeader(int r) {
      final child = Container(
        height: cellHeight,
        margin: const EdgeInsets.only(bottom: gap),
        padding: const EdgeInsets.only(right: 8),
        alignment: Alignment.centerLeft,
        child: DefaultTextStyle.merge(
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodySmall,
          child: rows[r],
        ),
      );
      return onRowTap == null
          ? child
          : InkWell(onTap: () => onRowTap!(r), child: child);
    }

    Widget cellAt(int r, int c) {
      final data = cell(r, c);
      final textColor =
          data.textColor ??
          (ThemeData.estimateBrightnessForColor(data.fill) == Brightness.dark
              ? Colors.white
              : Colors.black87);
      final box = Container(
        width: cellWidth,
        height: cellHeight,
        margin: const EdgeInsets.only(right: gap, bottom: gap),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: data.fill,
          borderRadius: BorderRadius.circular(4),
        ),
        child: Text(
          data.text,
          style: theme.textTheme.labelMedium?.copyWith(
            color: textColor,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      );
      return Semantics(
        label: data.tooltip,
        excludeSemantics: true,
        child: Tooltip(
          message: data.tooltip,
          child: data.onTap == null
              ? box
              : InkWell(onTap: data.onTap, child: box),
        ),
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: rowHeaderWidth,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                height:
                    headerHeight +
                    gap +
                    (columnGroups.isEmpty ? 0 : groupHeight + gap),
                child: Align(
                  alignment: Alignment.bottomLeft,
                  child: DefaultTextStyle.merge(
                    style: muted,
                    child: corner ?? const SizedBox.shrink(),
                  ),
                ),
              ),
              for (var r = 0; r < rows.length; r++) rowHeader(r),
            ],
          ),
        ),
        Expanded(
          child: Scrollbar(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (columnGroups.isNotEmpty)
                    Row(
                      children: [
                        for (final g in columnGroups)
                          Tooltip(
                            message: g.tooltip ?? g.label,
                            child: Container(
                              width: g.span * (cellWidth + gap) - gap,
                              height: groupHeight,
                              margin: const EdgeInsets.only(
                                right: gap,
                                bottom: gap,
                              ),
                              alignment: Alignment.bottomLeft,
                              decoration: BoxDecoration(
                                border: Border(
                                  bottom: BorderSide(
                                    color: theme.colorScheme.outline,
                                  ),
                                ),
                              ),
                              child: Text(
                                g.label,
                                style: theme.textTheme.labelMedium,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ),
                      ],
                    ),
                  Row(
                    children: [
                      for (final col in columns)
                        Tooltip(
                          message: col.tooltip,
                          child: Container(
                            width: cellWidth,
                            height: headerHeight,
                            margin: const EdgeInsets.only(
                              right: gap,
                              bottom: gap,
                            ),
                            alignment: Alignment.bottomCenter,
                            child: Text(
                              col.label,
                              style: muted,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              textAlign: TextAlign.center,
                            ),
                          ),
                        ),
                    ],
                  ),
                  for (var r = 0; r < rows.length; r++)
                    Row(
                      children: [
                        for (var c = 0; c < columns.length; c++) cellAt(r, c),
                      ],
                    ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Single-hue sequential ramp for counts (light to dark blue; on a dark
/// surface the low end recedes toward the surface instead). Six steps of
/// a validated blue ramp; zero gets the neutral surface.
abstract final class CountRamp {
  static const _light = [
    Color(0xFFCDE2FB),
    Color(0xFF9EC5F4),
    Color(0xFF6DA7EC),
    Color(0xFF3987E5),
    Color(0xFF256ABF),
    Color(0xFF184F95),
  ];
  static const _dark = [
    Color(0xFF0D366B),
    Color(0xFF104281),
    Color(0xFF1C5CAB),
    Color(0xFF2A78D6),
    Color(0xFF5598E7),
    Color(0xFF86B6EF),
  ];

  static Color of(BuildContext context, int count, int max) {
    final scheme = Theme.of(context).colorScheme;
    if (count <= 0 || max <= 0) return scheme.surfaceContainerHighest;
    final steps = scheme.brightness == Brightness.dark ? _dark : _light;
    final i = ((count / max) * steps.length).ceil().clamp(1, steps.length);
    return steps[i - 1];
  }

  /// The lightest and darkest non-zero steps, for the legend.
  static List<Color> legend(BuildContext context) =>
      Theme.of(context).colorScheme.brightness == Brightness.dark
      ? _dark
      : _light;
}
