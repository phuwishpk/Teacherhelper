import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_repository.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_repository.dart';
import 'assistant.dart';

/// «ข้อมูลนักเรียน» above the chat box (DESIGN §37.8): the first time the
/// notice and "เข้าใจแล้ว", then a classroom, a student or an assignment
/// whose results the next questions are about. The choice stays as a chip
/// until removed. Hidden when the admin switched student data off.
class StudentDataChip extends ConsumerWidget {
  const StudentDataChip({super.key, required this.status, this.enabled = true});

  final AssistantStatus status;
  final bool enabled;

  Future<void> _choose(BuildContext context, WidgetRef ref) async {
    if (!status.studentDataAcknowledged) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (_) => const _Notice(),
      );
      if (ok != true || !context.mounted) return;
      try {
        await ref.read(assistantRepositoryProvider).acknowledgeStudentData();
        ref.invalidate(assistantStatusProvider);
      } catch (e) {
        if (!context.mounted) return;
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
        return;
      }
    }
    if (!context.mounted) return;
    final scope = await showDialog<DataScope>(
      context: context,
      builder: (_) => const _ScopeDialog(),
    );
    if (scope != null) ref.read(assistantScopeProvider.notifier).choose(scope);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!status.studentDataAllowed) return const SizedBox.shrink();
    final scope = ref.watch(assistantScopeProvider);
    return Align(
      alignment: Alignment.centerLeft,
      child: scope == null
          ? ActionChip(
              key: const ValueKey('assistant_data'),
              avatar: const Icon(Icons.person_search_outlined),
              label: const Text('ข้อมูลนักเรียน'),
              onPressed: enabled ? () => _choose(context, ref) : null,
            )
          : InputChip(
              key: const ValueKey('assistant_scope'),
              avatar: const Icon(Icons.person_search_outlined),
              label: Text('ข้อมูล: ${scope.title}'),
              onPressed: enabled ? () => _choose(context, ref) : null,
              deleteIcon: const Icon(Icons.close),
              deleteButtonTooltipMessage: 'เลิกใช้ข้อมูลนี้',
              onDeleted: enabled
                  ? () => ref.read(assistantScopeProvider.notifier).choose(null)
                  : null,
            ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice();

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('ถามผู้ช่วยเรื่องข้อมูลนักเรียน'),
    content: const Text(
      'ผู้ช่วยจะส่งคะแนน การส่งงาน ผลรายข้อ ตัวชี้วัด และการเข้าเรียนของ'
      'นักเรียนในห้องที่ครูสอนไปให้ Gemini โดยแทนนักเรียนด้วยรหัส เช่น S1 '
      'ไม่ส่งชื่อ เลขที่ หรือเลขประจำตัว ชื่อที่ครูพิมพ์ก็ถูกแทนด้วยรหัสก่อนส่ง\n\n'
      'คำขอใช้ Gemini key ของครู ถ้าเป็น key ฟรี Google อาจนำข้อมูลไป'
      'ปรับปรุงบริการ บทสนทนาเก็บ 90 วัน และผู้ดูแลระบบเปิดอ่านได้',
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(false),
        child: const Text('ยกเลิก'),
      ),
      FilledButton(
        key: const ValueKey('assistant_data_ack'),
        onPressed: () => Navigator.of(context).pop(true),
        child: const Text('เข้าใจแล้ว'),
      ),
    ],
  );
}

enum _Kind { classroom, student, assignment }

class _ScopeDialog extends ConsumerStatefulWidget {
  const _ScopeDialog();

  @override
  ConsumerState<_ScopeDialog> createState() => _ScopeDialogState();
}

class _ScopeDialogState extends ConsumerState<_ScopeDialog> {
  _Kind _kind = _Kind.classroom;
  Classroom? _classroom;
  Future<List<RosterStudent>>? _roster;

  late final Future<(List<Classroom>, List<Assignment>)> _load = () async {
    final classrooms = await ref.read(classroomsRepositoryProvider).list();
    final assignments = await ref.read(assignmentsRepositoryProvider).list();
    return (
      classrooms.where((c) => !c.isClosed).toList(),
      assignments.where((a) => a.status != 'draft').toList(),
    );
  }();

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('ถามเรื่องข้อมูลของ'),
      scrollable: true,
      content: SizedBox(
        width: 480,
        child: FutureBuilder(
          future: _load,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return Text(apiErrorMessage(snapshot.error!));
            }
            final data = snapshot.data;
            if (data == null) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final (classrooms, assignments) = data;
            return Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SegmentedButton<_Kind>(
                  segments: const [
                    ButtonSegment(value: _Kind.classroom, label: Text('ห้อง')),
                    ButtonSegment(
                      value: _Kind.student,
                      label: Text('นักเรียน'),
                    ),
                    ButtonSegment(value: _Kind.assignment, label: Text('งาน')),
                  ],
                  selected: {_kind},
                  onSelectionChanged: (s) => setState(() => _kind = s.first),
                ),
                const SizedBox(height: 12),
                ...switch (_kind) {
                  _Kind.classroom => [
                    for (final c in classrooms)
                      ListTile(
                        key: ValueKey('assistant_data_classroom_${c.id}'),
                        contentPadding: EdgeInsets.zero,
                        title: Text(c.name),
                        onTap: () => Navigator.of(
                          context,
                        ).pop(DataScope.classroom(c.id, 'ห้อง ${c.name}')),
                      ),
                  ],
                  _Kind.assignment => [
                    if (assignments.isEmpty)
                      const Text('ยังไม่มีงานที่เปิดให้ส่งหรือปิดแล้ว'),
                    for (final a in assignments)
                      ListTile(
                        key: ValueKey('assistant_data_assignment_${a.id}'),
                        contentPadding: EdgeInsets.zero,
                        title: Text(a.title),
                        subtitle: a.classroomName == null
                            ? null
                            : Text(a.classroomName!),
                        onTap: () => Navigator.of(
                          context,
                        ).pop(DataScope.assignment(a.id, a.title)),
                      ),
                  ],
                  _Kind.student => [
                    DropdownButtonFormField<int>(
                      key: const ValueKey('assistant_data_student_classroom'),
                      initialValue: _classroom?.id,
                      decoration: const InputDecoration(labelText: 'ห้อง'),
                      items: [
                        for (final c in classrooms)
                          DropdownMenuItem(value: c.id, child: Text(c.name)),
                      ],
                      onChanged: (id) => setState(() {
                        _classroom = classrooms.firstWhere((c) => c.id == id);
                        _roster = ref
                            .read(classroomsRepositoryProvider)
                            .roster(id!);
                      }),
                    ),
                    if (_roster case final roster?)
                      FutureBuilder(
                        future: roster,
                        builder: (context, snap) => switch (snap) {
                          AsyncSnapshot(:final error?) => Text(
                            apiErrorMessage(error),
                          ),
                          AsyncSnapshot(data: final students?) => Column(
                            children: [
                              for (final s in students)
                                ListTile(
                                  key: ValueKey(
                                    'assistant_data_student_${s.studentId}',
                                  ),
                                  contentPadding: EdgeInsets.zero,
                                  leading: Text('${s.studentNumber}'),
                                  title: Text(s.name),
                                  onTap: () => Navigator.of(context).pop(
                                    DataScope.student(
                                      s.studentId,
                                      _classroom!.id,
                                      s.name,
                                    ),
                                  ),
                                ),
                            ],
                          ),
                          _ => const Padding(
                            padding: EdgeInsets.all(16),
                            child: Center(child: CircularProgressIndicator()),
                          ),
                        },
                      ),
                  ],
                },
              ],
            );
          },
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
      ],
    );
  }
}

/// [text] with every label of [labels] shown as the student's name.
String withNames(String text, Map<String, StudentLabel> labels) =>
    labels.isEmpty
    ? text
    : text.replaceAllMapped(
        RegExp(r'\bS\d+\b'),
        (m) => labels[m[0]]?.name ?? m[0]!,
      );

/// The labels [text] mentions, in order.
List<StudentLabel> mentioned(String text, Map<String, StudentLabel> labels) {
  final seen = <String>{};
  return [
    for (final m in RegExp(r'\bS\d+\b').allMatches(text))
      if (labels[m[0]] case final l? when seen.add(l.label)) l,
  ];
}
