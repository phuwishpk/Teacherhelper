import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'classrooms_providers.dart';
import 'one_time_pins_view.dart';
import 'roster_parser.dart';
import 'school_student_search.dart';
import 'school_students.dart';

/// The two ways to add students (DESIGN §24.13 build 1).
enum StudentsAddMode {
  /// "เพิ่มนักเรียนใหม่": paste "เลขที่ ชื่อ [เลขประจำตัว]" lines.
  newStudents,

  /// "เลือกนักเรียนที่มีอยู่": search the school and pick (DESIGN §24.4).
  existing,
}

/// `POST /classrooms/{id}/students` with new students (pasted list) or
/// students the school already has (search and pick, one account per child,
/// DESIGN §24.4). After a successful add it shows the PINs that were issued
/// once (the server keeps only the hash, DESIGN §9.2) until the teacher
/// leaves the screen; existing students keep their PIN and QR card.
class StudentsBulkAddScreen extends ConsumerStatefulWidget {
  const StudentsBulkAddScreen({
    super.key,
    required this.classroomId,
    this.initialMode = StudentsAddMode.newStudents,
  });

  final int classroomId;
  final StudentsAddMode initialMode;

  @override
  ConsumerState<StudentsBulkAddScreen> createState() =>
      _StudentsBulkAddScreenState();
}

/// A picked existing student with the number they get in this room.
class _Pick {
  _Pick(this.student, int number)
    : number = TextEditingController(text: '$number');

  final SchoolStudent student;
  final TextEditingController number;
  bool reissuePin = false;
}

class _StudentsBulkAddScreenState extends ConsumerState<StudentsBulkAddScreen> {
  final _text = TextEditingController();
  RosterParseResult _parsed = const RosterParseResult([], []);
  late StudentsAddMode _mode = widget.initialMode;
  final _picks = <_Pick>[];
  bool _busy = false;
  String? _error;
  List<String> _errorDetails = const [];

  /// 422 `student_code_taken`: the student who holds the code and the
  /// number the pasted row asked for, offered as "เพิ่มคนนี้เข้าห้องแทน".
  (SchoolStudent, int)? _codeHolder;

  /// Set once the server accepted the list: the rows to show.
  List<EnrolledStudent>? _enrolled;

  @override
  void dispose() {
    _text.dispose();
    for (final p in _picks) {
      p.number.dispose();
    }
    super.dispose();
  }

  void _reparse() => setState(() => _parsed = parseRosterLines(_text.text));

  /// The next free number after the roster and the picks.
  int _nextNumber() {
    final roster =
        ref.read(rosterProvider(widget.classroomId)).value ?? const [];
    var max = 0;
    for (final s in roster) {
      if (s.studentNumber > max) max = s.studentNumber;
    }
    for (final p in _picks) {
      final n = int.tryParse(p.number.text.trim()) ?? 0;
      if (n > max) max = n;
    }
    return max + 1;
  }

  void _pick(SchoolStudent student, {int? number}) {
    setState(() => _picks.add(_Pick(student, number ?? _nextNumber())));
  }

  void _unpick(_Pick pick) {
    setState(() => _picks.remove(pick));
    pick.number.dispose();
  }

  void _clearError() {
    _error = null;
    _errorDetails = const [];
    _codeHolder = null;
  }

  /// The rows to send, or null with [_error] set.
  List<StudentEnrolment>? _rows() {
    if (_mode == StudentsAddMode.newStudents) {
      return _parsed.ok ? _parsed.students : null;
    }
    final rows = <StudentEnrolment>[];
    final seen = <int>{};
    for (final p in _picks) {
      final n = int.tryParse(p.number.text.trim());
      if (n == null || n < 1 || n > 255) {
        _error = 'เลขที่ของ ${p.student.name} ต้องอยู่ระหว่าง 1–255';
        return null;
      }
      if (!seen.add(n)) {
        _error = 'เลขที่ $n ซ้ำกันในรายการ';
        return null;
      }
      rows.add(
        ExistingStudentEnrolment(
          studentId: p.student.id,
          studentNumber: n,
          reissuePin: p.reissuePin,
        ),
      );
    }
    return rows.isEmpty ? null : rows;
  }

  Future<void> _submit() async {
    setState(_clearError);
    final rows = _rows();
    if (rows == null) {
      setState(() {});
      return;
    }
    setState(() => _busy = true);
    try {
      final enrolled = await ref
          .read(rosterProvider(widget.classroomId).notifier)
          .addStudents(rows);
      if (!mounted) return;
      if (enrolled.any((e) => e.hasPin)) {
        setState(() => _enrolled = enrolled);
      } else {
        final messenger = ScaffoldMessenger.of(context);
        context.pop();
        messenger
          ..hideCurrentSnackBar()
          ..showSnackBar(
            SnackBar(
              content: Text(
                'เพิ่มนักเรียน ${enrolled.length} คนแล้ว ใช้ PIN และบัตร QR เดิมได้',
              ),
            ),
          );
      }
    } catch (e) {
      if (mounted) setState(() => _showError(e, rows));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _showError(Object e, List<StudentEnrolment> rows) {
    _error = apiErrorMessage(e);
    _errorDetails = [
      for (final m in apiErrorMessages(e))
        if (m != _error) m,
    ];
    final body = apiErrorBody(e);
    final holder = body?['existing_student'];
    if (apiErrorCode(e) != 'student_code_taken' || holder is! Map) return;
    // errors.students.{i}.student_code names the pasted row (DESIGN §24.18).
    final errors = body?['errors'];
    final key = errors is Map
        ? errors.keys
              .map((k) => '$k')
              .firstWhere(
                (k) => RegExp(r'^students\.\d+\.student_code$').hasMatch(k),
                orElse: () => '',
              )
        : '';
    final index = int.tryParse(key.split('.').elementAtOrNull(1) ?? '');
    if (index == null || index >= rows.length) return;
    _codeHolder = (
      SchoolStudent(
        id: (holder['id'] as num).toInt(),
        name: holder['name'] as String? ?? '',
      ),
      rows[index].studentNumber,
    );
  }

  /// "เพิ่มคนนี้เข้าห้องแทน": the pasted row leaves the list and the
  /// student who holds the code is picked with the same number.
  void _useCodeHolder() {
    final (student, number) = _codeHolder!;
    final rest = [
      for (final s in _parsed.students)
        if (s.studentNumber != number) s,
    ];
    _text.text = [
      for (final s in rest)
        '${s.studentNumber} ${s.name}'
            '${s.studentCode == null ? '' : '\t${s.studentCode}'}',
    ].join('\n');
    setState(() {
      _parsed = parseRosterLines(_text.text);
      _clearError();
      _mode = StudentsAddMode.existing;
      if (!_picks.any((p) => p.student.id == student.id)) {
        _picks.add(_Pick(student, number));
      }
    });
  }

  /// Leaving the PIN list loses the PINs for good, so ask first.
  Future<void> _leavePins() async {
    final ok = await confirmLeavePins(context);
    if (ok && mounted) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    final enrolled = _enrolled;
    if (enrolled != null) {
      final withPins = [
        for (final e in enrolled)
          if (e.hasPin) e,
      ];
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) _leavePins();
        },
        child: OneTimePinsView(
          title: 'เพิ่มนักเรียน ${enrolled.length} คนแล้ว',
          enrolled: withPins,
          onCopy: () => copyPins(context, withPins),
          onDone: () => context.pop(),
        ),
      );
    }
    // Loads the roster for the next free number of a pick.
    ref.watch(rosterProvider(widget.classroomId));
    final theme = Theme.of(context);
    final existing = _mode == StudentsAddMode.existing;
    final canSubmit = !_busy && (existing ? _picks.isNotEmpty : _parsed.ok);
    return Scaffold(
      appBar: AppBar(title: const Text('เพิ่มนักเรียน')),
      body: FormColumn(
        maxWidth: 640,
        children: [
          SegmentedButton<StudentsAddMode>(
            key: const ValueKey('students_add_mode'),
            segments: const [
              ButtonSegment(
                value: StudentsAddMode.newStudents,
                icon: Icon(Icons.person_add_alt),
                label: Text('เพิ่มนักเรียนใหม่'),
              ),
              ButtonSegment(
                value: StudentsAddMode.existing,
                icon: Icon(Icons.person_search_outlined),
                label: Text('เลือกนักเรียนที่มีอยู่'),
              ),
            ],
            selected: {_mode},
            onSelectionChanged: (s) => setState(() {
              _mode = s.single;
              _clearError();
            }),
          ),
          const SizedBox(height: 16),
          if (existing) ..._existingForm(theme) else ..._newForm(theme),
          if (_error != null) ...[
            const SizedBox(height: 12),
            _ErrorCard(
              message: _error!,
              details: _errorDetails,
              action: _codeHolder == null
                  ? null
                  : FilledButton.tonalIcon(
                      key: const ValueKey('use_code_holder'),
                      onPressed: _useCodeHolder,
                      icon: const Icon(Icons.person_search_outlined),
                      label: Text('เพิ่ม ${_codeHolder!.$1.name} เข้าห้องแทน'),
                    ),
            ),
          ],
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: canSubmit ? _submit : null,
            icon: const Icon(Icons.group_add_outlined),
            label: const Text('เพิ่มนักเรียน'),
          ),
        ],
      ),
    );
  }

  List<Widget> _newForm(ThemeData theme) => [
    Text(
      'วางรายชื่อบรรทัดละคน ขึ้นต้นด้วยเลขที่ ตามด้วยชื่อ และเลขประจำตัวนักเรียน (ถ้ามี) '
      'นักเรียนที่มีบัญชีในโรงเรียนอยู่แล้วให้ใช้ "เลือกนักเรียนที่มีอยู่" เพื่อไม่ให้มีบัญชีซ้ำ',
      style: theme.textTheme.bodyMedium,
    ),
    const SizedBox(height: 12),
    TextField(
      controller: _text,
      minLines: 8,
      maxLines: 16,
      keyboardType: TextInputType.multiline,
      decoration: const InputDecoration(
        hintText: '1 ด.ช. สมชาย ใจดี 65001\n2 ด.ญ. สมหญิง รักเรียน',
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
                  style: TextStyle(color: theme.colorScheme.onErrorContainer),
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
                subtitle: s.studentCode == null
                    ? null
                    : Text('เลขประจำตัว ${s.studentCode}'),
              ),
          ],
        ),
      ),
    ],
  ];

  List<Widget> _existingForm(ThemeData theme) => [
    Text(
      'นักเรียนที่มีบัญชีในโรงเรียนแล้วใช้ PIN และบัตร QR เดิมได้ทุกห้อง '
      'ประวัติและคะแนนต่อเนื่องในบัญชีเดียว',
      style: theme.textTheme.bodyMedium,
    ),
    const SizedBox(height: 12),
    SchoolStudentSearch(
      onPick: _pick,
      unavailable: (s) {
        if (s.isIn(widget.classroomId)) return 'อยู่ในห้องนี้แล้ว';
        if (_picks.any((p) => p.student.id == s.id)) return 'เลือกแล้ว';
        return null;
      },
    ),
    if (_picks.isNotEmpty) ...[
      const SizedBox(height: 16),
      Text('จะเพิ่ม ${_picks.length} คน', style: theme.textTheme.titleSmall),
      const SizedBox(height: 4),
      for (final p in _picks) _pickCard(theme, p),
    ],
  ];

  Widget _pickCard(ThemeData theme, _Pick p) => Card(
    key: ValueKey('pick_${p.student.id}'),
    child: Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(p.student.name, style: theme.textTheme.titleSmall),
                    if (p.student.classrooms.isNotEmpty ||
                        p.student.studentCode != null)
                      Text(p.student.details, style: theme.textTheme.bodySmall),
                  ],
                ),
              ),
              SizedBox(
                width: 84,
                child: TextField(
                  key: ValueKey('pick_number_${p.student.id}'),
                  controller: p.number,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'เลขที่'),
                ),
              ),
              IconButton(
                tooltip: 'เอาออกจากรายการ',
                icon: const Icon(Icons.close),
                onPressed: () => _unpick(p),
              ),
            ],
          ),
          CheckboxListTile(
            key: ValueKey('pick_reissue_${p.student.id}'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            controlAffinity: ListTileControlAffinity.leading,
            value: p.reissuePin,
            onChanged: (v) => setState(() => p.reissuePin = v ?? false),
            title: const Text('ออก PIN ใหม่ (PIN เดิมใช้ไม่ได้)'),
          ),
        ],
      ),
    ),
  );
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard({
    required this.message,
    this.details = const [],
    this.action,
  });

  final String message;
  final List<String> details;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final style = TextStyle(color: scheme.onErrorContainer);
    return Card(
      key: const ValueKey('students_add_error'),
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(message, style: style),
            for (final d in details) Text('• $d', style: style),
            if (action != null) ...[const SizedBox(height: 8), action!],
          ],
        ),
      ),
    );
  }
}
