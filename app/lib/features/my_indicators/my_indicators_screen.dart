import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../assignments/assignments_providers.dart';
import '../assignments/assignments_repository.dart';
import '../assignments/question.dart';
import '../courses/indicator_widgets.dart';
import 'my_indicators.dart';

/// One subject and grade of the teacher's set; [grade] null holds the
/// teacher's own indicators that have no grade.
typedef IndicatorGroupKey = ({int? subjectId, int? grade});

/// "ตัวชี้วัดของฉัน" (DESIGN §34), from the settings and the home card: the
/// teacher adds a subject and grade (all its indicators at once), then
/// unticks the ones not taught. Every change is saved at once.
class MyIndicatorsScreen extends ConsumerStatefulWidget {
  const MyIndicatorsScreen({super.key});

  @override
  ConsumerState<MyIndicatorsScreen> createState() => _MyIndicatorsScreenState();
}

class _MyIndicatorsScreenState extends ConsumerState<MyIndicatorsScreen> {
  /// Every indicator of a group, loaded when the group is opened.
  final Map<IndicatorGroupKey, List<Skill>> _all = {};
  bool _saving = false;

  Future<List<Skill>> _load(IndicatorGroupKey key) => ref
      .read(assignmentsRepositoryProvider)
      .searchSkills(
        subjectId: key.subjectId,
        grade: key.grade,
        level: kAssessableLevels,
      );

  Future<void> _save(Iterable<Skill> skills, {String? done}) async {
    setState(() => _saving = true);
    try {
      await ref.read(myIndicatorsProvider.notifier).replace(skills);
      if (mounted && done != null) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(done)));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _addGroup(List<Skill> current) async {
    final key = await showDialog<IndicatorGroupKey>(
      context: context,
      builder: (_) => const _AddGroupDialog(),
    );
    if (key == null || !mounted) return;
    setState(() => _saving = true);
    List<Skill> found;
    try {
      found = await _load(key);
    } catch (e) {
      if (mounted) {
        setState(() => _saving = false);
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
      }
      return;
    }
    if (!mounted) return;
    _all[key] = found;
    if (found.isEmpty) {
      setState(() => _saving = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('วิชาและชั้นนี้ยังไม่มีตัวชี้วัดในหลักสูตร'),
        ),
      );
      return;
    }
    await _save([
      ...current,
      ...found,
    ], done: 'เพิ่มตัวชี้วัด ${found.length} ตัวแล้ว เอาตัวที่ไม่ได้สอนออกได้');
  }

  @override
  Widget build(BuildContext context) {
    final mine = ref.watch(myIndicatorsProvider);
    final subjects = {
      for (final s in ref.watch(subjectsProvider).value ?? const <Subject>[])
        s.id: s.name,
    };
    return Scaffold(
      appBar: AppBar(title: const Text('ตัวชี้วัดของฉัน')),
      body: AsyncView<List<Skill>>(
        value: mine,
        onRetry: () => ref.invalidate(myIndicatorsProvider),
        data: (current) {
          final groups = <IndicatorGroupKey, List<Skill>>{};
          for (final s in current) {
            groups
                .putIfAbsent((
                  subjectId: s.subjectId,
                  grade: s.gradeLevel,
                ), () => [])
                .add(s);
          }
          final theme = Theme.of(context);
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 720),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'เลือกวิชาและชั้นที่คุณสอน หน้าเลือกตัวชี้วัดตอนสร้างงาน '
                        'รายวิชา หน่วย และแผน จะแสดงเฉพาะตัวชี้วัดเหล่านี้กับตัวที่คุณเพิ่มเอง '
                        '(สลับไปดูทั้งหลักสูตรได้ในหน้านั้น)',
                        style: theme.textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 12),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: FilledButton.icon(
                          key: const ValueKey('my_indicators_add_group'),
                          onPressed: _saving ? null : () => _addGroup(current),
                          icon: const Icon(Icons.add),
                          label: const Text('เพิ่มวิชาและชั้น'),
                        ),
                      ),
                      if (_saving) ...[
                        const SizedBox(height: 8),
                        const LinearProgressIndicator(),
                      ],
                      const SizedBox(height: 12),
                      if (groups.isEmpty)
                        Text(
                          'ยังไม่ได้เลือก หน้าเลือกตัวชี้วัดจะแสดงทั้งหลักสูตร',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      for (final entry in groups.entries)
                        _GroupCard(
                          key: ValueKey(
                            'my_indicators_group_${entry.key.subjectId}_${entry.key.grade}',
                          ),
                          title: _groupTitle(entry.key, subjects),
                          chosen: entry.value,
                          all: _all[entry.key],
                          enabled: !_saving,
                          onOpen: () async {
                            if (_all.containsKey(entry.key)) return;
                            try {
                              final all = await _load(entry.key);
                              if (mounted) {
                                setState(() => _all[entry.key] = all);
                              }
                            } catch (e) {
                              if (mounted) {
                                ScaffoldMessenger.of(this.context).showSnackBar(
                                  SnackBar(content: Text(apiErrorMessage(e))),
                                );
                              }
                            }
                          },
                          onToggle: (skill, on) => _save(
                            on
                                ? [...current, skill]
                                : current.where((s) => s.id != skill.id),
                          ),
                          onRemove: () => _save(
                            current.where((s) => !entry.value.contains(s)),
                            done:
                                'เอา${_groupTitle(entry.key, subjects)}ออกแล้ว',
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  static String _groupTitle(IndicatorGroupKey key, Map<int, String> subjects) {
    final subject = subjects[key.subjectId] ?? 'วิชา';
    final grade = key.grade;
    return grade == null
        ? '$subject (ไม่ระบุชั้น)'
        : '$subject ${gradeLevelLabel(grade)}';
  }
}

class _GroupCard extends StatelessWidget {
  const _GroupCard({
    super.key,
    required this.title,
    required this.chosen,
    required this.all,
    required this.enabled,
    required this.onOpen,
    required this.onToggle,
    required this.onRemove,
  });

  final String title;
  final List<Skill> chosen;

  /// Every indicator of the subject and grade; null until loaded.
  final List<Skill>? all;
  final bool enabled;
  final VoidCallback onOpen;
  final void Function(Skill skill, bool on) onToggle;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final chosenIds = {for (final s in chosen) s.id};
    // Chosen ones the curriculum list lacks (e.g. without a grade) stay listed.
    final rows = [
      ...?all,
      ...chosen.where((s) => !(all ?? const []).contains(s)),
    ];
    return Card(
      child: ExpansionTile(
        title: Text(title),
        subtitle: Text(
          all == null
              ? 'เลือกไว้ ${chosen.length} ตัว'
              : 'เลือกไว้ ${chosen.length} จาก ${rows.length} ตัว',
        ),
        onExpansionChanged: (open) {
          if (open) onOpen();
        },
        children: [
          if (all == null)
            const Padding(
              padding: EdgeInsets.all(16),
              child: LinearProgressIndicator(),
            )
          else
            for (final s in rows)
              CheckboxListTile(
                dense: true,
                value: chosenIds.contains(s.id),
                onChanged: enabled ? (v) => onToggle(s, v ?? false) : null,
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
              ),
          Align(
            alignment: Alignment.centerRight,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              child: TextButton.icon(
                onPressed: enabled ? onRemove : null,
                icon: const Icon(Icons.remove_circle_outline),
                label: const Text('เอาวิชาและชั้นนี้ออก'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Picks a subject and a grade; pops them, or null when cancelled.
class _AddGroupDialog extends ConsumerStatefulWidget {
  const _AddGroupDialog();

  @override
  ConsumerState<_AddGroupDialog> createState() => _AddGroupDialogState();
}

class _AddGroupDialogState extends ConsumerState<_AddGroupDialog> {
  int? _subjectId;
  int? _grade;

  @override
  Widget build(BuildContext context) {
    final subjects = ref.watch(subjectsProvider);
    return AlertDialog(
      title: const Text('เพิ่มวิชาและชั้น'),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AsyncView<List<Subject>>(
              value: subjects,
              onRetry: () => ref.invalidate(subjectsProvider),
              data: (list) => DropdownButtonFormField<int>(
                key: const ValueKey('my_indicators_subject'),
                initialValue: _subjectId,
                decoration: const InputDecoration(labelText: 'วิชา'),
                items: [
                  for (final s in list)
                    DropdownMenuItem(value: s.id, child: Text(s.name)),
                ],
                onChanged: (v) => setState(() => _subjectId = v),
              ),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              key: const ValueKey('my_indicators_grade'),
              initialValue: _grade,
              decoration: const InputDecoration(labelText: 'ชั้น'),
              items: [
                for (var g = 1; g <= 12; g++)
                  DropdownMenuItem(value: g, child: Text(gradeLevelLabel(g))),
              ],
              onChanged: (v) => setState(() => _grade = v),
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
          key: const ValueKey('my_indicators_add_confirm'),
          onPressed: _subjectId == null || _grade == null
              ? null
              : () => Navigator.of(
                  context,
                ).pop((subjectId: _subjectId, grade: _grade)),
          child: const Text('เพิ่มทุกตัวชี้วัด'),
        ),
      ],
    );
  }
}
