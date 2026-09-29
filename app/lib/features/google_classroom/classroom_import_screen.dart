import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../classrooms/one_time_pins_view.dart';
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// Highest student number the server accepts on import
/// (`ClassroomImporter::MAX_STUDENT_NUMBER`).
const maxImportStudentNumber = 255;

/// Preview of importing one Google Classroom course as a new room (DESIGN
/// §19.2): name, grade, year and the numbered students, which the teacher
/// may renumber or take out before "สร้างห้อง". The one-time PINs follow,
/// then the screen pops with the new classroom's id.
class ClassroomImportScreen extends ConsumerWidget {
  const ClassroomImportScreen({super.key, required this.courseId});

  final String courseId;

  static const _title = 'นำเข้าจาก Google Classroom';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.listen(googleImportPreviewProvider(courseId), (_, next) {
      if (next case AsyncError(
        :final error,
      ) when isGoogleReconnectError(error)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
    });
    return switch (ref.watch(googleImportPreviewProvider(courseId))) {
      AsyncData(:final value) => _ImportForm(
        key: ValueKey(courseId),
        preview: value,
      ),
      AsyncError(:final error) => Scaffold(
        appBar: AppBar(title: const Text(_title)),
        body: ErrorView(
          message: googleErrorMessage(error),
          onRetry: apiErrorCode(error) == 'course_already_linked'
              ? null
              : () => ref.invalidate(googleImportPreviewProvider(courseId)),
        ),
      ),
      _ => Scaffold(
        appBar: AppBar(title: const Text(_title)),
        body: const Center(child: CircularProgressIndicator()),
      ),
    };
  }
}

class _ImportForm extends ConsumerStatefulWidget {
  const _ImportForm({super.key, required this.preview});

  final ClassroomImportPreview preview;

  @override
  ConsumerState<_ImportForm> createState() => _ImportFormState();
}

class _ImportFormState extends ConsumerState<_ImportForm> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.preview.suggestedName);
  late final _year = TextEditingController(
    text: '${widget.preview.academicYear}',
  );
  late int? _grade = widget.preview.gradeLevelGuess;

  /// Student number fields by Google user id, in the proposed order.
  late final Map<String, TextEditingController> _numbers = {
    for (final s in widget.preview.students)
      s.googleUserId: TextEditingController(text: '${s.proposedNumber}'),
  };

  /// Accounts taken out (test accounts, parents): not created, and kept
  /// out of later roster syncs.
  final _removed = <String>{};

  bool _busy = false;
  String? _error;
  ClassroomImportResult? _result;

  List<ImportPreviewStudent> get _kept => [
    for (final s in widget.preview.students)
      if (!_removed.contains(s.googleUserId)) s,
  ];

  @override
  void dispose() {
    _name.dispose();
    _year.dispose();
    for (final c in _numbers.values) {
      c.dispose();
    }
    super.dispose();
  }

  int? _numberOf(String googleUserId) {
    final n = int.tryParse(_numbers[googleUserId]!.text.trim());
    return n != null && n >= 1 && n <= maxImportStudentNumber ? n : null;
  }

  /// Why a kept student's number cannot be used, by Google user id.
  Map<String, String> _numberErrors() {
    final kept = _kept;
    final counts = <int, int>{};
    for (final s in kept) {
      if (_numberOf(s.googleUserId) case final n?) {
        counts[n] = (counts[n] ?? 0) + 1;
      }
    }
    return {
      for (final s in kept)
        if (_numberOf(s.googleUserId) case final n)
          if (n == null)
            s.googleUserId: 'เลขที่ต้องเป็น 1–$maxImportStudentNumber'
          else if (counts[n]! > 1)
            s.googleUserId: 'เลขที่ $n ซ้ำกับคนอื่น',
    };
  }

  void _remove(String googleUserId) =>
      setState(() => _removed.add(googleUserId));

  void _restore(String googleUserId) =>
      setState(() => _removed.remove(googleUserId));

  /// Numbers the kept students 1..N, keeping the order of their current
  /// numbers (closes the gaps left by removed accounts).
  void _renumber() {
    final order = widget.preview.students;
    final kept = _kept
      ..sort((a, b) {
        final na = _numberOf(a.googleUserId) ?? maxImportStudentNumber + 1;
        final nb = _numberOf(b.googleUserId) ?? maxImportStudentNumber + 1;
        return na != nb
            ? na.compareTo(nb)
            : order.indexOf(a) - order.indexOf(b);
      });
    setState(() {
      for (var i = 0; i < kept.length; i++) {
        _numbers[kept[i].googleUserId]!.text = '${i + 1}';
      }
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || _numberErrors().isNotEmpty) {
      return;
    }
    final preview = widget.preview;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(googleClassroomRepositoryProvider)
          .importClassroom(
            ClassroomImportRequest(
              courseId: preview.courseId,
              name: _name.text.trim(),
              gradeLevel: _grade!,
              academicYear: int.parse(_year.text.trim()),
              numbers: {
                for (final s in _kept)
                  s.googleUserId: _numberOf(s.googleUserId)!,
              },
              removed: [
                for (final s in preview.students)
                  if (_removed.contains(s.googleUserId)) s.googleUserId,
              ],
            ),
          );
      ref.read(classroomsProvider.notifier).addCreated(result.classroom);
      if (!mounted) return;
      if (result.students.isEmpty) {
        showMessage(context, 'สร้างห้อง ${result.classroom.name} แล้ว');
        context.pop(result.classroom.id);
        return;
      }
      setState(() => _result = result);
    } catch (e) {
      if (isGoogleReconnectError(e)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
      if (apiErrorCode(e) == 'course_already_linked') {
        ref.invalidate(googleCoursesProvider);
      }
      if (mounted) setState(() => _error = googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _leavePins(int classroomId) async {
    final ok = await confirmLeavePins(context);
    if (ok && mounted) context.pop(classroomId);
  }

  @override
  Widget build(BuildContext context) {
    if (_result case final result?) {
      final id = result.classroom.id;
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) _leavePins(id);
        },
        child: OneTimePinsView(
          title: 'สร้างห้อง ${result.classroom.name} แล้ว',
          enrolled: result.students,
          onCopy: () => copyPins(context, result.students),
          onDone: () => context.pop(id),
        ),
      );
    }

    final theme = Theme.of(context);
    final preview = widget.preview;
    final kept = _kept;
    final errors = _numberErrors();
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Scaffold(
      appBar: AppBar(title: const Text(ClassroomImportScreen._title)),
      body: Form(
        key: _formKey,
        child: FormColumn(
          maxWidth: 640,
          children: [
            Card(
              color: theme.colorScheme.secondaryContainer,
              child: ListTile(
                leading: const Icon(Icons.school_outlined),
                title: Text([preview.name, ?preview.section].join(' · ')),
                subtitle: const Text(
                  'ชื่อและอีเมลของนักเรียนมาจาก Google Classroom '
                  'บัญชีที่เอาออกจะไม่ถูกเพิ่มกลับตอนซิงก์รายชื่อ',
                ),
              ),
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('import_name'),
              controller: _name,
              maxLength: 100,
              decoration: const InputDecoration(labelText: 'ชื่อห้อง'),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อห้อง' : null,
            ),
            const SizedBox(height: 8),
            DropdownButtonFormField<int>(
              key: const ValueKey('import_grade'),
              initialValue: _grade,
              hint: const Text('เลือกระดับชั้น'),
              decoration: InputDecoration(
                labelText: 'ระดับชั้น',
                helperText: preview.gradeLevelGuess == null
                    ? 'เดาระดับชั้นจากชื่อคอร์สไม่ได้ กรุณาเลือก'
                    : null,
              ),
              items: [
                for (var level = 1; level <= 12; level++)
                  DropdownMenuItem(
                    value: level,
                    child: Text(gradeLevelLabel(level)),
                  ),
              ],
              validator: (v) => v == null ? 'เลือกระดับชั้น' : null,
              onChanged: (v) => setState(() => _grade = v),
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('import_year'),
              controller: _year,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'ปีการศึกษา (พ.ศ.)'),
              validator: (v) {
                final n = int.tryParse(v?.trim() ?? '');
                if (n == null || n < 2500 || n > 2700) {
                  return 'กรอกปี พ.ศ. เช่น ${currentThaiYear()}';
                }
                return null;
              },
            ),
            const SizedBox(height: 24),
            Row(
              children: [
                Expanded(
                  child: Text(
                    'นักเรียน ${kept.length} คน',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (kept.isNotEmpty)
                  TextButton.icon(
                    key: const ValueKey('import_renumber'),
                    onPressed: _busy ? null : _renumber,
                    icon: const Icon(Icons.format_list_numbered),
                    label: const Text('เรียงเลขที่ใหม่'),
                  ),
              ],
            ),
            Text(
              'เลขที่เรียงตามชื่อ (ไม่นับคำนำหน้า) แก้เลขที่ได้ '
              'และกด "เอาออก" สำหรับบัญชีที่ไม่ใช่นักเรียน เช่น บัญชีทดสอบ',
              style: muted,
            ),
            const SizedBox(height: 8),
            if (kept.isEmpty)
              const Card(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Text(
                    'ไม่มีนักเรียนที่จะเพิ่ม สร้างห้องได้ แล้วค่อยกด "ซิงก์รายชื่อ" '
                    'เมื่อนักเรียนเข้าคอร์สแล้ว',
                    textAlign: TextAlign.center,
                  ),
                ),
              )
            else
              Card(
                clipBehavior: Clip.antiAlias,
                child: Column(
                  children: [
                    for (final s in kept) ...[
                      _StudentRow(
                        student: s,
                        controller: _numbers[s.googleUserId]!,
                        error: errors[s.googleUserId],
                        enabled: !_busy,
                        onChanged: () => setState(() {}),
                        onRemove: () => _remove(s.googleUserId),
                      ),
                      if (s != kept.last) const Divider(height: 1),
                    ],
                  ],
                ),
              ),
            if (_removed.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(
                'เอาออกแล้ว ${_removed.length} บัญชี',
                style: theme.textTheme.titleSmall,
              ),
              const SizedBox(height: 4),
              Card(
                child: Column(
                  children: [
                    for (final s in preview.students)
                      if (_removed.contains(s.googleUserId))
                        ListTile(
                          dense: true,
                          leading: const Icon(Icons.person_off_outlined),
                          title: Text(s.name),
                          subtitle: s.email == null ? null : Text(s.email!),
                          trailing: TextButton(
                            key: ValueKey('import_restore_${s.googleUserId}'),
                            onPressed: _busy
                                ? null
                                : () => _restore(s.googleUserId),
                            child: const Text('นำกลับ'),
                          ),
                        ),
                  ],
                ),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 16),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            if (errors.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(
                'แก้เลขที่ที่ซ้ำหรือไม่ถูกต้องก่อนสร้างห้อง',
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton.icon(
              key: const ValueKey('import_create'),
              onPressed: _busy || errors.isNotEmpty ? null : _submit,
              icon: _busy
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.add_home_work_outlined),
              label: const Text('สร้างห้อง'),
            ),
          ],
        ),
      ),
    );
  }
}

class _StudentRow extends StatelessWidget {
  const _StudentRow({
    required this.student,
    required this.controller,
    required this.error,
    required this.enabled,
    required this.onChanged,
    required this.onRemove,
  });

  final ImportPreviewStudent student;
  final TextEditingController controller;
  final String? error;
  final bool enabled;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
      child: Row(
        children: [
          SizedBox(
            width: 64,
            child: TextField(
              key: ValueKey('import_number_${student.googleUserId}'),
              controller: controller,
              enabled: enabled,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.center,
              inputFormatters: [
                FilteringTextInputFormatter.digitsOnly,
                LengthLimitingTextInputFormatter(3),
              ],
              decoration: InputDecoration(
                isDense: true,
                labelText: 'เลขที่',
                // Red outline only; the message is under the name.
                error: error == null ? null : const SizedBox.shrink(),
              ),
              onChanged: (_) => onChanged(),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(student.name),
                if (student.email case final email?)
                  Text(
                    email,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                if (error case final message?)
                  Text(
                    message,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
              ],
            ),
          ),
          IconButton(
            key: ValueKey('import_remove_${student.googleUserId}'),
            tooltip: 'เอาออก',
            onPressed: enabled ? onRemove : null,
            icon: const Icon(Icons.person_remove_outlined),
          ),
        ],
      ),
    );
  }
}
