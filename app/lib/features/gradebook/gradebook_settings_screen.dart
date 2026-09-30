import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'gradebook_models.dart';
import 'gradebook_providers.dart';
import 'gradebook_repository.dart';

/// `/courses/:id/gradebook/settings` (DESIGN §23.2, §23.9): the categories
/// of the course (name, weight, drop-lowest-k, the homework default),
/// dragged into order, with a "รวม x%" bar that stays a warning until it is
/// exactly 100, and the 7 grade cutoffs. Shared by every classroom of the
/// course.
class GradebookSettingsScreen extends ConsumerWidget {
  const GradebookSettingsScreen({super.key, required this.courseId});

  final int courseId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settings = ref.watch(gradebookSettingsProvider(courseId));
    return settings.when(
      skipLoadingOnRefresh: true,
      data: (s) => _SettingsForm(courseId: courseId, initial: s),
      loading: () => Scaffold(
        appBar: AppBar(title: const Text('ตั้งค่าสมุดคะแนน')),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: const Text('ตั้งค่าสมุดคะแนน')),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(gradebookSettingsProvider(courseId)),
        ),
      ),
    );
  }
}

/// One editable category row with its text controllers.
class _CategoryRow {
  _CategoryRow(CategoryDraft c)
    : id = c.id,
      itemCount = c.itemCount,
      dropLowest = c.dropLowest,
      isHomeworkDefault = c.isHomeworkDefault,
      name = TextEditingController(text: c.name),
      weight = TextEditingController(
        text: c.weight == 0 ? '' : formatGbNumber(c.weight),
      );

  final int? id;
  final int itemCount;
  final TextEditingController name;
  final TextEditingController weight;
  int dropLowest;
  bool isHomeworkDefault;

  /// A stable key for drag-and-drop, also for new rows.
  final Key key = UniqueKey();

  double? get weightValue => parseScore(weight.text);

  CategoryDraft toDraft() => CategoryDraft(
    id: id,
    name: name.text.trim(),
    weight: weightValue ?? 0,
    dropLowest: dropLowest,
    isHomeworkDefault: isHomeworkDefault,
    itemCount: itemCount,
  );

  void dispose() {
    name.dispose();
    weight.dispose();
  }
}

class _SettingsForm extends ConsumerStatefulWidget {
  const _SettingsForm({required this.courseId, required this.initial});

  final int courseId;
  final GradebookSettings initial;

  @override
  ConsumerState<_SettingsForm> createState() => _SettingsFormState();
}

class _SettingsFormState extends ConsumerState<_SettingsForm> {
  late final List<_CategoryRow> _rows = [
    for (final c in widget.initial.categories) _CategoryRow(c),
  ];
  late final List<TextEditingController> _cutoffs = [
    for (final c in widget.initial.cutoffs)
      TextEditingController(text: c.toString()),
  ];
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final r in _rows) {
      r.dispose();
    }
    for (final c in _cutoffs) {
      c.dispose();
    }
    super.dispose();
  }

  int get _cents => weightCents([for (final r in _rows) r.weightValue ?? 0]);

  List<int?> get _cutoffValues => [
    for (final c in _cutoffs) int.tryParse(c.text.trim()),
  ];

  String? get _rowsProblem {
    if (_rows.isEmpty) return 'เพิ่มหมวดอย่างน้อยหนึ่งหมวด';
    final names = <String>{};
    for (final r in _rows) {
      final name = r.name.text.trim();
      if (name.isEmpty) return 'ตั้งชื่อหมวดให้ครบ';
      if (!names.add(name.toLowerCase())) return 'ชื่อหมวดซ้ำกัน';
      final w = r.weightValue;
      if (w == null || w <= 0) return 'น้ำหนักของหมวดต้องมากกว่า 0';
    }
    return null;
  }

  bool get _canSave =>
      !_busy &&
      _cents == 10000 &&
      _rowsProblem == null &&
      cutoffsProblem(_cutoffValues) == null;

  void _add() => setState(
    () => _rows.add(_CategoryRow(const CategoryDraft(name: '', weight: 0))),
  );

  void _remove(int index) => setState(() => _rows.removeAt(index).dispose());

  void _setDefault(int index, bool on) => setState(() {
    for (var i = 0; i < _rows.length; i++) {
      _rows[i].isHomeworkDefault = on && i == index;
    }
  });

  void _useDefaultCutoffs() => setState(() {
    final d = widget.initial.defaultCutoffs;
    for (var i = 0; i < _cutoffs.length && i < d.length; i++) {
      _cutoffs[i].text = d[i].toString();
    }
  });

  Future<void> _save() async {
    final drafts = [for (final r in _rows) r.toDraft()];
    final kept = {for (final d in drafts) ?d.id};
    final removedItems = widget.initial.categories
        .where((c) => c.id != null && !kept.contains(c.id))
        .fold<int>(0, (n, c) => n + c.itemCount);
    if (removedItems > 0) {
      final ok = await confirm(
        context,
        title: 'ลบหมวดที่มีรายการ?',
        message:
            'หมวดที่ลบมีงานและรายการคะแนนรวม $removedItems รายการ '
            'รายการเหล่านี้จะเป็น "ยังไม่ระบุหมวด" และไม่นับในคะแนน '
            'จนกว่าจะเลือกหมวดใหม่',
        confirmLabel: 'ลบและบันทึก',
        destructive: true,
      );
      if (!ok || !mounted) return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final repo = ref.read(gradebookRepositoryProvider);
    try {
      final initial = widget.initial.categories;
      final categoriesChanged =
          drafts.length != initial.length ||
          [for (var i = 0; i < drafts.length; i++) drafts[i].sameAs(initial[i])]
              .contains(false);
      if (categoriesChanged) {
        await repo.saveCategories(widget.courseId, drafts);
      }
      final cutoffs = [for (final c in _cutoffValues) c!];
      if (!_sameList(cutoffs, widget.initial.cutoffs)) {
        await repo.saveCutoffs(
          widget.courseId,
          _sameList(cutoffs, widget.initial.defaultCutoffs) ? null : cutoffs,
        );
      }
      invalidateGradebook(ref);
      if (!mounted) return;
      showMessage(context, 'บันทึกการตั้งค่าสมุดคะแนนแล้ว');
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  static bool _sameList(List<int> a, List<int> b) =>
      a.length == b.length &&
      [for (var i = 0; i < a.length; i++) a[i] == b[i]].every((x) => x);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final cents = _cents;
    final ok = cents == 10000;
    final totalColor = ok ? Colors.green.shade700 : theme.colorScheme.error;
    final cutoffProblem = cutoffsProblem(_cutoffValues);
    final rowsProblem = _rowsProblem;

    return Scaffold(
      appBar: AppBar(title: const Text('ตั้งค่าสมุดคะแนน')),
      body: ContentColumn(
        padding: EdgeInsets.zero,
        child: ReorderableListView.builder(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          header: Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              'หมวดคะแนนและเกณฑ์เกรดใช้ร่วมกันทุกห้องของรายวิชานี้ '
              'ลากเพื่อเรียงลำดับ น้ำหนักทุกหมวดรวมกันต้องเท่ากับ 100',
              style: theme.textTheme.bodySmall,
            ),
          ),
          itemCount: _rows.length,
          onReorderItem: (from, to) =>
              setState(() => _rows.insert(to, _rows.removeAt(from))),
          itemBuilder: (context, i) => _CategoryCard(
            key: _rows[i].key,
            index: i,
            row: _rows[i],
            onChanged: () => setState(() {}),
            onDefault: (on) => _setDefault(i, on),
            onRemove: () => _remove(i),
          ),
          footer: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton.icon(
                  key: const ValueKey('category_add'),
                  onPressed: _rows.length >= kMaxCategories ? null : _add,
                  icon: const Icon(Icons.add),
                  label: const Text('เพิ่มหมวด'),
                ),
              ),
              Container(
                key: const ValueKey('weights_total'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: totalColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: totalColor),
                ),
                child: Row(
                  children: [
                    Icon(
                      ok ? Icons.check_circle : Icons.warning_amber,
                      color: totalColor,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'รวม ${formatGbNumber(cents / 100)}%'
                        '${ok ? '' : ' (ต้องเท่ากับ 100%)'}',
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: totalColor,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              if (rowsProblem != null) ...[
                const SizedBox(height: 4),
                Text(
                  rowsProblem,
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ],
              const SizedBox(height: 24),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'เกณฑ์เกรด (คะแนนรวมขั้นต่ำ)',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  TextButton(
                    key: const ValueKey('cutoffs_default'),
                    onPressed: _useDefaultCutoffs,
                    child: const Text('ใช้ค่าตั้งต้น'),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (var i = 0; i < _cutoffs.length; i++)
                    SizedBox(
                      width: 96,
                      child: TextField(
                        key: ValueKey('cutoff_$i'),
                        controller: _cutoffs[i],
                        keyboardType: TextInputType.number,
                        inputFormatters: [
                          FilteringTextInputFormatter.digitsOnly,
                        ],
                        decoration: InputDecoration(
                          labelText: 'เกรด ${kCutoffGrades[i]}',
                          prefixText: '≥ ',
                        ),
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                cutoffProblem ??
                    'ต่ำกว่า ${_cutoffs.last.text} ได้เกรด 0 '
                        'คะแนนรวมปัดครึ่งขึ้นเป็นจำนวนเต็มก่อนตัดเกรด',
                style: cutoffProblem == null
                    ? theme.textTheme.bodySmall
                    : TextStyle(color: theme.colorScheme.error),
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
              ],
              const SizedBox(height: 24),
              FilledButton(
                key: const ValueKey('gradebook_settings_save'),
                onPressed: _canSave ? _save : null,
                child: const Text('บันทึก'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CategoryCard extends StatelessWidget {
  const _CategoryCard({
    super.key,
    required this.index,
    required this.row,
    required this.onChanged,
    required this.onDefault,
    required this.onRemove,
  });

  final int index;
  final _CategoryRow row;
  final VoidCallback onChanged;
  final ValueChanged<bool> onDefault;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  flex: 3,
                  child: TextField(
                    key: ValueKey('category_name_$index'),
                    controller: row.name,
                    decoration: const InputDecoration(labelText: 'ชื่อหมวด'),
                    onChanged: (_) => onChanged(),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 2,
                  child: TextField(
                    key: ValueKey('category_weight_$index'),
                    controller: row.weight,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'น้ำหนัก',
                      suffixText: '%',
                    ),
                    onChanged: (_) => onChanged(),
                  ),
                ),
                IconButton(
                  key: ValueKey('category_remove_$index'),
                  tooltip: 'ลบหมวด',
                  icon: const Icon(Icons.delete_outline),
                  onPressed: onRemove,
                ),
              ],
            ),
            const SizedBox(height: 4),
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 12,
              children: [
                DropdownButton<int>(
                  key: ValueKey('category_drop_$index'),
                  value: row.dropLowest,
                  items: [
                    for (var k = 0; k <= kMaxDropLowest; k++)
                      DropdownMenuItem(
                        value: k,
                        child: Text(
                          k == 0 ? 'ไม่ตัดคะแนนต่ำสุด' : 'ตัดต่ำสุด $k รายการ',
                        ),
                      ),
                  ],
                  onChanged: (k) {
                    if (k == null) return;
                    row.dropLowest = k;
                    onChanged();
                  },
                ),
                FilterChip(
                  key: ValueKey('category_default_$index'),
                  label: const Text('หมวดตั้งต้นของการบ้าน'),
                  selected: row.isHomeworkDefault,
                  onSelected: onDefault,
                ),
              ],
            ),
            if (row.itemCount > 0)
              Text(
                'มี ${row.itemCount} รายการในหมวดนี้',
                style: theme.textTheme.bodySmall,
              ),
          ],
        ),
      ),
    );
  }
}
