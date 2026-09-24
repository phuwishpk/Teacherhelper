import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'classrooms_providers.dart';
import 'roster_parser.dart';

/// Paste-a-list screen for `POST /classrooms/{id}/students`.
class StudentsBulkAddScreen extends ConsumerStatefulWidget {
  const StudentsBulkAddScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  ConsumerState<StudentsBulkAddScreen> createState() =>
      _StudentsBulkAddScreenState();
}

class _StudentsBulkAddScreenState extends ConsumerState<StudentsBulkAddScreen> {
  final _text = TextEditingController();
  RosterParseResult _parsed = const RosterParseResult([], []);
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _reparse() => setState(() => _parsed = parseRosterLines(_text.text));

  Future<void> _submit() async {
    if (!_parsed.ok) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(rosterProvider(widget.classroomId).notifier)
          .addStudents(_parsed.students);
      if (!mounted) return;
      showMessage(context, 'เพิ่มนักเรียน ${_parsed.students.length} คนแล้ว');
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เพิ่มนักเรียน')),
      body: FormColumn(
        maxWidth: 640,
        children: [
          Text(
            'วางรายชื่อบรรทัดละคน ขึ้นต้นด้วยเลขที่ แล้วตามด้วยชื่อ',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _text,
            minLines: 8,
            maxLines: 16,
            keyboardType: TextInputType.multiline,
            decoration: const InputDecoration(
              hintText: '1 ด.ช. สมชาย ใจดี\n2 ด.ญ. สมหญิง รักเรียน',
              alignLabelWithHint: true,
            ),
            onChanged: (_) => _reparse(),
          ),
          const SizedBox(height: 12),
          if (_parsed.errors.isNotEmpty)
            Card(
              color: theme.colorScheme.errorContainer,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final e in _parsed.errors)
                      Text(
                        'บรรทัด ${e.lineNumber}: ${e.message}',
                        style: TextStyle(
                          color: theme.colorScheme.onErrorContainer,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          if (_parsed.students.isNotEmpty) ...[
            Text(
              'จะเพิ่ม ${_parsed.students.length} คน',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 4),
            Card(
              child: Column(
                children: [
                  for (final s in _parsed.students)
                    ListTile(
                      dense: true,
                      leading: Text('${s.studentNumber}'),
                      title: Text(s.name),
                    ),
                ],
              ),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          ],
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: _busy || !_parsed.ok ? null : _submit,
            icon: const Icon(Icons.group_add_outlined),
            label: const Text('เพิ่มนักเรียน'),
          ),
        ],
      ),
    );
  }
}
