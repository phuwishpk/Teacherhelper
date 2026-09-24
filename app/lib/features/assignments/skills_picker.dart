import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import 'assignments_repository.dart';
import 'question.dart';

/// Search dialog over `GET /skills?subject=&grade=&q=`; returns the chosen
/// skills or null when cancelled.
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
  bool _loading = false;
  String? _error;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
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
    try {
      final results = await ref
          .read(assignmentsRepositoryProvider)
          .searchSkills(
            subjectId: widget.subjectId,
            grade: widget.grade,
            q: _query.text.trim(),
          );
      if (mounted) setState(() => _results = results);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
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
            const SizedBox(height: 8),
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
                          title: Text(s.code),
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
