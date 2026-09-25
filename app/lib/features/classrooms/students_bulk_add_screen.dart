import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'classrooms_providers.dart';
import 'roster_parser.dart';

/// Paste-a-list screen for `POST /classrooms/{id}/students`. After a
/// successful add it shows each new student's initial PIN once (the server
/// keeps only the hash, DESIGN §9.2) until the teacher leaves the screen.
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

  /// Set once the server accepted the list: the one-time PINs to show.
  List<EnrolledStudent>? _enrolled;

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
      final enrolled = await ref
          .read(rosterProvider(widget.classroomId).notifier)
          .addStudents(_parsed.students);
      if (!mounted) return;
      setState(() => _enrolled = enrolled);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Leaving the PIN list loses the PINs for good, so ask first.
  Future<void> _leavePins() async {
    final ok = await confirm(
      context,
      title: 'ออกจากหน้านี้?',
      message:
          'PIN จะไม่แสดงอีก ถ้ายังไม่ได้จด ต้องรีเซ็ต PIN ทีละคนจากหน้าห้องเรียน',
      confirmLabel: 'ออก',
    );
    if (ok && mounted) context.pop();
  }

  Future<void> _copyPins(List<EnrolledStudent> enrolled) async {
    final text = [
      'เลขที่\tชื่อ\tPIN',
      for (final s in enrolled) '${s.studentNumber}\t${s.name}\t${s.pin}',
    ].join('\n');
    await Clipboard.setData(ClipboardData(text: text));
    if (mounted) showMessage(context, 'คัดลอก PIN ${enrolled.length} คนแล้ว');
  }

  @override
  Widget build(BuildContext context) {
    final enrolled = _enrolled;
    if (enrolled != null) {
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) _leavePins();
        },
        child: _PinsView(
          enrolled: enrolled,
          onCopy: () => _copyPins(enrolled),
          onDone: () => context.pop(),
        ),
      );
    }
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

/// The one-time PIN list shown right after a successful bulk add.
class _PinsView extends StatelessWidget {
  const _PinsView({
    required this.enrolled,
    required this.onCopy,
    required this.onDone,
  });

  final List<EnrolledStudent> enrolled;
  final VoidCallback onCopy;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('เพิ่มนักเรียน ${enrolled.length} คนแล้ว')),
      body: FormColumn(
        maxWidth: 640,
        children: [
          Card(
            color: theme.colorScheme.tertiaryContainer,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Icon(
                    Icons.password_outlined,
                    color: theme.colorScheme.onTertiaryContainer,
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      'PIN เริ่มต้นของนักเรียนแสดงครั้งเดียว จดหรือคัดลอกไว้ก่อนออกจากหน้านี้ '
                      '(นักเรียนใช้ PIN คู่กับรหัสห้องและเลขที่ เมื่อไม่มีบัตร QR)',
                      style: TextStyle(
                        color: theme.colorScheme.onTertiaryContainer,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: Column(
              children: [
                for (final s in enrolled)
                  ListTile(
                    dense: true,
                    leading: Text('${s.studentNumber}'),
                    title: Text(s.name),
                    trailing: SelectableText(
                      s.pin,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontFamily: 'monospace',
                        letterSpacing: 2,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          OutlinedButton.icon(
            onPressed: onCopy,
            icon: const Icon(Icons.copy_all_outlined),
            label: const Text('คัดลอก PIN ทั้งหมด'),
          ),
          const SizedBox(height: 8),
          FilledButton.icon(
            onPressed: onDone,
            icon: const Icon(Icons.check),
            label: const Text('จด PIN แล้ว เสร็จสิ้น'),
          ),
        ],
      ),
    );
  }
}
