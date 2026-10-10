import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../gradebook/gradebook_providers.dart';
import 'attendance_models.dart';
import 'attendance_repository.dart';

/// `/courses/:id/attendance/new?classroom=` and
/// `/courses/:id/attendance/:sid` (DESIGN §29.5): one period. A new period
/// starts with everyone มาตรงเวลา; the teacher changes only the others.
class AttendanceSessionScreen extends ConsumerWidget {
  const AttendanceSessionScreen({
    super.key,
    required this.courseId,
    this.classroomId,
    this.sessionId,
  }) : assert(classroomId != null || sessionId != null);

  final int courseId;

  /// Of a new period.
  final int? classroomId;

  /// Of a checked period being edited.
  final int? sessionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (sessionId case final id?) {
      final session = ref.watch(attendanceSessionProvider(id));
      return session.value == null
          ? Scaffold(
              appBar: AppBar(title: const Text('แก้การเช็คชื่อ')),
              body: AsyncView(
                value: session,
                onRetry: () => ref.invalidate(attendanceSessionProvider(id)),
                data: (_) => const SizedBox.shrink(),
              ),
            )
          : _SessionForm(
              key: ValueKey('session_$id'),
              courseId: courseId,
              classroomId: session.value!.classroomId,
              session: session.value,
              people: session.value!.records,
            );
    }
    final key = (courseId: courseId, classroomId: classroomId!);
    final overview = ref.watch(attendanceOverviewProvider(key));
    return overview.value == null
        ? Scaffold(
            appBar: AppBar(title: const Text('เช็คชื่อ')),
            body: AsyncView(
              value: overview,
              onRetry: () => ref.invalidate(attendanceOverviewProvider(key)),
              data: (_) => const SizedBox.shrink(),
            ),
          )
        : _SessionForm(
            courseId: courseId,
            classroomId: classroomId!,
            people: [
              for (final s in overview.value!.students)
                if (s.inClassroom)
                  AttendanceEntry(
                    studentId: s.studentId,
                    studentNumber: s.studentNumber,
                    name: s.name,
                    inClassroom: true,
                  ),
            ],
          );
  }
}

class _SessionForm extends ConsumerStatefulWidget {
  const _SessionForm({
    super.key,
    required this.courseId,
    required this.classroomId,
    required this.people,
    this.session,
  });

  final int courseId;
  final int classroomId;
  final List<AttendanceEntry> people;
  final AttendanceSession? session;

  @override
  ConsumerState<_SessionForm> createState() => _SessionFormState();
}

class _SessionFormState extends ConsumerState<_SessionForm> {
  late DateTime _day = widget.session?.heldOn ?? _today();
  late int? _periodNo = widget.session?.periodNo;
  late final _note = TextEditingController(text: widget.session?.note ?? '');
  late final Map<int, AttendanceStatus> _status = {
    for (final p in widget.people)
      // A new period, or a student who joined after it: มาตรงเวลา until changed.
      p.studentId: p.status ?? AttendanceStatus.present,
  };
  late final Map<int, String?> _notes = {
    for (final p in widget.people) p.studentId: p.note,
  };
  bool _busy = false;

  bool get _editing => widget.session != null;

  static DateTime _today() {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _done(String message) {
    ref.invalidate(attendanceOverviewProvider);
    ref.invalidate(gradebookGridProvider);
    if (widget.session case final s?) {
      ref.invalidate(attendanceSessionProvider(s.id));
    }
    showMessage(context, message);
    context.pop();
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: DateTime(2020),
      lastDate: DateTime(_today().year + 1, 12, 31),
    );
    if (picked != null) setState(() => _day = picked);
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final note = _note.text.trim();
    final draft = AttendanceDraft(
      heldOn: _day,
      periodNo: _periodNo,
      note: note.isEmpty ? null : note,
      records: {
        for (final e in _status.entries)
          e.key: (status: e.value, note: _notes[e.key]),
      },
    );
    try {
      final repo = ref.read(attendanceRepositoryProvider);
      if (widget.session case final s?) {
        await repo.update(s.id, draft);
      } else {
        await repo.create(widget.courseId, widget.classroomId, draft);
      }
      if (mounted) _done('บันทึกการเช็คชื่อแล้ว');
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    final ok = await confirm(
      context,
      title: 'ลบการเช็คชื่อคาบนี้?',
      message:
          'สถานะของนักเรียนทุกคนในคาบนี้จะหายไป และคะแนนการเข้าเรียนคิดใหม่',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(attendanceRepositoryProvider).delete(widget.session!.id);
      if (mounted) _done('ลบการเช็คชื่อแล้ว');
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editNote(AttendanceEntry p) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => _NoteDialog(name: p.name, initial: _notes[p.studentId]),
    );
    if (note == null) return;
    setState(() => _notes[p.studentId] = note.isEmpty ? null : note);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final counts = <AttendanceStatus, int>{};
    for (final s in _status.values) {
      counts[s] = (counts[s] ?? 0) + 1;
    }
    return Scaffold(
      appBar: AppBar(
        title: Text(_editing ? 'แก้การเช็คชื่อ' : 'เช็คชื่อ'),
        actions: [
          if (_editing)
            IconButton(
              key: const ValueKey('session_delete'),
              tooltip: 'ลบการเช็คชื่อคาบนี้',
              icon: const Icon(Icons.delete_outline),
              onPressed: _busy ? null : _delete,
            ),
        ],
      ),
      bottomNavigationBar: BottomActionBar(
        child: Row(
          children: [
            Expanded(
              child: Text(
                [
                  for (final s in AttendanceStatus.values)
                    if ((counts[s] ?? 0) > 0) '${s.short} ${counts[s]}',
                ].join(' · '),
                key: const ValueKey('session_counts'),
              ),
            ),
            const SizedBox(width: 12),
            FilledButton(
              key: const ValueKey('session_save'),
              onPressed: _busy || widget.people.isEmpty ? null : _save,
              child: Text(_busy ? 'กำลังบันทึก…' : 'บันทึก'),
            ),
          ],
        ),
      ),
      body: ContentColumn(
        child: ListView(
          children: [
            // Room for the floating label of the period field.
            const SizedBox(height: 8),
            Wrap(
              spacing: 12,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                OutlinedButton.icon(
                  key: const ValueKey('session_day'),
                  onPressed: _pickDay,
                  icon: const Icon(Icons.event_outlined),
                  label: Text(formatThaiDate(_day)),
                ),
                SizedBox(
                  width: 150,
                  child: DropdownButtonFormField<int?>(
                    key: const ValueKey('session_period'),
                    initialValue: _periodNo,
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'คาบ'),
                    items: [
                      const DropdownMenuItem(
                        value: null,
                        child: Text('ไม่ระบุ'),
                      ),
                      for (var n = 1; n <= 12; n++)
                        DropdownMenuItem(value: n, child: Text('คาบ $n')),
                      // A stored number above the list stays selectable.
                      if ((_periodNo ?? 0) > 12)
                        DropdownMenuItem(
                          value: _periodNo,
                          child: Text('คาบ $_periodNo'),
                        ),
                    ],
                    onChanged: (v) => setState(() => _periodNo = v),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            TextField(
              key: const ValueKey('session_note'),
              controller: _note,
              maxLength: 255,
              decoration: const InputDecoration(
                labelText: 'หมายเหตุของคาบ (ไม่บังคับ)',
              ),
            ),
            if (widget.people.isEmpty)
              const Card(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Text(
                    'ห้องนี้ยังไม่มีนักเรียน เพิ่มนักเรียนก่อนเช็คชื่อ',
                  ),
                ),
              )
            else ...[
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  key: const ValueKey('session_all_present'),
                  onPressed: () => setState(() {
                    for (final id in _status.keys.toList()) {
                      _status[id] = AttendanceStatus.present;
                    }
                  }),
                  icon: const Icon(Icons.done_all),
                  label: const Text('มาทุกคน'),
                ),
              ),
              Card(
                child: Column(
                  children: [for (final p in widget.people) _row(theme, p)],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _row(ThemeData theme, AttendanceEntry p) {
    final current = _status[p.studentId]!;
    final note = _notes[p.studentId];
    return Padding(
      key: ValueKey('session_student_${p.studentId}'),
      padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  [
                    if (p.studentNumber case final n?) '$n.',
                    p.inClassroom ? p.name : '${p.name} (ออกจากห้องแล้ว)',
                  ].join(' '),
                  style: theme.textTheme.titleSmall,
                ),
              ),
              IconButton(
                key: ValueKey('session_note_${p.studentId}'),
                tooltip: 'หมายเหตุของนักเรียนคนนี้',
                visualDensity: VisualDensity.compact,
                icon: Icon(
                  note == null
                      ? Icons.sticky_note_2_outlined
                      : Icons.sticky_note_2,
                ),
                onPressed: () => _editNote(p),
              ),
            ],
          ),
          if (note != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(note, style: theme.textTheme.bodySmall),
            ),
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              for (final s in AttendanceStatus.values)
                ChoiceChip(
                  key: ValueKey('session_${p.studentId}_${s.wire}'),
                  label: Text(s.short),
                  tooltip: s.label,
                  selected: s == current,
                  selectedColor: s
                      .color(theme.colorScheme)
                      .withValues(alpha: 0.2),
                  onSelected: (_) => setState(() => _status[p.studentId] = s),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _NoteDialog extends StatefulWidget {
  const _NoteDialog({required this.name, this.initial});

  final String name;
  final String? initial;

  @override
  State<_NoteDialog> createState() => _NoteDialogState();
}

class _NoteDialogState extends State<_NoteDialog> {
  late final _text = TextEditingController(text: widget.initial ?? '');

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('หมายเหตุของ ${widget.name}'),
      content: TextField(
        key: const ValueKey('student_note'),
        controller: _text,
        autofocus: true,
        maxLength: 255,
        decoration: const InputDecoration(hintText: 'เช่น ไปแข่งกีฬา'),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('student_note_save'),
          onPressed: () => Navigator.of(context).pop(_text.text.trim()),
          child: const Text('ตกลง'),
        ),
      ],
    );
  }
}
