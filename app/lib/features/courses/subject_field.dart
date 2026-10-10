import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../assignments/assignments_repository.dart';
import '../assignments/question.dart';

/// The subject group of a course: the shared groups and the teacher's own,
/// with a button that adds an own group (DESIGN §29.4).
class SubjectField extends ConsumerStatefulWidget {
  const SubjectField({
    super.key,
    required this.fieldKey,
    required this.value,
    required this.onChanged,
    this.helperText,
  });

  /// Key of the dropdown itself.
  final Key fieldKey;
  final int? value;

  /// Null locks the field (the subject group can no longer change).
  final ValueChanged<int?>? onChanged;
  final String? helperText;

  @override
  ConsumerState<SubjectField> createState() => _SubjectFieldState();
}

class _SubjectFieldState extends ConsumerState<SubjectField> {
  bool _busy = false;

  /// Bumped after adding a group, so the dropdown shows the new value.
  int _generation = 0;

  Future<void> _add() async {
    final name = await showDialog<String>(
      context: context,
      builder: (_) => const _AddSubjectDialog(),
    );
    if (name == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final subject = await ref
          .read(assignmentsRepositoryProvider)
          .createSubject(name);
      ref.invalidate(subjectsProvider);
      await ref.read(subjectsProvider.future);
      if (!mounted) return;
      widget.onChanged?.call(subject.id);
      setState(() => _generation++);
      showMessage(context, 'เพิ่มกลุ่มสาระ "${subject.name}" แล้ว');
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final subjects = ref.watch(subjectsProvider);
    final list = subjects.value ?? const <Subject>[];
    final locked = widget.onChanged == null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        KeyedSubtree(
          key: ValueKey(_generation),
          child: DropdownButtonFormField<int>(
            key: widget.fieldKey,
            initialValue: widget.value,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: 'กลุ่มสาระ',
              helperText: widget.helperText,
            ),
            items: [
              for (final s in list)
                DropdownMenuItem(
                  value: s.id,
                  child: Text(s.isOwn ? '${s.name} (ของฉัน)' : s.name),
                ),
            ],
            onChanged: widget.onChanged,
            validator: (v) => v == null ? 'เลือกกลุ่มสาระ' : null,
          ),
        ),
        if (subjects.hasError)
          Text(
            'โหลดกลุ่มสาระไม่ได้: ${apiErrorMessage(subjects.error!)}',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          )
        else if (subjects.hasValue && list.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'ยังไม่มีกลุ่มสาระในระบบ กด "เพิ่มกลุ่มสาระของฉัน" เพื่อสร้างเอง',
              key: const ValueKey('subject_empty_hint'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        if (!locked)
          TextButton.icon(
            key: const ValueKey('subject_add'),
            onPressed: _busy ? null : _add,
            icon: const Icon(Icons.add),
            label: const Text('เพิ่มกลุ่มสาระของฉัน'),
          ),
      ],
    );
  }
}

class _AddSubjectDialog extends StatefulWidget {
  const _AddSubjectDialog();

  @override
  State<_AddSubjectDialog> createState() => _AddSubjectDialogState();
}

class _AddSubjectDialogState extends State<_AddSubjectDialog> {
  final _name = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  void _submit() {
    final text = _name.text.trim();
    if (text.isEmpty) {
      setState(() => _error = 'กรอกชื่อกลุ่มสาระ');
      return;
    }
    Navigator.of(context).pop(text);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เพิ่มกลุ่มสาระของฉัน'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'ใช้เมื่อวิชาที่สอนไม่อยู่ใน 8 กลุ่มสาระ เช่น หน้าที่พลเมือง '
              'ลูกเสือ หรือวิชาเพิ่มเติม กลุ่มสาระนี้เห็นเฉพาะคุณ',
            ),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('subject_name'),
              controller: _name,
              autofocus: true,
              maxLength: 100,
              decoration: InputDecoration(
                labelText: 'ชื่อกลุ่มสาระ',
                errorText: _error,
              ),
              onSubmitted: (_) => _submit(),
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
          key: const ValueKey('subject_save'),
          onPressed: _submit,
          child: const Text('เพิ่ม'),
        ),
      ],
    );
  }
}
