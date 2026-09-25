import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// Pairs the Google accounts of the linked course with the students of the
/// room (`GET/PUT /classrooms/{id}/google-roster`, DESIGN §18.6, §18.7).
/// Suggested pairs come pre-filled; the teacher edits and saves.
class GoogleRosterScreen extends ConsumerWidget {
  const GoogleRosterScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final google = ref.watch(googleRosterProvider(classroomId));
    final students = ref.watch(rosterProvider(classroomId));
    final classroom = ref.watch(classroomProvider(classroomId)).value;

    Widget body;
    if (google case AsyncError(:final error)) {
      body = ErrorView(
        message: googleErrorMessage(error),
        onRetry: () => ref.invalidate(googleRosterProvider(classroomId)),
      );
    } else if (students case AsyncError(:final error)) {
      body = ErrorView(
        message: apiErrorMessage(error),
        onRetry: () => ref.invalidate(rosterProvider(classroomId)),
      );
    } else if (google.value case final entries? when students.value != null) {
      body = RosterMatchingForm(
        classroomId: classroomId,
        entries: entries,
        students: students.value!,
        courseName: classroom?.googleLink?.courseName,
      );
    } else {
      body = const Center(child: CircularProgressIndicator());
    }

    return Scaffold(
      appBar: AppBar(title: const Text('จับคู่นักเรียนกับ Google Classroom')),
      body: body,
    );
  }
}

/// The matching list itself (separate so tests can pump it with data).
class RosterMatchingForm extends ConsumerStatefulWidget {
  const RosterMatchingForm({
    super.key,
    required this.classroomId,
    required this.entries,
    required this.students,
    this.courseName,
  });

  final int classroomId;
  final List<GoogleRosterEntry> entries;
  final List<RosterStudent> students;
  final String? courseName;

  @override
  ConsumerState<RosterMatchingForm> createState() => _RosterMatchingFormState();
}

class _RosterMatchingFormState extends ConsumerState<RosterMatchingForm> {
  late final Map<String, int?> _matches = {
    for (final e in widget.entries) e.googleUserId: _known(e.initialStudentId),
  };
  bool _saving = false;

  /// Drops a suggested id that is not (or no longer) in the room.
  int? _known(int? id) =>
      id != null && widget.students.any((s) => s.studentId == id) ? id : null;

  /// Student ids chosen for more than one account.
  Set<int> get _duplicates {
    final seen = <int>{};
    return {
      for (final id in _matches.values.nonNulls)
        if (!seen.add(id)) id,
    };
  }

  Future<void> _save() async {
    if (_duplicates.isNotEmpty) return;
    setState(() => _saving = true);
    try {
      await ref
          .read(googleClassroomRepositoryProvider)
          .saveRoster(widget.classroomId, Map.of(_matches));
      ref.invalidate(googleRosterProvider(widget.classroomId));
      if (!mounted) return;
      showMessage(context, 'บันทึกการจับคู่แล้ว');
      if (context.canPop()) context.pop();
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final duplicates = _duplicates;
    final matched = _matches.values.nonNulls.toSet();
    final unmatchedAccounts = _matches.values.where((v) => v == null).length;
    final studentsWithout = widget.students
        .where((s) => !matched.contains(s.studentId))
        .toList();
    final byId = {for (final s in widget.students) s.studentId: s};

    return Column(
      children: [
        Expanded(
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: ListView(
              children: [
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          widget.courseName == null
                              ? 'จับคู่แล้ว ${matched.length}/${widget.entries.length} บัญชี'
                              : '${widget.courseName}: จับคู่แล้ว '
                                    '${matched.length}/${widget.entries.length} บัญชี',
                          key: const ValueKey('roster_summary'),
                          style: theme.textTheme.titleMedium,
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          'ระบบเสนอคู่จากชื่อให้แล้ว ตรวจแล้วแก้ให้ถูกก่อนบันทึก',
                        ),
                        if (duplicates.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          Text(
                            'เลือกนักเรียนคนเดียวกันให้หลายบัญชี: '
                            '${duplicates.map((id) => byId[id]?.name ?? '$id').join(', ')} '
                            'นักเรียนหนึ่งคนจับคู่ได้บัญชีเดียว',
                            key: const ValueKey('roster_duplicates'),
                            style: TextStyle(color: theme.colorScheme.error),
                          ),
                        ],
                        if (unmatchedAccounts > 0) ...[
                          const SizedBox(height: 8),
                          _Warning(
                            key: const ValueKey('roster_unmatched_accounts'),
                            text:
                                'บัญชีใน Classroom ที่ยังไม่จับคู่ $unmatchedAccounts บัญชี: '
                                'งานที่ส่งจากบัญชีเหล่านี้ยังสแกนได้ถ้าใบงานมี QR ของนักเรียน '
                                'แต่ส่งคะแนนกลับ Classroom ไม่ได้',
                          ),
                        ],
                        if (studentsWithout.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          _Warning(
                            key: const ValueKey('roster_unmatched_students'),
                            text:
                                'นักเรียนในห้องที่ไม่มีบัญชี Classroom ${studentsWithout.length} คน '
                                '(${studentsWithout.take(5).map((s) => 'เลขที่ ${s.studentNumber}').join(', ')}'
                                '${studentsWithout.length > 5 ? ', …' : ''}): '
                                'ส่งงานเป็นกระดาษให้ครูสแกนด้วยกล้องได้ตามปกติ '
                                'แต่คะแนนจะไม่ถูกส่งกลับ Classroom',
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                if (widget.entries.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Text(
                      'ยังไม่มีนักเรียนในคอร์สนี้ เชิญนักเรียนเข้าชั้นเรียนใน Google Classroom ก่อน',
                      textAlign: TextAlign.center,
                    ),
                  ),
                for (final entry in widget.entries)
                  _EntryTile(
                    entry: entry,
                    students: widget.students,
                    value: _matches[entry.googleUserId],
                    duplicate: duplicates.contains(
                      _matches[entry.googleUserId],
                    ),
                    enabled: !_saving,
                    onChanged: (id) =>
                        setState(() => _matches[entry.googleUserId] = id),
                  ),
              ],
            ),
          ),
        ),
        SafeArea(
          top: false,
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                key: const ValueKey('roster_save'),
                onPressed: _saving || duplicates.isNotEmpty ? null : _save,
                icon: _saving
                    ? const SizedBox.square(
                        dimension: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.save_outlined),
                label: const Text('บันทึกการจับคู่'),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _Warning extends StatelessWidget {
  const _Warning({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2, right: 8),
          child: Icon(
            Icons.warning_amber_rounded,
            size: 18,
            color: theme.colorScheme.tertiary,
          ),
        ),
        Expanded(child: Text(text)),
      ],
    );
  }
}

class _EntryTile extends StatelessWidget {
  const _EntryTile({
    required this.entry,
    required this.students,
    required this.value,
    required this.duplicate,
    required this.enabled,
    required this.onChanged,
  });

  final GoogleRosterEntry entry;
  final List<RosterStudent> students;
  final int? value;
  final bool duplicate;
  final bool enabled;
  final ValueChanged<int?> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final suggested =
        entry.matchedStudentId == null &&
        entry.suggestedStudentId != null &&
        value == entry.suggestedStudentId;
    return Card(
      key: ValueKey('roster_entry_${entry.googleUserId}'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(entry.name, style: theme.textTheme.titleSmall),
                      if (entry.email != null)
                        Text(entry.email!, style: theme.textTheme.bodySmall),
                    ],
                  ),
                ),
                if (suggested) const StatusChip(label: 'เสนอจากชื่อ'),
              ],
            ),
            const SizedBox(height: 8),
            DropdownButton<int?>(
              key: ValueKey('roster_pick_${entry.googleUserId}'),
              isExpanded: true,
              value: value,
              onChanged: enabled ? onChanged : null,
              items: [
                const DropdownMenuItem<int?>(
                  value: null,
                  child: Text('ไม่จับคู่'),
                ),
                for (final s in students)
                  DropdownMenuItem<int?>(
                    value: s.studentId,
                    child: Text(
                      'เลขที่ ${s.studentNumber}  ${s.name}',
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
            ),
            if (duplicate)
              Text(
                'นักเรียนคนนี้ถูกเลือกให้บัญชีอื่นแล้ว',
                style: TextStyle(color: theme.colorScheme.error),
              ),
          ],
        ),
      ),
    );
  }
}
