import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../courses/indicator_widgets.dart';
import '../my_indicators/my_indicators.dart';
import 'assignments_repository.dart';
import 'question.dart';

/// Search dialog over `GET /skills?subject=&grade=&q=&level=` limited to
/// indicators and sub-indicators (the only levels questions, courses, units
/// and plans take, DESIGN §20.2); returns the chosen skills or null when
/// cancelled. "เพิ่มตัวชี้วัดเอง" adds a missing one and ticks it.
/// A teacher who keeps "ตัวชี้วัดของฉัน" (DESIGN §34) sees only those and
/// their own indicators, with a switch to the whole curriculum.
Future<List<Skill>?> showSkillsPicker(
  BuildContext context, {
  required int? subjectId,
  int? grade,
  List<Skill> selected = const [],
}) {
  return showDialog<List<Skill>>(
    context: context,
    builder: (_) => _SkillsPickerDialog(
      subjectId: subjectId,
      grade: grade,
      initial: selected,
    ),
  );
}

class _SkillsPickerDialog extends ConsumerStatefulWidget {
  const _SkillsPickerDialog({
    required this.subjectId,
    required this.grade,
    required this.initial,
  });

  final int? subjectId;
  final int? grade;
  final List<Skill> initial;

  @override
  ConsumerState<_SkillsPickerDialog> createState() =>
      _SkillsPickerDialogState();
}

class _SkillsPickerDialogState extends ConsumerState<_SkillsPickerDialog> {
  final _query = TextEditingController();
  late final Map<int, Skill> _selected = {
    for (final s in widget.initial) s.id: s,
  };
  List<Skill> _results = const [];
  // The search that produced _results: an empty one shows the whole
  // subject and grade, so "เพิ่มทั้งหมด" means exactly that.
  String _shownQuery = '';
  bool _loading = false;
  String? _error;
  Timer? _debounce;

  /// "แสดงทั้งหลักสูตร": ignore the teacher's set for this dialog.
  bool _showAll = false;

  bool get _mine =>
      hasMyIndicators(ref.read(myIndicatorsProvider)) && !_showAll;

  @override
  void initState() {
    super.initState();
    // The set may arrive after the first search: search again when it does.
    ref.listenManual(myIndicatorsProvider, (previous, next) {
      if (hasMyIndicators(previous ?? const AsyncLoading()) !=
          hasMyIndicators(next)) {
        _search();
      }
    });
    _search();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _query.dispose();
    super.dispose();
  }

  void _onChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _search);
  }

  Future<void> _search() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final q = _query.text.trim();
    try {
      final results = await ref
          .read(assignmentsRepositoryProvider)
          .searchSkills(
            subjectId: widget.subjectId,
            grade: widget.grade,
            q: q,
            level: kAssessableLevels,
            mine: _mine,
          );
      if (mounted) {
        setState(() {
          _results = results;
          _shownQuery = q;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// Ticks every indicator the current search shows (DESIGN §29.4: the
  /// whole subject and grade when the search box is empty).
  int get _unselectedShown =>
      _results.where((s) => !_selected.containsKey(s.id)).length;

  void _addAll() {
    setState(() {
      for (final s in _results) {
        _selected[s.id] = s;
      }
    });
  }

  Future<void> _addIndicator() async {
    final added = await showAddIndicatorDialog(
      context,
      subjectId: widget.subjectId,
      grade: widget.grade,
    );
    if (added == null || !mounted) return;
    setState(() {
      _selected[added.id] = added;
      _results = [added, ..._results.where((s) => s.id != added.id)];
    });
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เลือกตัวชี้วัด / ทักษะ'),
      content: SizedBox(
        width: 520,
        height: 440,
        child: Column(
          children: [
            TextField(
              controller: _query,
              autofocus: true,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'ค้นด้วยรหัสหรือชื่อ เช่น ค 1.1',
              ),
              onChanged: _onChanged,
            ),
            if (hasMyIndicators(ref.watch(myIndicatorsProvider)))
              SwitchListTile(
                key: const ValueKey('skills_picker_show_all'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: const Text('แสดงทั้งหลักสูตร'),
                subtitle: Text(
                  _showAll
                      ? 'แสดงทุกตัวชี้วัดของวิชาและชั้นนี้'
                      : 'ตอนนี้แสดงเฉพาะตัวชี้วัดของฉันและที่คุณเพิ่มเอง',
                ),
                value: _showAll,
                onChanged: (v) {
                  setState(() => _showAll = v);
                  _search();
                },
              ),
            // Below the search so the dialog's button bar keeps its width.
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                key: const ValueKey('skills_picker_add_all'),
                onPressed: _unselectedShown == 0 ? null : _addAll,
                icon: const Icon(Icons.done_all, size: 18),
                label: Text(
                  _shownQuery.isEmpty
                      ? 'เพิ่มทั้งหมด ($_unselectedShown)'
                      : 'เพิ่มที่แสดงทั้งหมด ($_unselectedShown)',
                ),
              ),
            ),
            if (_selected.isNotEmpty)
              Align(
                alignment: Alignment.centerLeft,
                child: Wrap(
                  spacing: 6,
                  children: [
                    for (final s in _selected.values)
                      InputChip(
                        label: Text(s.code),
                        onDeleted: () => setState(() => _selected.remove(s.id)),
                      ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            Expanded(
              child: _loading && _results.isEmpty
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                  ? Center(child: Text(_error!))
                  : _results.isEmpty
                  ? const Center(child: Text('ไม่พบทักษะ'))
                  : ListView.builder(
                      itemCount: _results.length,
                      itemBuilder: (context, i) {
                        final s = _results[i];
                        final checked = _selected.containsKey(s.id);
                        return CheckboxListTile(
                          value: checked,
                          dense: true,
                          title: Row(
                            children: [
                              Flexible(child: Text(s.code)),
                              if (s.sourceLabel case final label?) ...[
                                const SizedBox(width: 6),
                                TeacherAddedLabel(label: label),
                              ],
                            ],
                          ),
                          subtitle: Text(
                            s.name,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          onChanged: (v) => setState(() {
                            if (v == true) {
                              _selected[s.id] = s;
                            } else {
                              _selected.remove(s.id);
                            }
                          }),
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton.icon(
          key: const ValueKey('skills_picker_add_indicator'),
          onPressed: _addIndicator,
          icon: const Icon(Icons.add),
          label: const Text('เพิ่มตัวชี้วัดเอง'),
        ),
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(_selected.values.toList()),
          child: Text('ใช้ ${_selected.length} รายการ'),
        ),
      ],
    );
  }
}
