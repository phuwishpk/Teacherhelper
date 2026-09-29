import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../assignments/assignments_repository.dart';
import '../assignments/question.dart';
import '../assignments/skills_picker.dart';
import 'courses_repository.dart';

/// Levels a question, course, unit or lesson plan can take (DESIGN §20.2).
const kAssessableLevels = 'indicator,sub_indicator';

/// Levels a teacher's indicator can be added under: a standard (then it is
/// an indicator) or an indicator (then it is a sub-indicator).
const kParentLevels = 'standard,indicator';

/// "ครูเพิ่มเอง" next to an indicator a teacher of the school added.
class TeacherAddedLabel extends StatelessWidget {
  const TeacherAddedLabel({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        color: scheme.tertiaryContainer,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        label,
        style: Theme.of(
          context,
        ).textTheme.labelSmall?.copyWith(color: scheme.onTertiaryContainer),
      ),
    );
  }
}

/// Indicators as chips (code, name on long press); [onRemove] makes them
/// deletable.
class IndicatorChips extends StatelessWidget {
  const IndicatorChips({
    super.key,
    required this.indicators,
    this.onRemove,
    this.pendingCodes = const [],
  });

  final List<Skill> indicators;
  final ValueChanged<Skill>? onRemove;

  /// Codes read from a document that match no indicator yet.
  final List<String> pendingCodes;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (indicators.isEmpty && pendingCodes.isEmpty) {
      return Text('ยังไม่มีตัวชี้วัด', style: theme.textTheme.bodySmall);
    }
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final s in indicators)
          Tooltip(
            message: s.name,
            child: InputChip(
              label: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(s.code),
                  if (s.sourceLabel case final label?) ...[
                    const SizedBox(width: 4),
                    TeacherAddedLabel(label: label),
                  ],
                ],
              ),
              onDeleted: onRemove == null ? null : () => onRemove!(s),
              deleteButtonTooltipMessage: 'เอาออก',
            ),
          ),
        for (final code in pendingCodes)
          Chip(
            avatar: Icon(
              Icons.help_outline,
              size: 16,
              color: theme.colorScheme.error,
            ),
            label: Text('$code (ยังไม่พบ)'),
          ),
      ],
    );
  }
}

/// A form field of indicators: chips plus "เลือกตัวชี้วัด", which opens the
/// search limited to indicators and sub-indicators of [subjectId].
class IndicatorsField extends StatelessWidget {
  const IndicatorsField({
    super.key,
    required this.indicators,
    required this.onChanged,
    required this.subjectId,
    this.grade,
    this.label = 'ตัวชี้วัด',
    this.pendingCodes = const [],
  });

  final List<Skill> indicators;
  final ValueChanged<List<Skill>> onChanged;
  final int? subjectId;
  final int? grade;
  final String label;
  final List<String> pendingCodes;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                '$label (${indicators.length})',
                style: theme.textTheme.labelLarge,
              ),
            ),
            TextButton.icon(
              onPressed: () async {
                final picked = await showSkillsPicker(
                  context,
                  subjectId: subjectId,
                  grade: grade,
                  selected: indicators,
                );
                if (picked != null) onChanged(picked);
              },
              icon: const Icon(Icons.checklist),
              label: const Text('เลือกตัวชี้วัด'),
            ),
          ],
        ),
        IndicatorChips(
          indicators: indicators,
          pendingCodes: pendingCodes,
          onRemove: (s) => onChanged([
            for (final i in indicators)
              if (i.id != s.id) i,
          ]),
        ),
      ],
    );
  }
}

/// "เพิ่มตัวชี้วัดของโรงเรียน" (DESIGN §20.2): the teacher picks the
/// standard (or indicator) it belongs under and names it; the server gives
/// it a code when none is typed. Pops the new [Skill], shared with every
/// teacher of the school and labelled "ครูเพิ่มเอง".
Future<Skill?> showAddIndicatorDialog(
  BuildContext context, {
  int? subjectId,
  int? grade,
  String? code,
  String? name,
}) => showDialog<Skill>(
  context: context,
  builder: (_) => AddIndicatorDialog(
    subjectId: subjectId,
    grade: grade,
    initialCode: code,
    initialName: name,
  ),
);

class AddIndicatorDialog extends ConsumerStatefulWidget {
  const AddIndicatorDialog({
    super.key,
    this.subjectId,
    this.grade,
    this.initialCode,
    this.initialName,
    this.debounce = const Duration(milliseconds: 350),
  });

  final int? subjectId;
  final int? grade;
  final String? initialCode;
  final String? initialName;
  final Duration debounce;

  @override
  ConsumerState<AddIndicatorDialog> createState() => _AddIndicatorDialogState();
}

class _AddIndicatorDialogState extends ConsumerState<AddIndicatorDialog> {
  late final _search = TextEditingController(
    text: _parentGuess(widget.initialCode),
  );
  late final _code = TextEditingController(text: widget.initialCode ?? '');
  late final _name = TextEditingController(text: widget.initialName ?? '');
  List<Skill> _parents = const [];
  Skill? _parent;
  bool _loading = false;
  bool _saving = false;
  String? _error;
  Timer? _debounce;

  /// "ค 1.1 ป.5/9" is searched under "ค 1.1" (its standard).
  static String _parentGuess(String? code) {
    if (code == null) return '';
    final space = code.lastIndexOf(' ');
    return space > 0 ? code.substring(0, space).trim() : '';
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final rows = await ref
          .read(assignmentsRepositoryProvider)
          .searchSkills(
            subjectId: widget.subjectId,
            q: _search.text.trim(),
            level: kParentLevels,
          );
      if (mounted) setState(() => _parents = rows);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    final parent = _parent;
    if (parent == null || _name.text.trim().isEmpty) {
      setState(
        () => _error = 'เลือกมาตรฐานหรือตัวชี้วัดที่จะเพิ่มไว้ใต้ และกรอกชื่อ',
      );
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final skill = await ref
          .read(coursesRepositoryProvider)
          .addIndicator(
            parentId: parent.id,
            name: _name.text.trim(),
            code: _code.text.trim().isEmpty ? null : _code.text.trim(),
          );
      if (mounted) Navigator.of(context).pop(skill);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AlertDialog(
      title: const Text('เพิ่มตัวชี้วัดของโรงเรียน'),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'ตัวชี้วัดที่เพิ่มจะมีป้าย "ครูเพิ่มเอง" และครูทุกคนในโรงเรียนเลือกใช้ได้',
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              TextField(
                key: const ValueKey('add_indicator_parent_search'),
                controller: _search,
                decoration: const InputDecoration(
                  labelText: 'อยู่ภายใต้ (มาตรฐานหรือตัวชี้วัด)',
                  prefixIcon: Icon(Icons.search),
                  hintText: 'ค้นด้วยรหัส เช่น ค 1.1',
                ),
                onChanged: (_) {
                  _debounce?.cancel();
                  _debounce = Timer(widget.debounce, _load);
                },
              ),
              const SizedBox(height: 8),
              SizedBox(
                height: 180,
                child: _loading && _parents.isEmpty
                    ? const Center(child: CircularProgressIndicator())
                    : _parents.isEmpty
                    ? const Center(child: Text('ไม่พบมาตรฐานหรือตัวชี้วัด'))
                    : RadioGroup<int>(
                        groupValue: _parent?.id,
                        onChanged: (id) => setState(
                          () => _parent = _parents
                              .where((p) => p.id == id)
                              .firstOrNull,
                        ),
                        child: ListView(
                          children: [
                            for (final p in _parents)
                              RadioListTile<int>(
                                dense: true,
                                value: p.id,
                                title: Text(p.code),
                                subtitle: Text(
                                  p.name,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                        ),
                      ),
              ),
              const SizedBox(height: 8),
              TextField(
                key: const ValueKey('add_indicator_name'),
                controller: _name,
                minLines: 1,
                maxLines: 3,
                decoration: const InputDecoration(labelText: 'ชื่อตัวชี้วัด'),
              ),
              const SizedBox(height: 8),
              TextField(
                key: const ValueKey('add_indicator_code'),
                controller: _code,
                decoration: const InputDecoration(
                  labelText: 'รหัส (ไม่บังคับ)',
                  helperText:
                      'เว้นว่างไว้ ระบบตั้งรหัสให้ต่อจากรหัสที่เลือกไว้ข้างบน',
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
              ],
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
          key: const ValueKey('add_indicator_save'),
          onPressed: _saving ? null : _save,
          child: const Text('เพิ่ม'),
        ),
      ],
    );
  }
}
