import 'package:flutter/material.dart';

import 'gradebook_models.dart';

const _numberWidth = 44.0;
const _nameWidth = 156.0;
const _columnWidth = 88.0;
const _summaryWidth = 76.0;
const _groupHeight = 30.0;
const _headerHeight = 64.0;
const _rowHeight = 48.0;

/// Where the horizontal list starts showing [key] (for "กรอกคะแนน" from
/// an exam, §23.9).
double gradebookColumnOffset(GradebookGrid grid, String key) {
  final index = grid.columns.indexWhere((c) => c.key == key);
  return index < 0 ? 0 : index * _columnWidth;
}

/// The grid of a classroom (DESIGN §23.3, §23.9): number and name frozen on
/// the left, columns grouped by category and scrolled sideways, then the
/// per-category percent, the total and the grade. Faint columns are not
/// counted; cells carry "ไม่ส่ง", "ไม่มีคะแนน", "ยังไม่ถึงกำหนด",
/// "รอประกาศผล", "ยกเว้น" and "ตัดออก"; rows carry "อาจติด มส", ร and มส.
class GradebookTable extends StatelessWidget {
  const GradebookTable({
    super.key,
    required this.grid,
    required this.onCellTap,
    required this.onColumnTap,
    required this.onRowTap,
    this.horizontalController,
    this.highlightKey,
  });

  final GradebookGrid grid;
  final void Function(GradebookRow row, GradebookColumn column) onCellTap;
  final ValueChanged<GradebookColumn> onColumnTap;
  final ValueChanged<GradebookRow> onRowTap;
  final ScrollController? horizontalController;
  final String? highlightKey;

  List<(String, int)> _groups() {
    final out = <(String, int)>[];
    int? current;
    var started = false;
    for (final c in grid.columns) {
      if (!started || c.categoryId != current) {
        final cat = grid.category(c.categoryId);
        out.add((
          cat == null
              ? 'ยังไม่ระบุหมวด'
              : '${cat.name} (${formatGbNumber(cat.weight)}%)',
          0,
        ));
        current = c.categoryId;
        started = true;
      }
      out[out.length - 1] = (out.last.$1, out.last.$2 + 1);
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final border = BorderSide(color: theme.dividerColor, width: 0.5);
    final headerColor = theme.colorScheme.surfaceContainerHighest;
    final groups = _groups();

    Widget box({
      required double width,
      required double height,
      required Widget child,
      Color? color,
      Key? key,
      VoidCallback? onTap,
      Alignment alignment = Alignment.center,
    }) {
      final content = Container(
        width: width,
        height: height,
        alignment: alignment,
        padding: const EdgeInsets.symmetric(horizontal: 4),
        decoration: BoxDecoration(
          color: color,
          border: Border(right: border, bottom: border),
        ),
        child: child,
      );
      return onTap == null
          ? KeyedSubtree(key: key, child: content)
          : InkWell(key: key, onTap: onTap, child: content);
    }

    final left = Column(
      children: [
        box(
          width: _numberWidth + _nameWidth,
          height: _groupHeight,
          color: headerColor,
          child: const SizedBox.shrink(),
        ),
        Row(
          children: [
            box(
              width: _numberWidth,
              height: _headerHeight,
              color: headerColor,
              child: Text('เลขที่', style: theme.textTheme.labelSmall),
            ),
            box(
              width: _nameWidth,
              height: _headerHeight,
              color: headerColor,
              alignment: Alignment.centerLeft,
              child: Text('ชื่อ', style: theme.textTheme.labelMedium),
            ),
          ],
        ),
        for (final r in grid.rows)
          InkWell(
            key: ValueKey('row_${r.studentId}'),
            onTap: () => onRowTap(r),
            child: Row(
              children: [
                box(
                  width: _numberWidth,
                  height: _rowHeight,
                  child: Text('${r.studentNumber}'),
                ),
                box(
                  width: _nameWidth,
                  height: _rowHeight,
                  alignment: Alignment.centerLeft,
                  child: _NameCell(row: r),
                ),
              ],
            ),
          ),
      ],
    );

    final right = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            for (final (label, count) in groups)
              box(
                width: count * _columnWidth,
                height: _groupHeight,
                color: headerColor,
                child: Text(
                  label,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelMedium,
                ),
              ),
            box(
              width: (grid.categories.length + 2) * _summaryWidth,
              height: _groupHeight,
              color: theme.colorScheme.secondaryContainer,
              child: Text('สรุป', style: theme.textTheme.labelMedium),
            ),
          ],
        ),
        Row(
          children: [
            for (final c in grid.columns)
              box(
                key: ValueKey('col_${c.key}'),
                width: _columnWidth,
                height: _headerHeight,
                color: c.key == highlightKey
                    ? theme.colorScheme.primaryContainer
                    : headerColor,
                onTap: () => onColumnTap(c),
                child: _ColumnHeader(column: c),
              ),
            for (final cat in grid.categories)
              box(
                width: _summaryWidth,
                height: _headerHeight,
                color: theme.colorScheme.secondaryContainer,
                child: Text(
                  '${cat.name}\n%',
                  textAlign: TextAlign.center,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall,
                ),
              ),
            box(
              width: _summaryWidth,
              height: _headerHeight,
              color: theme.colorScheme.secondaryContainer,
              child: Text(
                grid.complete ? 'รวม' : 'รวมระหว่างภาค',
                textAlign: TextAlign.center,
                style: theme.textTheme.labelSmall,
              ),
            ),
            box(
              width: _summaryWidth,
              height: _headerHeight,
              color: theme.colorScheme.secondaryContainer,
              child: Text('เกรด', style: theme.textTheme.labelMedium),
            ),
          ],
        ),
        for (final r in grid.rows)
          Row(
            children: [
              for (final c in grid.columns)
                box(
                  key: ValueKey('cell_${r.studentId}_${c.key}'),
                  width: _columnWidth,
                  height: _rowHeight,
                  color: c.key == highlightKey
                      ? theme.colorScheme.primaryContainer.withValues(
                          alpha: 0.35,
                        )
                      : null,
                  onTap: () => onCellTap(r, c),
                  child: _Cell(cell: r.cell(c.key), column: c),
                ),
              for (final cat in grid.categories)
                box(
                  width: _summaryWidth,
                  height: _rowHeight,
                  child: Text(formatGbNumber(r.categories[cat.id]?.percent)),
                ),
              box(
                key: ValueKey('total_${r.studentId}'),
                width: _summaryWidth,
                height: _rowHeight,
                child: Text(
                  r.total == null
                      ? '–'
                      : r.totalRounded != null
                      ? '${r.totalRounded}'
                      : formatGbNumber(r.total),
                  style: r.inProgress
                      ? const TextStyle(fontStyle: FontStyle.italic)
                      : const TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
              box(
                key: ValueKey('grade_${r.studentId}'),
                width: _summaryWidth,
                height: _rowHeight,
                child: Text(
                  r.gradeText,
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: r.special != null ? theme.colorScheme.error : null,
                  ),
                ),
              ),
            ],
          ),
      ],
    );

    return SingleChildScrollView(
      padding: const EdgeInsets.only(bottom: 88),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          left,
          Expanded(
            child: SingleChildScrollView(
              key: const ValueKey('gradebook_horizontal'),
              controller: horizontalController,
              scrollDirection: Axis.horizontal,
              child: right,
            ),
          ),
        ],
      ),
    );
  }
}

class _NameCell extends StatelessWidget {
  const _NameCell({required this.row});

  final GradebookRow row;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final badges = [
      ?specialLabel(row.special),
      if (row.attendanceWarning) 'อาจติด มส',
      if (row.leftCourse) 'ออกจากรายวิชา',
    ];
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(row.name, maxLines: 1, overflow: TextOverflow.ellipsis),
        if (badges.isNotEmpty)
          Text(
            badges.join(' · '),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
      ],
    );
  }
}

class _ColumnHeader extends StatelessWidget {
  const _ColumnHeader({required this.column});

  final GradebookColumn column;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = column;
    final sub = c.notCountedReason ?? 'เต็ม ${formatGbNumber(c.fullMarks)}';
    return Opacity(
      opacity: c.counted ? 1 : 0.55,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (c.editable)
                const Padding(
                  padding: EdgeInsets.only(right: 2),
                  child: Icon(Icons.edit, size: 12),
                ),
              if (c.isAttendance)
                const Padding(
                  padding: EdgeInsets.only(right: 2),
                  child: Icon(Icons.event_available, size: 12),
                ),
              Flexible(
                child: Text(
                  c.name,
                  maxLines: 2,
                  textAlign: TextAlign.center,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          Text(
            sub,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall,
          ),
        ],
      ),
    );
  }
}

class _Cell extends StatelessWidget {
  const _Cell({required this.cell, required this.column});

  final GradebookCell cell;
  final GradebookColumn column;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final small = theme.textTheme.labelSmall;
    final faint = theme.colorScheme.outline;
    Widget label(String text, Color? color, {FontStyle? style}) => Text(
      text,
      textAlign: TextAlign.center,
      maxLines: 2,
      style: small?.copyWith(color: color, fontStyle: style),
    );
    final score = cell.score == null ? null : formatGbNumber(cell.score);
    final Widget body = switch (cell.state) {
      CellState.scored => Text(
        score ?? '',
        style: cell.dropped
            ? TextStyle(decoration: TextDecoration.lineThrough, color: faint)
            : null,
      ),
      CellState.missing => label(
        cellStateLabel(cell.state, column.type),
        theme.colorScheme.error,
      ),
      CellState.notDue => label('ยังไม่ถึงกำหนด', faint),
      CellState.pending => label('รอประกาศผล', Colors.orange.shade800),
      CellState.excused => label('ยกเว้น', faint, style: FontStyle.italic),
      CellState.notCounted => Text(
        score ?? '–',
        style: TextStyle(color: faint),
      ),
    };
    if (!cell.dropped) return body;
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        body,
        Text('ตัดออก', style: small?.copyWith(color: faint)),
      ],
    );
  }
}
