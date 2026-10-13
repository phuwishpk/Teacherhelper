import 'package:flutter/material.dart';

import '../classrooms/classroom.dart';

/// "เลือกนักเรียน" of a post or homework for chosen students (DESIGN §30.1,
/// §30.13): the roster with checkboxes. Pops the chosen ids, sorted.
Future<List<int>?> showStudentsPicker(
  BuildContext context, {
  required List<RosterStudent> roster,
  required List<int> selected,
}) => showDialog<List<int>>(
  context: context,
  builder: (context) => _StudentsDialog(roster: roster, selected: selected),
);

class _StudentsDialog extends StatefulWidget {
  const _StudentsDialog({required this.roster, required this.selected});

  final List<RosterStudent> roster;
  final List<int> selected;

  @override
  State<_StudentsDialog> createState() => _StudentsDialogState();
}

class _StudentsDialogState extends State<_StudentsDialog> {
  late final _chosen = {...widget.selected};

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เลือกนักเรียน'),
      contentPadding: const EdgeInsets.symmetric(vertical: 12),
      content: SizedBox(
        width: 420,
        child: widget.roster.isEmpty
            ? const Padding(
                padding: EdgeInsets.all(24),
                child: Text('ห้องนี้ยังไม่มีนักเรียน'),
              )
            : ListView(
                shrinkWrap: true,
                children: [
                  for (final s in widget.roster)
                    CheckboxListTile(
                      key: ValueKey('pick_student_${s.studentId}'),
                      value: _chosen.contains(s.studentId),
                      title: Text('${s.studentNumber}. ${s.name}'),
                      onChanged: (on) => setState(
                        () => on ?? false
                            ? _chosen.add(s.studentId)
                            : _chosen.remove(s.studentId),
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
          key: const ValueKey('pick_students_done'),
          onPressed: _chosen.isEmpty
              ? null
              : () => Navigator.of(context).pop(_chosen.toList()..sort()),
          child: Text('เลือก ${_chosen.length} คน'),
        ),
      ],
    );
  }
}
