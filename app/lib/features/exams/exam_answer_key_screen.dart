import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'exam_key_sheet_scan_screen.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exams_repository.dart';

/// "ตารางเฉลย" (DESIGN §22.3): one row per question in the original
/// order; tap the accepted options (several allowed, true_false one) or
/// type the accepted numbers separated by commas. "บันทึก" sends the
/// changed rows with `PUT /exams/{id}/answer-key`; "อนุมัติเฉลย" follows
/// once the key is complete (exams graded by the app only).
/// "สแกนกระดาษเฉลย" fills the grid from the teacher's key sheet; the rows
/// that differ from the saved key or were read unclearly are marked until
/// the teacher saves.
class ExamAnswerKeyScreen extends ConsumerWidget {
  const ExamAnswerKeyScreen({super.key, required this.examId});

  final int examId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(examDetailProvider(examId));
    return detail.when(
      skipLoadingOnRefresh: true,
      loading: () => Scaffold(
        appBar: AppBar(title: const Text('ตารางเฉลย')),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: const Text('ตารางเฉลย')),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(examDetailProvider(examId)),
        ),
      ),
      data: (d) => _KeyGrid(detail: d),
    );
  }
}

class _KeyGrid extends ConsumerStatefulWidget {
  const _KeyGrid({required this.detail});

  final ExamDetail detail;

  @override
  ConsumerState<_KeyGrid> createState() => _KeyGridState();
}

class _KeyGridState extends ConsumerState<_KeyGrid> {
  /// Selected original positions of mcq/true_false rows.
  final _options = <int, Set<int>>{};

  /// Typed values of numeric rows.
  final _values = <int, TextEditingController>{};

  /// Messages by question id: the server's 422 of the last save.
  var _serverErrors = <int, String>{};

  /// Notes of the last key-sheet scan by question id.
  var _scanNotes = <int, ({String text, bool doubtful})>{};
  bool _busy = false;

  ExamDetail get _d => widget.detail;

  @override
  void initState() {
    super.initState();
    for (final q in _d.questions) {
      _init(q);
    }
  }

  void _init(ExamQuestion q) {
    if (q.type == ExamSectionType.numeric) {
      _values[q.id] = TextEditingController(
        text: q.key?.values.join(', ') ?? '',
      );
    } else {
      _options[q.id] = {...?q.key?.options};
    }
  }

  @override
  void didUpdateWidget(covariant _KeyGrid old) {
    super.didUpdateWidget(old);
    // Questions added elsewhere (another screen) get a row.
    for (final q in _d.questions) {
      if (!_values.containsKey(q.id) && !_options.containsKey(q.id)) _init(q);
    }
  }

  @override
  void dispose() {
    for (final c in _values.values) {
      c.dispose();
    }
    super.dispose();
  }

  NumericSpec _spec(ExamQuestion q) =>
      _d.sectionOf(q.id)?.numeric ?? const NumericSpec(digits: 1);

  /// The key typed in a row, or the local problem of a numeric row.
  ({ExamKey? key, String? error}) _typed(ExamQuestion q) {
    if (q.type == ExamSectionType.numeric) {
      final parsed = NumericAnswer.parseList(
        _values[q.id]?.text ?? '',
        _spec(q),
      );
      if (parsed.error != null) return (key: null, error: parsed.error);
      return (
        key: parsed.values.isEmpty ? null : ExamKey(values: parsed.values),
        error: null,
      );
    }
    final selected = _options[q.id] ?? const <int>{};
    return (
      key: selected.isEmpty
          ? null
          : ExamKey(options: selected.toList()..sort()),
      error: null,
    );
  }

  bool _dirty(ExamQuestion q) {
    final t = _typed(q);
    return t.error != null || t.key != q.key;
  }

  List<ExamQuestion> get _changed => [
    for (final q in _d.questions)
      if (_dirty(q)) q,
  ];

  Future<void> _save() async {
    final changed = _changed;
    if (changed.isEmpty) return;
    final problems = {for (final q in changed) q.id: ?_typed(q).error};
    if (problems.isNotEmpty) {
      setState(() => _serverErrors = problems);
      showMessage(
        context,
        'แก้ค่าที่ไม่ถูกต้อง ${problems.length} ข้อก่อนบันทึก',
      );
      return;
    }
    setState(() {
      _busy = true;
      _serverErrors = {};
    });
    try {
      await ref.read(examDetailProvider(_d.exam.id).notifier).saveAnswerKey([
        for (final q in changed)
          (questionId: q.id, type: q.type, key: _typed(q).key),
      ]);
      if (mounted) {
        setState(() => _scanNotes = {});
        showMessage(context, 'บันทึกเฉลย ${changed.length} ข้อแล้ว');
      }
    } catch (e) {
      if (!mounted) return;
      // errors.answers.{i}.… name the entry i of the list sent.
      final byField = apiFieldErrors(e);
      final mapped = <int, String>{};
      for (final entry in byField.entries) {
        final m = RegExp(r'^answers\.(\d+)').firstMatch(entry.key);
        final i = m == null ? null : int.parse(m.group(1)!);
        if (i != null && i < changed.length) {
          mapped.putIfAbsent(changed[i].id, () => entry.value);
        }
      }
      setState(() => _serverErrors = mapped);
      showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Opens the key-sheet scanner and fills the grid with its proposal.
  Future<void> _scanKeySheet() async {
    final result = await context.push<KeySheetScanResult>(
      AppRoutes.examKeySheetScan(_d.exam.id),
    );
    if (result == null || !mounted) return;
    final notes = <int, ({String text, bool doubtful})>{};
    var filled = 0;
    setState(() {
      for (final item in result.items) {
        final q = _d.question(item.questionId);
        if (q == null) continue;
        final key = item.key;
        if (key != null) {
          filled++;
          if (q.type == ExamSectionType.numeric) {
            _values[q.id]?.text = key.values.join(', ');
          } else {
            _options[q.id] = {...key.options};
          }
          _serverErrors.remove(q.id);
        }
        if (item.doubtful) {
          notes[q.id] = (
            text: key == null
                ? 'อ่านจากกระดาษเฉลยไม่ได้ ตรวจแล้วกรอกเอง'
                : 'รอยฝนบนกระดาษเฉลยไม่ชัด ตรวจอีกครั้ง',
            doubtful: true,
          );
        } else if (item.differs) {
          notes[q.id] = (text: 'ต่างจากเฉลยที่บันทึกไว้', doubtful: false);
        }
      }
      _scanNotes = notes;
    });
    showMessage(
      context,
      'เติมจากกระดาษเฉลยชุด ${examVersionLabel(result.versionNo)} $filled ข้อ '
      '(อ่านไม่ชัด ${notes.values.where((n) => n.doubtful).length} ข้อ) '
      'ตรวจแล้วกดบันทึก',
    );
  }

  Future<void> _approve() async {
    setState(() => _busy = true);
    try {
      await ref.read(examDetailProvider(_d.exam.id).notifier).approveKey();
      if (mounted) showMessage(context, 'อนุมัติเฉลยแล้ว ข้อสอบพร้อมใช้');
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final questions = _d.questions;
    final changed = _changed.length;
    final keyed = questions.where((q) => _typed(q).key != null).length;
    final rows = <Object>[
      for (final s in _d.sections) ...[s, ...s.questions],
    ];
    final canApprove =
        !_d.isManual && !_d.keyApproved && changed == 0 && _d.keyComplete;
    return PopScope(
      canPop: changed == 0,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final leave = await confirm(
          context,
          title: 'ยังไม่ได้บันทึก',
          message: 'เฉลยที่แก้ $changed ข้อยังไม่ได้บันทึก ออกโดยไม่บันทึก?',
          confirmLabel: 'ออก',
          destructive: true,
        );
        if (leave && context.mounted) Navigator.of(context).pop();
      },
      child: Scaffold(
        appBar: AppBar(
          title: const Text('ตารางเฉลย'),
          actions: [
            if (!_d.isManual)
              IconButton(
                key: const ValueKey('key_grid_scan'),
                tooltip: 'สแกนกระดาษเฉลย',
                onPressed: _busy ? null : _scanKeySheet,
                icon: const Icon(Icons.document_scanner_outlined),
              ),
          ],
        ),
        body: ContentColumn(
          padding: EdgeInsets.zero,
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            itemCount: rows.length + 1,
            itemBuilder: (context, i) {
              if (i == 0) {
                return Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    'มีเฉลยแล้ว $keyed/${questions.length} ข้อ '
                    '· เฉลยอ้างลำดับตัวเลือกของชุด ก เฉลยชุดอื่นคำนวณให้เอง',
                    style: theme.textTheme.bodySmall,
                  ),
                );
              }
              final row = rows[i - 1];
              if (row is ExamSection) {
                return Padding(
                  padding: const EdgeInsets.only(top: 12, bottom: 4),
                  child: Text(
                    '${row.heading} · ${row.typeSummary}',
                    style: theme.textTheme.titleSmall,
                  ),
                );
              }
              final q = row as ExamQuestion;
              return _KeyRow(
                key: ValueKey('key_row_${q.id}'),
                question: q,
                section: _d.sectionOf(q.id)!,
                selected: _options[q.id],
                values: _values[q.id],
                dirty: _dirty(q),
                note: _scanNotes[q.id],
                error: _serverErrors[q.id] ?? _typed(q).error,
                enabled: !_busy,
                onToggle: (p) => setState(() {
                  final set = _options[q.id]!;
                  final on = !set.contains(p);
                  if (q.type == ExamSectionType.trueFalse) set.clear();
                  if (on) {
                    set.add(p);
                  } else {
                    set.remove(p);
                  }
                  _serverErrors.remove(q.id);
                }),
                onValuesChanged: () =>
                    setState(() => _serverErrors.remove(q.id)),
              );
            },
          ),
        ),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Row(
              children: [
                Expanded(
                  child: FilledButton(
                    key: const ValueKey('key_grid_save'),
                    onPressed: _busy || changed == 0 ? null : _save,
                    child: Text(
                      changed == 0 ? 'บันทึกแล้ว' : 'บันทึก ($changed ข้อ)',
                    ),
                  ),
                ),
                if (!_d.isManual) ...[
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton(
                      key: const ValueKey('key_grid_approve'),
                      onPressed: _busy || !canApprove ? null : _approve,
                      child: Text(
                        _d.keyApproved ? 'อนุมัติแล้ว' : 'อนุมัติเฉลย',
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _KeyRow extends StatelessWidget {
  const _KeyRow({
    super.key,
    required this.question,
    required this.section,
    required this.selected,
    required this.values,
    required this.dirty,
    this.note,
    required this.error,
    required this.enabled,
    required this.onToggle,
    required this.onValuesChanged,
  });

  final ExamQuestion question;
  final ExamSection section;
  final Set<int>? selected;
  final TextEditingController? values;
  final bool dirty;

  /// What the key-sheet scan said about this row.
  final ({String text, bool doubtful})? note;
  final String? error;
  final bool enabled;
  final ValueChanged<int> onToggle;
  final VoidCallback onValuesChanged;

  @override
  Widget build(BuildContext context) {
    final q = question;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final Widget input = q.type == ExamSectionType.numeric
        ? TextField(
            key: ValueKey('key_values_${q.id}'),
            controller: values,
            enabled: enabled,
            keyboardType: const TextInputType.numberWithOptions(
              signed: true,
              decimal: true,
            ),
            decoration: InputDecoration(
              isDense: true,
              hintText: 'เช่น 0.5 หรือ 0.33, 0.333',
              errorText: error,
              helperText: section.numeric?.summary,
            ),
            onChanged: (_) => onValuesChanged(),
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                spacing: 6,
                runSpacing: 4,
                children: [
                  for (var p = 1; p <= section.choiceCount; p++)
                    FilterChip(
                      key: ValueKey('key_chip_${q.id}_$p'),
                      showCheckmark: false,
                      visualDensity: VisualDensity.compact,
                      label: Text(examChoiceLabel(q.type, p)),
                      selected: selected?.contains(p) ?? false,
                      onSelected: enabled ? (_) => onToggle(p) : null,
                    ),
                ],
              ),
              if (error != null)
                Text(
                  error!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.error,
                  ),
                ),
            ],
          );
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 6),
      decoration: BoxDecoration(
        color: note == null
            ? null
            : (note!.doubtful ? scheme.errorContainer : scheme.primaryContainer)
                  .withValues(alpha: 0.35),
        border: Border(bottom: BorderSide(color: scheme.outlineVariant)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 56,
            child: Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(
                children: [
                  Text('${q.position}', style: theme.textTheme.titleSmall),
                  if (dirty)
                    Padding(
                      padding: const EdgeInsets.only(left: 4),
                      child: Icon(Icons.circle, size: 8, color: scheme.primary),
                    ),
                ],
              ),
            ),
          ),
          Expanded(
            child: note == null
                ? input
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      input,
                      Text(
                        note!.text,
                        key: ValueKey('key_note_${q.id}'),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: note!.doubtful ? scheme.error : scheme.primary,
                        ),
                      ),
                    ],
                  ),
          ),
          if (!q.approved)
            Tooltip(
              message: q.blank ? 'ข้อว่าง ยังไม่ได้กรอก' : 'ยังไม่อนุมัติ',
              child: Padding(
                padding: const EdgeInsets.only(top: 8, left: 4),
                child: Icon(
                  q.blank ? Icons.edit_note : Icons.pending_outlined,
                  size: 20,
                  color: q.blank ? scheme.outline : scheme.error,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
