import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import 'classroom.dart';
import 'classrooms_repository.dart';

/// "แก้ข้อมูลนักเรียน" (DESIGN §24.4): the name and the student code of
/// the account (`PATCH /students/{id}`) and the number in this room
/// (`PATCH /classrooms/{id}/students/{student_id}`). Resolves to true once
/// something was saved.
Future<bool> showStudentEditDialog(
  BuildContext context, {
  required int classroomId,
  required RosterStudent student,
}) async =>
    await showDialog<bool>(
      context: context,
      builder: (_) =>
          _StudentEditDialog(classroomId: classroomId, student: student),
    ) ??
    false;

class _StudentEditDialog extends ConsumerStatefulWidget {
  const _StudentEditDialog({required this.classroomId, required this.student});

  final int classroomId;
  final RosterStudent student;

  @override
  ConsumerState<_StudentEditDialog> createState() => _StudentEditDialogState();
}

class _StudentEditDialogState extends ConsumerState<_StudentEditDialog> {
  late final _name = TextEditingController(text: widget.student.name);
  late final _code = TextEditingController(
    text: widget.student.studentCode ?? '',
  );
  late final _number = TextEditingController(
    text: '${widget.student.studentNumber}',
  );
  bool _busy = false;
  String? _error;

  /// Part of the change was saved before an error: the roster must reload.
  bool _saved = false;

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    _number.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    final code = _code.text.trim();
    final number = int.tryParse(_number.text.trim());
    if (name.isEmpty) {
      setState(() => _error = 'กรุณากรอกชื่อนักเรียน');
      return;
    }
    if (number == null || number < 1 || number > 255) {
      setState(() => _error = 'เลขที่ต้องอยู่ระหว่าง 1–255');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final repo = ref.read(classroomsRepositoryProvider);
    final s = widget.student;
    try {
      if (name != s.name || code != (s.studentCode ?? '')) {
        await repo.updateStudent(
          s.studentId,
          name: name,
          studentCode: code.isEmpty ? null : code,
        );
        _saved = true;
      }
      if (number != s.studentNumber) {
        await repo.updateStudentNumber(widget.classroomId, s.studentId, number);
        _saved = true;
      }
      if (mounted) Navigator.of(context).pop(_saved);
    } catch (e) {
      if (!mounted) return;
      final taken = apiErrorBody(e)?['existing_student'];
      setState(() {
        _busy = false;
        _error = [
          apiErrorMessage(e),
          if (taken is Map) 'เลขนี้เป็นของ ${taken['name']}',
          // The name may already be saved when only the number failed.
          if (_saved) 'บันทึกชื่อและเลขประจำตัวแล้ว',
        ].join('\n');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('แก้ข้อมูลนักเรียน'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const ValueKey('edit_student_name'),
              controller: _name,
              decoration: const InputDecoration(labelText: 'ชื่อ'),
            ),
            TextField(
              key: const ValueKey('edit_student_code'),
              controller: _code,
              decoration: const InputDecoration(
                labelText: 'เลขประจำตัวนักเรียน',
                helperText: 'เว้นว่างได้',
              ),
            ),
            TextField(
              key: const ValueKey('edit_student_number'),
              controller: _number,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'เลขที่ในห้องนี้'),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(_saved),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('edit_student_save'),
          onPressed: _busy ? null : _save,
          child: const Text('บันทึก'),
        ),
      ],
    );
  }
}
