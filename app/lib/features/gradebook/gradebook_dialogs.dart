import 'package:flutter/material.dart';

import 'gradebook_models.dart';

/// What the cell dialog asks for: scores to save, or to open the result
/// of an app-graded submission.
typedef CellEditResult = ({List<ScoreChange> changes, bool openResult});

/// Edit one cell (DESIGN §23.3): a typed score (manual exam or item) on a
/// number pad, clear it, or set "ยกเว้น". App-graded work takes "ยกเว้น"
/// only and can open its result.
class CellEditDialog extends StatefulWidget {
  const CellEditDialog({super.key, required this.row, required this.column});

  final GradebookRow row;
  final GradebookColumn column;

  @override
  State<CellEditDialog> createState() => _CellEditDialogState();
}

class _CellEditDialogState extends State<CellEditDialog> {
  late final GradebookCell _cell = widget.row.cell(widget.column.key);
  late final _score = TextEditingController(
    text: widget.column.editable && _cell.score != null
        ? formatGbNumber(_cell.score)
        : '',
  );
  late bool _excused = _cell.state == CellState.excused;
  String? _error;

  @override
  void dispose() {
    _score.dispose();
    super.dispose();
  }

  void _close(List<ScoreChange> changes, {bool openResult = false}) =>
      Navigator.of(
        context,
      ).pop<CellEditResult>((changes: changes, openResult: openResult));

  void _save() {
    final student = widget.row.studentId;
    final wasExcused = _cell.state == CellState.excused;
    if (!widget.column.editable) {
      _close([
        if (_excused != wasExcused) ScoreChange.excused(student, _excused),
      ]);
      return;
    }
    final text = _score.text.trim();
    double? value;
    if (text.isNotEmpty) {
      value = parseScore(text);
      final problem = scoreProblem(value, widget.column.fullMarks);
      if (problem != null) {
        setState(() => _error = 'คะแนน$problem');
        return;
      }
    }
    final scoreChanged = value != _cell.score;
    if (!scoreChanged && _excused == wasExcused) {
      _close(const []);
      return;
    }
    _close([
      ScoreChange(
        studentId: student,
        score: value,
        setScore: scoreChanged,
        excused: _excused != wasExcused ? _excused : null,
      ),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final column = widget.column;
    final row = widget.row;
    final stateText = cellStateLabel(_cell.state, column.type);
    return AlertDialog(
      title: Text('${row.studentNumber}. ${row.name}'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '${column.name} · เต็ม ${formatGbNumber(column.fullMarks)}',
              style: theme.textTheme.titleSmall,
            ),
            if (column.notCountedReason case final reason?)
              Text(reason, style: theme.textTheme.bodySmall),
            const SizedBox(height: 8),
            if (column.editable)
              TextField(
                key: const ValueKey('cell_score'),
                controller: _score,
                autofocus: true,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText: 'คะแนน',
                  hintText: 'เว้นว่าง = ไม่มีคะแนน',
                  suffixText: '/ ${formatGbNumber(column.fullMarks)}',
                  errorText: _error,
                ),
                onSubmitted: (_) => _save(),
              )
            else
              Text(
                _cell.state == CellState.scored
                    ? 'คะแนนจากผลที่ประกาศแล้ว '
                          '${formatGbNumber(_cell.score)}/'
                          '${formatGbNumber(column.fullMarks)}'
                    : 'ตรวจด้วยแอป: $stateText',
              ),
            SwitchListTile(
              key: const ValueKey('cell_excused'),
              contentPadding: EdgeInsets.zero,
              title: const Text('ยกเว้น'),
              subtitle: const Text('ไม่นำช่องนี้เข้าค่าเฉลี่ยของนักเรียน'),
              value: _excused,
              onChanged: (v) => setState(() => _excused = v),
            ),
          ],
        ),
      ),
      actions: [
        if (!column.editable && _cell.submissionId != null)
          TextButton(
            key: const ValueKey('cell_open_result'),
            onPressed: () => _close(const [], openResult: true),
            child: const Text('เปิดหน้าผล'),
          ),
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('cell_save'),
          onPressed: _save,
          child: const Text('บันทึก'),
        ),
      ],
    );
  }
}

/// "วางคะแนนจาก Excel" (§23.3): the clipboard's values in roster order
/// from the chosen student, with a preview of every change and of the
/// values that cannot be saved (not a number, over full marks, negative).
class PasteScoresDialog extends StatefulWidget {
  const PasteScoresDialog({
    super.key,
    required this.rows,
    required this.column,
    required this.text,
    this.startIndex = 0,
  });

  final List<GradebookRow> rows;
  final GradebookColumn column;
  final String text;
  final int startIndex;

  @override
  State<PasteScoresDialog> createState() => _PasteScoresDialogState();
}

class _PasteScoresDialogState extends State<PasteScoresDialog> {
  late int _start = widget.startIndex.clamp(0, widget.rows.length - 1);
  late final List<PastedScore> _values = parsePastedScores(
    widget.text,
    widget.column.fullMarks,
  );

  List<(GradebookRow, PastedScore)> get _pairs => [
    for (var i = 0; i < _values.length; i++)
      if (_start + i < widget.rows.length)
        (widget.rows[_start + i], _values[i]),
  ];

  int get _overflow =>
      (_start + _values.length - widget.rows.length).clamp(0, _values.length);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pairs = _pairs;
    final valid = [
      for (final (row, v) in pairs)
        if (v.valid) ScoreChange.score(row.studentId, v.value),
    ];
    final invalid = pairs.where((p) => !p.$2.blank && !p.$2.valid).length;
    return AlertDialog(
      title: Text('วางคะแนน: ${widget.column.name}'),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            DropdownButtonFormField<int>(
              key: const ValueKey('paste_start'),
              initialValue: _start,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'เริ่มจาก'),
              items: [
                for (var i = 0; i < widget.rows.length; i++)
                  DropdownMenuItem(
                    value: i,
                    child: Text(
                      'เลขที่ ${widget.rows[i].studentNumber} '
                      '${widget.rows[i].name}',
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: (v) => setState(() => _start = v ?? 0),
            ),
            const SizedBox(height: 8),
            Text(
              [
                'อ่านได้ ${_values.length} บรรทัด',
                'บันทึกได้ ${valid.length} ค่า',
                if (invalid > 0) 'ค่าที่ผิด $invalid ค่า (ไม่บันทึก)',
                if (_overflow > 0) 'เกินจำนวนนักเรียน $_overflow บรรทัด',
              ].join(' · '),
              key: const ValueKey('paste_summary'),
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 8),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final (row, v) in pairs)
                    _PasteLine(row: row, value: v, column: widget.column),
                ],
              ),
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
          key: const ValueKey('paste_save'),
          onPressed: valid.isEmpty
              ? null
              : () => Navigator.of(context).pop(valid),
          child: Text('บันทึก ${valid.length} ค่า'),
        ),
      ],
    );
  }
}

class _PasteLine extends StatelessWidget {
  const _PasteLine({
    required this.row,
    required this.value,
    required this.column,
  });

  final GradebookRow row;
  final PastedScore value;
  final GradebookColumn column;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final cell = row.cell(column.key);
    final before = cell.score == null ? 'ว่าง' : formatGbNumber(cell.score);
    final String after;
    Color? color;
    if (value.blank) {
      after = 'ไม่เปลี่ยน';
      color = theme.colorScheme.outline;
    } else if (value.error != null) {
      after = '"${value.raw}" ${value.error}';
      color = theme.colorScheme.error;
    } else {
      after = formatGbNumber(value.value);
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          SizedBox(width: 32, child: Text('${row.studentNumber}')),
          Expanded(child: Text(row.name, overflow: TextOverflow.ellipsis)),
          Text('$before → ', style: theme.textTheme.bodySmall),
          Flexible(
            child: Text(
              after,
              style: TextStyle(color: color, fontWeight: FontWeight.w600),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}

/// A classroom choice of "เพิ่มรายการคะแนน".
typedef ClassroomChoice = ({int id, String name});

/// "เพิ่มรายการคะแนน" / "แก้รายการ" (§23.3): name, full marks, category,
/// "เป็นคะแนนการเข้าเรียน", and on create this classroom or every
/// classroom of the course (one item each).
class GradebookItemDialog extends StatefulWidget {
  const GradebookItemDialog({
    super.key,
    required this.categories,
    required this.classroomId,
    this.classrooms = const [],
    this.existing,
  });

  final List<GridCategory> categories;
  final int classroomId;
  final List<ClassroomChoice> classrooms;
  final GradebookColumn? existing;

  @override
  State<GradebookItemDialog> createState() => _GradebookItemDialogState();
}

class _GradebookItemDialogState extends State<GradebookItemDialog> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.existing?.name);
  late final _max = TextEditingController(
    text: widget.existing == null
        ? ''
        : formatGbNumber(widget.existing!.fullMarks),
  );
  late int? _categoryId =
      widget.existing?.categoryId ?? widget.categories.firstOrNull?.id;
  late bool _attendance = widget.existing?.isAttendance ?? false;
  bool _allClassrooms = false;

  @override
  void dispose() {
    _name.dispose();
    _max.dispose();
    super.dispose();
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    Navigator.of(context).pop(
      GradebookItemDraft(
        name: _name.text.trim(),
        maxPoints: parseScore(_max.text)!,
        categoryId: _categoryId!,
        isAttendance: _attendance,
        classroomIds: _allClassrooms
            ? [for (final c in widget.classrooms) c.id]
            : [widget.classroomId],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.existing != null;
    return AlertDialog(
      title: Text(editing ? 'แก้รายการคะแนน' : 'เพิ่มรายการคะแนน'),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextFormField(
                key: const ValueKey('item_name'),
                controller: _name,
                decoration: const InputDecoration(
                  labelText: 'ชื่อรายการ',
                  hintText: 'เช่น การแต่งกาย ความตั้งใจ การเข้าเรียน',
                ),
                validator: (v) {
                  final t = v?.trim() ?? '';
                  if (t.isEmpty) return 'กรุณาตั้งชื่อรายการ';
                  return t.length > 100 ? 'ยาวไม่เกิน 100 ตัวอักษร' : null;
                },
              ),
              TextFormField(
                key: const ValueKey('item_max'),
                controller: _max,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(labelText: 'คะแนนเต็ม'),
                validator: (v) {
                  final n = parseScore(v ?? '');
                  if (n == null || n <= 0) return 'คะแนนเต็มต้องมากกว่า 0';
                  if (n > 1000) return 'คะแนนเต็มต้องไม่เกิน 1000';
                  return scoreProblem(n, 1000) == null
                      ? null
                      : 'ทศนิยมได้ไม่เกิน 2 ตำแหน่ง';
                },
              ),
              DropdownButtonFormField<int>(
                key: const ValueKey('item_category'),
                initialValue: _categoryId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'หมวดคะแนน'),
                items: [
                  for (final c in widget.categories)
                    DropdownMenuItem(
                      value: c.id,
                      child: Text(
                        '${c.name} (${formatGbNumber(c.weight)}%)',
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                ],
                onChanged: (v) => setState(() => _categoryId = v),
                validator: (v) => v == null ? 'เลือกหมวดคะแนน' : null,
              ),
              SwitchListTile(
                key: const ValueKey('item_attendance'),
                contentPadding: EdgeInsets.zero,
                title: const Text('เป็นคะแนนการเข้าเรียน'),
                subtitle: const Text('ใช้เตือน "อาจติด มส" เมื่อต่ำกว่า 80%'),
                value: _attendance,
                onChanged: (v) => setState(() => _attendance = v),
              ),
              if (!editing && widget.classrooms.length > 1)
                SwitchListTile(
                  key: const ValueKey('item_all_classrooms'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text('เพิ่มให้ทุกห้องของรายวิชา'),
                  subtitle: Text(
                    widget.classrooms.map((c) => c.name).join(', '),
                  ),
                  value: _allClassrooms,
                  onChanged: (v) => setState(() => _allClassrooms = v),
                ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('item_save'),
          onPressed: _submit,
          child: Text(editing ? 'บันทึก' : 'เพิ่ม'),
        ),
      ],
    );
  }
}

/// ร/มส of one student (§23.6): the teacher's choice with a short note.
typedef SpecialGradeChoice = ({String? special, String? note});

/// One student's row: totals, warnings, and "ร" / "มส" instead of the
/// numeric grade.
class StudentRowDialog extends StatefulWidget {
  const StudentRowDialog({super.key, required this.row, required this.grid});

  final GradebookRow row;
  final GradebookGrid grid;

  @override
  State<StudentRowDialog> createState() => _StudentRowDialogState();
}

class _StudentRowDialogState extends State<StudentRowDialog> {
  late String _special = widget.row.special ?? 'none';
  late final _note = TextEditingController(text: widget.row.specialNote);

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final row = widget.row;
    final grid = widget.grid;
    final categoryCount = grid.categories.length;
    return AlertDialog(
      title: Text('${row.studentNumber}. ${row.name}'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final c in grid.categories)
              Text(
                '${c.name} (${formatGbNumber(c.weight)}%): '
                '${row.categories[c.id]?.percent == null ? 'ยังไม่มีคะแนน' : '${formatGbNumber(row.categories[c.id]!.percent)}% '
                          '→ ${formatGbNumber(row.categories[c.id]!.points)} คะแนน'}',
              ),
            const SizedBox(height: 8),
            Text(
              row.total == null
                  ? 'ยังไม่มีคะแนนรวม'
                  : row.inProgress
                  ? 'คะแนนระหว่างภาค ${formatGbNumber(row.total)}% '
                        'คิดจาก ${row.valuedCategoryCount} จาก $categoryCount หมวด '
                        '(น้ำหนักรวม ${formatGbNumber(row.countedWeight)}%)'
                  : 'คะแนนรวม ${formatGbNumber(row.total)} '
                        '→ ${row.totalRounded ?? '–'} เกรด ${gradeLabel(row.grade, null)}',
              key: const ValueKey('student_total'),
              style: theme.textTheme.titleSmall,
            ),
            if (row.attendanceWarning)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'อาจติด มส: การเข้าเรียนต่ำกว่า 80% (คำเตือนเท่านั้น '
                  'ครูตัดสินเองว่าจะตั้ง มส หรือไม่)',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            const SizedBox(height: 12),
            Text('เกรดพิเศษ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            SegmentedButton<String>(
              key: const ValueKey('special_choice'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 'none', label: Text('ไม่มี')),
                ButtonSegment(value: 'r', label: Text('ร')),
                ButtonSegment(value: 'ms', label: Text('มส')),
              ],
              selected: {_special},
              onSelectionChanged: (s) => setState(() => _special = s.first),
            ),
            if (_special != 'none')
              TextField(
                key: const ValueKey('special_note'),
                controller: _note,
                maxLength: 255,
                decoration: const InputDecoration(
                  labelText: 'หมายเหตุ (ไม่บังคับ นักเรียนไม่เห็น)',
                ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ปิด'),
        ),
        FilledButton(
          key: const ValueKey('special_save'),
          onPressed: () => Navigator.of(context).pop<SpecialGradeChoice>((
            special: _special == 'none' ? null : _special,
            note: _special == 'none' ? null : _note.text.trim(),
          )),
          child: const Text('บันทึก'),
        ),
      ],
    );
  }
}
