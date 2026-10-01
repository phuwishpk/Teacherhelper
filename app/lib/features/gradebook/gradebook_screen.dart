import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import 'gradebook_dialogs.dart';
import 'gradebook_models.dart';
import 'gradebook_providers.dart';
import 'gradebook_repository.dart';
import 'gradebook_table.dart';

/// `/courses/:id/gradebook?classroom=&column=` (DESIGN §23.9): the
/// gradebook of a course. Not set up yet: pick a template, then the
/// settings. Set up: the live grid of one classroom of the course with
/// "เพิ่มรายการคะแนน", "ประกาศเกรด" and "ส่งออก CSV". [focusColumn]
/// (`a{id}` from a manual exam's "กรอกคะแนน") scrolls to that column.
class GradebookScreen extends ConsumerStatefulWidget {
  const GradebookScreen({
    super.key,
    required this.courseId,
    this.initialClassroomId,
    this.focusColumn,
  });

  final int courseId;
  final int? initialClassroomId;
  final String? focusColumn;

  @override
  ConsumerState<GradebookScreen> createState() => _GradebookScreenState();
}

class _GradebookScreenState extends ConsumerState<GradebookScreen> {
  late int? _classroomId = widget.initialClassroomId;
  final _horizontal = ScrollController();
  bool _focused = false;
  bool _busy = false;

  int get _courseId => widget.courseId;

  GradebookRepository get _repo => ref.read(gradebookRepositoryProvider);

  @override
  void dispose() {
    _horizontal.dispose();
    super.dispose();
  }

  /// Runs a change, then loads the grid (and the settings' item counts)
  /// again; errors become a message.
  Future<void> _run(
    Future<String?> Function() action, {
    bool settings = false,
  }) async {
    setState(() => _busy = true);
    try {
      final message = await action();
      if (settings) ref.invalidate(gradebookSettingsProvider(_courseId));
      ref.invalidate(gradebookGridProvider);
      if (mounted && message != null) showMessage(context, message);
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _openColumn(GradebookColumn c, {bool result = false}) {
    switch (c.type) {
      case ColumnType.custom:
        return;
      case ColumnType.manualExam:
        context.push(AppRoutes.exam(c.id));
      case ColumnType.assignment:
        if (c.isExam) {
          context.push(
            result ? AppRoutes.examResults(c.id) : AppRoutes.exam(c.id),
          );
        } else {
          context.push(
            result ? AppRoutes.review(c.id) : AppRoutes.assignment(c.id),
          );
        }
    }
  }

  Future<void> _cellTap(GradebookRow row, GradebookColumn column) async {
    final result = await showDialog<CellEditResult>(
      context: context,
      builder: (_) => CellEditDialog(row: row, column: column),
    );
    if (result == null || !mounted) return;
    if (result.openResult) {
      _openColumn(column, result: true);
      return;
    }
    if (result.changes.isEmpty) return;
    await _run(() async {
      await _repo.saveScores(column, result.changes);
      return 'บันทึกแล้ว';
    });
  }

  Future<void> _columnTap(
    GradebookGrid grid,
    GradebookColumn column,
    List<ClassroomChoice> classrooms,
  ) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      builder: (sheet) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text(column.name),
              subtitle: Text(
                [
                  'เต็ม ${formatGbNumber(column.fullMarks)}',
                  ?grid.category(column.categoryId)?.name,
                  ?column.notCountedReason,
                  if (column.counted) 'นับแล้วในห้องนี้',
                ].join(' · '),
              ),
            ),
            if (column.editable) ...[
              ListTile(
                key: const ValueKey('column_fill_full'),
                leading: const Icon(Icons.done_all),
                title: const Text('ให้เต็มทั้งห้อง'),
                subtitle: const Text(
                  'ใส่คะแนนเต็มให้ทุกคนที่ยังว่าง ไม่ทับค่าที่กรอกไว้',
                ),
                onTap: () => Navigator.of(sheet).pop('fill'),
              ),
              ListTile(
                key: const ValueKey('column_paste'),
                leading: const Icon(Icons.content_paste),
                title: const Text('วางคะแนนจาก Excel'),
                subtitle: const Text('หนึ่งค่าต่อบรรทัด เรียงตามเลขที่'),
                onTap: () => Navigator.of(sheet).pop('paste'),
              ),
            ],
            if (column.type == ColumnType.custom) ...[
              ListTile(
                key: const ValueKey('column_edit'),
                leading: const Icon(Icons.edit_outlined),
                title: const Text('แก้รายการ'),
                onTap: () => Navigator.of(sheet).pop('edit'),
              ),
              ListTile(
                key: const ValueKey('column_delete'),
                leading: const Icon(Icons.delete_outline),
                title: const Text('ลบรายการ'),
                onTap: () => Navigator.of(sheet).pop('delete'),
              ),
            ] else
              ListTile(
                key: const ValueKey('column_open'),
                leading: const Icon(Icons.open_in_new),
                title: Text(column.isExam ? 'เปิดข้อสอบ' : 'เปิดการบ้าน'),
                onTap: () => Navigator.of(sheet).pop('open'),
              ),
          ],
        ),
      ),
    );
    if (!mounted) return;
    switch (action) {
      case 'fill':
        await _run(() async {
          final n = await _repo.fillFull(column);
          return 'ให้เต็มแล้ว $n คน';
        });
      case 'paste':
        await _paste(grid, column);
      case 'edit':
        await _editItem(grid, column);
      case 'delete':
        final ok = await confirm(
          context,
          title: 'ลบรายการ ${column.name}?',
          message: 'คะแนนที่กรอกไว้ในรายการนี้จะถูกลบด้วย',
          confirmLabel: 'ลบ',
          destructive: true,
        );
        if (!ok) return;
        await _run(() async {
          await _repo.deleteItem(column.id);
          return 'ลบรายการแล้ว';
        }, settings: true);
      case 'open':
        _openColumn(column);
    }
  }

  Future<void> _paste(GradebookGrid grid, GradebookColumn column) async {
    final data = await Clipboard.getData(Clipboard.kTextPlain);
    final text = data?.text ?? '';
    if (!mounted) return;
    if (text.trim().isEmpty || grid.rows.isEmpty) {
      showMessage(
        context,
        'คลิปบอร์ดว่าง คัดลอกคอลัมน์คะแนนจาก Excel แล้วลองอีกครั้ง',
      );
      return;
    }
    final changes = await showDialog<List<ScoreChange>>(
      context: context,
      builder: (_) =>
          PasteScoresDialog(rows: grid.rows, column: column, text: text),
    );
    if (changes == null || changes.isEmpty || !mounted) return;
    await _run(() async {
      await _repo.saveScores(column, changes);
      return 'บันทึกคะแนน ${changes.length} คนแล้ว';
    });
  }

  Future<void> _addItem(
    GradebookGrid grid,
    List<ClassroomChoice> classrooms,
  ) async {
    final draft = await showDialog<GradebookItemDraft>(
      context: context,
      builder: (_) => GradebookItemDialog(
        categories: grid.categories,
        classroomId: grid.classroomId,
        classrooms: classrooms,
      ),
    );
    if (draft == null || !mounted) return;
    await _run(() async {
      final items = await _repo.addItem(_courseId, draft);
      return items.length > 1
          ? 'เพิ่มรายการให้ ${items.length} ห้องแล้ว'
          : 'เพิ่มรายการแล้ว';
    }, settings: true);
  }

  Future<void> _editItem(GradebookGrid grid, GradebookColumn column) async {
    final draft = await showDialog<GradebookItemDraft>(
      context: context,
      builder: (_) => GradebookItemDialog(
        categories: grid.categories,
        classroomId: grid.classroomId,
        existing: column,
      ),
    );
    if (draft == null || !mounted) return;
    await _run(() async {
      await _repo.updateItem(column.id, draft);
      return 'บันทึกรายการแล้ว';
    }, settings: true);
  }

  Future<void> _rowTap(GradebookGrid grid, GradebookRow row) async {
    final choice = await showDialog<SpecialGradeChoice>(
      context: context,
      builder: (_) => StudentRowDialog(row: row, grid: grid),
    );
    if (choice == null || !mounted) return;
    if (choice.special == row.special &&
        (choice.note ?? '') == (row.specialNote ?? '')) {
      return;
    }
    await _run(() async {
      await _repo.setSpecialGrade(
        _courseId,
        classroomId: grid.classroomId,
        studentId: row.studentId,
        special: choice.special,
        note: choice.note,
      );
      return choice.special == null
          ? 'ล้างเกรดพิเศษแล้ว'
          : 'ตั้งเกรด ${specialLabel(choice.special)} แล้ว';
    });
  }

  Future<void> _publish(GradebookGrid grid) async {
    final pending = grid.countCells(CellState.pending);
    final notDue = grid.countCells(CellState.notDue);
    final uncategorised = grid.uncategorisedColumns.length;
    final warnings = [
      if (pending > 0) 'มี $pending ช่องที่ "รอประกาศผล" (ไม่นับ)',
      if (notDue > 0) 'มี $notDue ช่องที่ "ยังไม่ถึงกำหนด" (ไม่นับ)',
      if (uncategorised > 0)
        'มี $uncategorised รายการที่ยังไม่ระบุหมวด (ไม่นับ)',
    ];
    final ok = await confirm(
      context,
      title: grid.publication == null ? 'ประกาศเกรด?' : 'ประกาศเกรดใหม่?',
      message: [
        'นักเรียน ${grid.rows.length} คนของห้อง ${grid.classroomName} '
            'จะเห็นเกรด คะแนนรวม และคะแนนรายหมวดของตัวเอง '
            '(ไม่เห็นของเพื่อนหรือค่าเฉลี่ยห้อง)',
        if (warnings.isNotEmpty) '\nโปรดตรวจก่อนประกาศ:',
        ...warnings.map((w) => '• $w'),
      ].join('\n'),
      confirmLabel: 'ประกาศ',
    );
    if (!ok || !mounted) return;
    await _run(() async {
      final r = await _repo.publish(_courseId, grid.classroomId);
      return 'ประกาศเกรดแล้ว ${r.studentCount} คน';
    });
  }

  Future<void> _withdraw(GradebookGrid grid) async {
    final ok = await confirm(
      context,
      title: 'ถอนประกาศเกรด?',
      message:
          'นักเรียนจะกลับไปเห็นฉบับที่ประกาศก่อนหน้า '
          'หรือไม่เห็นเกรดเลยถ้าไม่มีฉบับก่อนหน้า',
      confirmLabel: 'ถอนประกาศ',
      destructive: true,
    );
    if (!ok || !mounted) return;
    await _run(() async {
      await _repo.withdraw(_courseId, grid.classroomId);
      return 'ถอนประกาศแล้ว';
    });
  }

  Future<void> _export(GradebookGrid grid) async {
    setState(() => _busy = true);
    try {
      await shareGradebookCsv(
        ref,
        courseId: _courseId,
        classroomId: grid.classroomId,
        courseCode: grid.courseCode,
        classroomName: grid.classroomName,
      );
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _focusColumn(GradebookGrid grid) {
    final key = widget.focusColumn;
    if (_focused || key == null) return;
    _focused = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_horizontal.hasClients) return;
      final target = gradebookColumnOffset(grid, key);
      _horizontal.jumpTo(
        target.clamp(0, _horizontal.position.maxScrollExtent).toDouble(),
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final course = ref.watch(courseDetailProvider(_courseId));
    final settings = ref.watch(gradebookSettingsProvider(_courseId));
    final configured = settings.value?.configured ?? false;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          course.value == null
              ? 'สมุดคะแนน'
              : 'สมุดคะแนน ${course.value!.code}',
        ),
        actions: [
          if (configured)
            IconButton(
              key: const ValueKey('gradebook_open_settings'),
              tooltip: 'ตั้งค่าหมวดคะแนนและเกณฑ์เกรด',
              icon: const Icon(Icons.tune),
              onPressed: () =>
                  context.push(AppRoutes.gradebookSettings(_courseId)),
            ),
        ],
        bottom: _busy
            ? const PreferredSize(
                preferredSize: Size.fromHeight(4),
                child: LinearProgressIndicator(),
              )
            : null,
      ),
      body: AsyncView(
        value: settings,
        onRetry: () => ref.invalidate(gradebookSettingsProvider(_courseId)),
        data: (s) => s.configured
            ? _classroomBody(course)
            : _SetupView(courseId: _courseId),
      ),
    );
  }

  Widget _classroomBody(AsyncValue<Course> course) {
    return AsyncView(
      value: course,
      onRetry: () => ref.invalidate(courseDetailProvider(_courseId)),
      data: (c) {
        if (c.classrooms.isEmpty) {
          return const EmptyView(
            icon: Icons.groups_outlined,
            title: 'รายวิชานี้ยังไม่ผูกห้องเรียน',
            message: 'แก้ไขรายวิชาเพื่อเลือกห้องเรียน แล้วกลับมาที่สมุดคะแนน',
          );
        }
        final classroomId = c.classrooms.any((r) => r.id == _classroomId)
            ? _classroomId!
            : c.classrooms.first.id;
        final choices = [
          for (final r in c.classrooms) (id: r.id, name: r.name),
        ];
        final grid = ref.watch(
          gradebookGridProvider((
            courseId: _courseId,
            classroomId: classroomId,
          )),
        );
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (c.classrooms.length > 1)
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                child: Row(
                  children: [
                    for (final r in c.classrooms)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          key: ValueKey('gradebook_classroom_${r.id}'),
                          label: Text(r.name),
                          selected: r.id == classroomId,
                          onSelected: (_) =>
                              setState(() => _classroomId = r.id),
                        ),
                      ),
                  ],
                ),
              ),
            Expanded(
              child: AsyncView(
                value: grid,
                onRetry: () => ref.invalidate(gradebookGridProvider),
                data: (g) {
                  _focusColumn(g);
                  return _gridView(g, choices);
                },
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _gridView(GradebookGrid g, List<ClassroomChoice> classrooms) {
    final theme = Theme.of(context);
    final uncategorised = g.uncategorisedColumns.length;
    final publication = g.publication;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (!g.complete)
                _Note(
                  key: const ValueKey('gradebook_in_progress'),
                  icon: Icons.hourglass_bottom,
                  color: Colors.orange.shade800,
                  text:
                      'คะแนนระหว่างภาค: ยังไม่มีรายการที่นับในหมวด '
                      '${g.missingCategories.join(', ')} '
                      '(คิดจากน้ำหนักรวม ${formatGbNumber(g.countedWeight)}%) '
                      'ยังตัดเกรดและประกาศเกรดไม่ได้',
                ),
              if (publication != null)
                _Note(
                  key: const ValueKey('gradebook_published'),
                  icon: publication.stale
                      ? Icons.sync_problem
                      : Icons.campaign_outlined,
                  color: publication.stale
                      ? theme.colorScheme.error
                      : Colors.green.shade700,
                  text: [
                    'ประกาศเกรดแล้ว ${formatThaiDateTime(publication.publishedAt)}',
                    if (publication.stale)
                      'มีการเปลี่ยนแปลงหลังประกาศ '
                          'ประกาศใหม่เพื่อให้นักเรียนเห็นค่าล่าสุด',
                  ].join(' · '),
                ),
              if (uncategorised > 0)
                _Note(
                  key: const ValueKey('gradebook_uncategorised'),
                  icon: Icons.label_off_outlined,
                  color: theme.colorScheme.outline,
                  text:
                      'มี $uncategorised รายการที่ยังไม่ระบุหมวด (ไม่นับ) '
                      'เลือกหมวดได้ที่ฟอร์มของงานหรือข้อสอบ',
                ),
              const SizedBox(height: 4),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  FilledButton.tonalIcon(
                    key: const ValueKey('gradebook_add_item'),
                    onPressed: _busy ? null : () => _addItem(g, classrooms),
                    icon: const Icon(Icons.add),
                    label: const Text('เพิ่มรายการคะแนน'),
                  ),
                  FilledButton.icon(
                    key: const ValueKey('gradebook_publish'),
                    onPressed: _busy || !g.complete || g.rows.isEmpty
                        ? null
                        : () => _publish(g),
                    icon: const Icon(Icons.campaign_outlined),
                    label: Text(
                      publication == null ? 'ประกาศเกรด' : 'ประกาศใหม่',
                    ),
                  ),
                  OutlinedButton.icon(
                    key: const ValueKey('gradebook_export'),
                    onPressed: _busy ? null : () => _export(g),
                    icon: const Icon(Icons.ios_share),
                    label: const Text('ส่งออก CSV'),
                  ),
                  if (publication != null)
                    TextButton.icon(
                      key: const ValueKey('gradebook_withdraw'),
                      onPressed: _busy ? null : () => _withdraw(g),
                      icon: const Icon(Icons.undo),
                      label: const Text('ถอนประกาศ'),
                    ),
                ],
              ),
            ],
          ),
        ),
        Expanded(
          child: g.rows.isEmpty
              ? const EmptyView(
                  icon: Icons.person_off_outlined,
                  title: 'ห้องนี้ยังไม่มีนักเรียน',
                  message: 'เพิ่มนักเรียนที่หน้าห้องเรียนก่อน',
                )
              : RefreshIndicator(
                  onRefresh: () => ref.refresh(
                    gradebookGridProvider((
                      courseId: _courseId,
                      classroomId: g.classroomId,
                    )).future,
                  ),
                  child: GradebookTable(
                    grid: g,
                    horizontalController: _horizontal,
                    highlightKey: widget.focusColumn,
                    onCellTap: _cellTap,
                    onColumnTap: (c) => _columnTap(g, c, classrooms),
                    onRowTap: (r) => _rowTap(g, r),
                  ),
                ),
        ),
      ],
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({
    super.key,
    required this.icon,
    required this.color,
    required this.text,
  });

  final IconData icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 18, color: color),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            text,
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(color: color),
          ),
        ),
      ],
    ),
  );
}

/// Not set up yet (§23.9): one of the two templates, or categories from
/// scratch; then the settings.
class _SetupView extends ConsumerStatefulWidget {
  const _SetupView({required this.courseId});

  final int courseId;

  @override
  ConsumerState<_SetupView> createState() => _SetupViewState();
}

class _SetupViewState extends ConsumerState<_SetupView> {
  bool _busy = false;

  Future<void> _apply(GradebookTemplate t) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(gradebookRepositoryProvider)
          .applyTemplate(widget.courseId, t.key);
      invalidateGradebook(ref);
      if (!mounted) return;
      showMessage(context, 'ใช้ "${t.name}" แล้ว ปรับหมวดและน้ำหนักได้เลย');
      context.push(AppRoutes.gradebookSettings(widget.courseId));
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final templates = ref.watch(gradebookTemplatesProvider);
    return AsyncView(
      value: templates,
      onRetry: () => ref.invalidate(gradebookTemplatesProvider),
      data: (list) => ContentColumn(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text('เริ่มสมุดคะแนน', style: theme.textTheme.titleLarge),
            const SizedBox(height: 4),
            const Text(
              'เลือกรูปแบบหมวดคะแนนตั้งต้น แล้วปรับชื่อหมวด น้ำหนัก '
              'และเกณฑ์เกรดได้อิสระ ใช้ร่วมกันทุกห้องของรายวิชานี้',
            ),
            const SizedBox(height: 12),
            for (final t in list)
              Card(
                child: ListTile(
                  key: ValueKey('template_${t.key}'),
                  enabled: !_busy,
                  title: Text(t.name),
                  subtitle: Text(
                    [
                      for (final c in t.categories)
                        '${c.name} ${formatGbNumber(c.weight)}'
                            '${c.isHomeworkDefault ? ' (การบ้าน)' : ''}',
                    ].join(' · '),
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _apply(t),
                ),
              ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              key: const ValueKey('template_custom'),
              onPressed: _busy
                  ? null
                  : () => context.push(
                      AppRoutes.gradebookSettings(widget.courseId),
                    ),
              icon: const Icon(Icons.tune),
              label: const Text('ตั้งหมวดเอง'),
            ),
          ],
        ),
      ),
    );
  }
}
