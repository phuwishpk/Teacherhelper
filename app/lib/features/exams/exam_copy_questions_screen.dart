import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/courses_providers.dart';
import 'exam_import_models.dart';
import 'exam_import_repository.dart';
import 'exam_models.dart';
import 'exam_providers.dart';

/// "คัดลอกจากข้อสอบเดิม" (DESIGN §22.4 item 3): the questions of the
/// teacher's own earlier exams (never another teacher's), filtered by
/// course, exam or a search, picked one by one or a whole section at a
/// time, then copied into this exam with their options, pictures, key,
/// "ห้ามสลับตัวเลือก" and approval. Without a target section the copy
/// creates sections like the source ones.
class ExamCopyQuestionsScreen extends ConsumerStatefulWidget {
  const ExamCopyQuestionsScreen({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<ExamCopyQuestionsScreen> createState() =>
      _ExamCopyQuestionsScreenState();
}

class _ExamCopyQuestionsScreenState
    extends ConsumerState<ExamCopyQuestionsScreen> {
  final _search = TextEditingController();
  int? _courseId;
  int? _filterExamId;

  final _items = <LibraryQuestion>[];
  String? _cursor;
  bool _loading = false;
  String? _loadError;
  int _serial = 0;

  /// Every exam seen so far, for the exam filter.
  final _exams = <int, LibraryExam>{};

  final _selected = <int>{};

  /// Target section; null = new sections like the source.
  int? _targetSectionId;
  bool _copying = false;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({required bool reset}) async {
    final serial = ++_serial;
    setState(() {
      _loading = true;
      _loadError = null;
      if (reset) {
        _items.clear();
        _cursor = null;
        // Only loaded questions are copied: a new filter starts over.
        _selected.clear();
      }
    });
    try {
      final page = await ref
          .read(examImportRepositoryProvider)
          .library(
            courseId: _courseId,
            examId: _filterExamId,
            query: _search.text,
            excludeExam: widget.examId,
            cursor: reset ? null : _cursor,
          );
      if (!mounted || serial != _serial) return;
      setState(() {
        _items.addAll(page.items);
        _cursor = page.nextCursor;
        for (final q in page.items) {
          _exams[q.exam.id] = q.exam;
        }
        _loading = false;
      });
    } catch (e) {
      if (!mounted || serial != _serial) return;
      setState(() {
        _loadError = apiErrorMessage(e);
        _loading = false;
      });
    }
  }

  Future<void> _copy(ExamDetail d) async {
    final ids = [
      for (final q in _items)
        if (_selected.contains(q.id)) q.id,
    ];
    if (ids.isEmpty) return;
    setState(() => _copying = true);
    try {
      final result = await ref
          .read(examDetailProvider(widget.examId).notifier)
          .copyQuestions(ids, sectionId: _targetSectionId);
      if (!mounted) return;
      setState(() => _copying = false);
      final byId = {for (final q in _items) q.id: q};
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text('คัดลอกแล้ว ${result.created} ข้อ'),
          content: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (result.skipped.isEmpty)
                  const Text('ข้อที่คัดลอกคงการอนุมัติและเฉลยของต้นฉบับ')
                else ...[
                  Text('ข้าม ${result.skipped.length} ข้อ:'),
                  for (final s in result.skipped)
                    Text(
                      '• ${byId[s.questionId] == null ? 'ข้อ' : '${byId[s.questionId]!.exam.title} ข้อ ${byId[s.questionId]!.question.position}'}: ${s.label}',
                    ),
                ],
              ],
            ),
          ),
          actions: [
            TextButton(
              key: const ValueKey('copy_result_ok'),
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('ตกลง'),
            ),
          ],
        ),
      );
      if (mounted) Navigator.of(context).maybePop();
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _copying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(examDetailProvider(widget.examId));
    final d = detail.value;
    return Scaffold(
      appBar: AppBar(title: const Text('คัดลอกจากข้อสอบเดิม')),
      bottomNavigationBar: d == null ? null : _bottom(context, d),
      body: ContentColumn(
        padding: EdgeInsets.zero,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          children: [
            ..._filters(context),
            const SizedBox(height: 8),
            if (_loadError != null && _items.isEmpty)
              ErrorView(message: _loadError!, onRetry: () => _load(reset: true))
            else if (!_loading && _items.isEmpty)
              const EmptyView(
                icon: Icons.library_books_outlined,
                title: 'ไม่พบข้อจากข้อสอบเดิม',
                message:
                    'แสดงเฉพาะข้อจากข้อสอบที่ครูสร้างเอง '
                    'ลองเปลี่ยนรายวิชา ข้อสอบ หรือคำค้น',
              ),
            ..._groups(context),
            if (_loading)
              const Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_cursor != null)
              TextButton(
                key: const ValueKey('copy_load_more'),
                onPressed: () => _load(reset: false),
                child: const Text('โหลดเพิ่ม'),
              ),
          ],
        ),
      ),
    );
  }

  List<Widget> _filters(BuildContext context) {
    final courses = ref.watch(coursesProvider).value ?? const [];
    final exams = _exams.values.toList()..sort((a, b) => b.id.compareTo(a.id));
    return [
      TextField(
        key: const ValueKey('copy_search'),
        controller: _search,
        textInputAction: TextInputAction.search,
        decoration: InputDecoration(
          labelText: 'ค้นหาโจทย์หรือตัวเลือก',
          prefixIcon: const Icon(Icons.search),
          suffixIcon: IconButton(
            tooltip: 'ค้นหา',
            icon: const Icon(Icons.arrow_forward),
            onPressed: () => _load(reset: true),
          ),
        ),
        onSubmitted: (_) => _load(reset: true),
      ),
      const SizedBox(height: 8),
      Row(
        children: [
          if (courses.isNotEmpty) ...[
            Expanded(
              child: DropdownButtonFormField<int?>(
                key: const ValueKey('copy_course'),
                initialValue: _courseId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'รายวิชา'),
                items: [
                  const DropdownMenuItem(
                    value: null,
                    child: Text('ทุกรายวิชา'),
                  ),
                  for (final c in courses)
                    DropdownMenuItem(
                      value: c.id,
                      child: Text(c.title, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) {
                  _courseId = v;
                  _load(reset: true);
                },
              ),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            child: DropdownButtonFormField<int?>(
              key: const ValueKey('copy_exam'),
              initialValue: _filterExamId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'ข้อสอบ'),
              items: [
                const DropdownMenuItem(value: null, child: Text('ทุกฉบับ')),
                for (final e in exams)
                  DropdownMenuItem(
                    value: e.id,
                    child: Text(e.title, overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) {
                _filterExamId = v;
                _load(reset: true);
              },
            ),
          ),
        ],
      ),
    ];
  }

  /// The loaded questions grouped by exam and section, in server order.
  List<Widget> _groups(BuildContext context) {
    final theme = Theme.of(context);
    final groups = <(int, int), List<LibraryQuestion>>{};
    for (final q in _items) {
      groups.putIfAbsent((q.exam.id, q.section.id), () => []).add(q);
    }
    int? lastExam;
    final out = <Widget>[];
    for (final entry in groups.entries) {
      final first = entry.value.first;
      if (first.exam.id != lastExam) {
        lastExam = first.exam.id;
        out.add(
          Padding(
            padding: const EdgeInsets.only(top: 12, bottom: 4),
            child: Text(first.exam.title, style: theme.textTheme.titleMedium),
          ),
        );
      }
      final ids = [for (final q in entry.value) q.id];
      final picked = ids.where(_selected.contains).length;
      final section = first.section;
      final title = (section.title ?? '').trim();
      out.add(
        Card(
          key: ValueKey('copy_group_${section.id}'),
          child: Column(
            children: [
              CheckboxListTile(
                key: ValueKey('copy_section_${section.id}'),
                tristate: true,
                value: picked == 0
                    ? false
                    : picked == ids.length
                    ? true
                    : null,
                onChanged: (_) => setState(() {
                  if (picked == ids.length) {
                    _selected.removeAll(ids);
                  } else {
                    _selected.addAll(ids);
                  }
                }),
                title: Text(
                  [
                    if (title.isNotEmpty) title,
                    section.typeSummary,
                  ].join(' · '),
                ),
                subtitle: const Text('เลือกทั้งตอน (ที่โหลดมาแล้ว)'),
              ),
              for (final q in entry.value) _questionTile(context, q),
            ],
          ),
        ),
      );
    }
    return out;
  }

  Widget _questionTile(BuildContext context, LibraryQuestion item) {
    final q = item.question;
    final scheme = Theme.of(context).colorScheme;
    final prompt = q.promptText.trim();
    final key = q.key;
    return CheckboxListTile(
      key: ValueKey('copy_q_${q.id}'),
      dense: true,
      value: _selected.contains(q.id),
      onChanged: (v) => setState(() {
        if (v ?? false) {
          _selected.add(q.id);
        } else {
          _selected.remove(q.id);
        }
      }),
      title: Text(
        'ข้อ ${q.position}  ${prompt.isEmpty ? (q.hasPromptImage ? '(โจทย์เป็นภาพ)' : '(ไม่มีโจทย์)') : prompt}',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Text(
        [
          key == null ? 'ไม่มีเฉลย' : 'เฉลย ${key.describe(q.type)}',
          if (q.hasPromptImage || q.options.any((o) => o.hasImage)) 'มีภาพ',
          if (!q.approved) 'ยังไม่อนุมัติ',
        ].join(' · '),
        style: key == null ? TextStyle(color: scheme.error) : null,
      ),
    );
  }

  Widget _bottom(BuildContext context, ExamDetail d) {
    final locked = d.structureLocked;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (locked)
              Text(
                'ข้อสอบนี้พิมพ์แล้ว โครงสร้างถูกล็อก ปลดล็อกก่อนเพิ่มข้อ',
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              )
            else
              DropdownButtonFormField<int?>(
                key: const ValueKey('copy_target'),
                initialValue: _targetSectionId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'คัดลอกไปที่'),
                items: [
                  const DropdownMenuItem(
                    value: null,
                    child: Text('ตอนใหม่ ตามตอนต้นทาง'),
                  ),
                  for (final s in d.sections)
                    DropdownMenuItem(
                      value: s.id,
                      child: Text(
                        '${s.heading} · ${s.typeSummary}',
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                ],
                onChanged: (v) => setState(() => _targetSectionId = v),
              ),
            const SizedBox(height: 8),
            FilledButton.icon(
              key: const ValueKey('copy_submit'),
              onPressed: locked || _copying || _selected.isEmpty
                  ? null
                  : () => _copy(d),
              icon: _copying
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.copy_all),
              label: Text('คัดลอก ${_selected.length} ข้อ'),
            ),
          ],
        ),
      ),
    );
  }
}
