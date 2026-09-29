import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'assignment.dart';
import 'assignments_providers.dart';

/// `/assignments/:id/edit`: uses the assignment handed over as route `extra`
/// when there is one, otherwise loads it by id so a deep link or a restored
/// route never silently opens the form in create mode (which would create a
/// duplicate on save).
class AssignmentEditScreen extends ConsumerWidget {
  const AssignmentEditScreen({
    super.key,
    required this.assignmentId,
    this.initial,
  });

  final int assignmentId;
  final Assignment? initial;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (initial case final a? when a.id == assignmentId) {
      return AssignmentFormScreen(existing: a);
    }
    final detail = ref.watch(assignmentDetailProvider(assignmentId));
    return detail.when(
      skipLoadingOnRefresh: true,
      data: (a) => AssignmentFormScreen(existing: a),
      loading: () => Scaffold(
        appBar: AppBar(title: const Text('แก้ไขการบ้าน')),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: const Text('แก้ไขการบ้าน')),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(assignmentDetailProvider(assignmentId)),
        ),
      ),
    );
  }
}

/// Create an assignment (classroom + subject fixed afterwards) or edit its
/// title, strictness and due date; the mode (worksheet or not, DESIGN
/// §19.5) while it is a draft without a layout, "รับงานส่งช้า" and
/// "เฉพาะคะแนน" (§21.7).
class AssignmentFormScreen extends ConsumerStatefulWidget {
  const AssignmentFormScreen({
    super.key,
    this.existing,
    this.initialClassroomId,
  });

  final Assignment? existing;
  final int? initialClassroomId;

  @override
  ConsumerState<AssignmentFormScreen> createState() =>
      _AssignmentFormScreenState();
}

class _AssignmentFormScreenState extends ConsumerState<AssignmentFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.existing?.title ?? '');
  late int? _classroomId =
      widget.existing?.classroomId ?? widget.initialClassroomId;
  late int? _subjectId = widget.existing?.subjectId;
  late Strictness _strictness =
      widget.existing?.strictness ?? Strictness.normal;
  late DateTime? _dueAt = widget.existing?.dueAt;
  late AssignmentMode _mode = widget.existing?.mode ?? AssignmentMode.worksheet;
  late bool _acceptLate = widget.existing?.acceptLate ?? true;
  late bool _scoreOnly = widget.existing?.scoreOnly ?? false;
  bool _busy = false;

  /// The server refuses a mode change once a layout was built or work was
  /// handed in (422 errors.mode); the app only offers it on a fresh draft.
  bool get _modeEditable {
    final a = widget.existing;
    return a == null || (a.isDraft && a.currentLayoutVersion == null);
  }

  String? _error;

  @override
  void dispose() {
    _title.dispose();
    super.dispose();
  }

  Future<void> _pickDue() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _dueAt ?? now,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now.add(const Duration(days: 365 * 2)),
    );
    if (picked != null) {
      // Due at end of day, local time; sent to the server as UTC.
      setState(
        () => _dueAt = DateTime(picked.year, picked.month, picked.day, 23, 59),
      );
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (widget.existing == null &&
        (_classroomId == null || _subjectId == null)) {
      setState(() => _error = 'เลือกห้องเรียนและวิชา');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (widget.existing case final a?) {
        await ref
            .read(assignmentDetailProvider(a.id).notifier)
            .edit(
              title: _title.text.trim(),
              strictness: _strictness,
              dueAt: _dueAt,
              clearDueAt: _dueAt == null && a.dueAt != null,
              mode: _mode != a.mode ? _mode : null,
              acceptLate: _acceptLate != a.acceptLate ? _acceptLate : null,
              scoreOnly: _scoreOnly != a.scoreOnly ? _scoreOnly : null,
            );
        if (!mounted) return;
        showMessage(context, 'บันทึกแล้ว');
        context.pop();
      } else {
        final created = await ref
            .read(assignmentsProvider.notifier)
            .create(
              classroomId: _classroomId!,
              subjectId: _subjectId!,
              title: _title.text.trim(),
              strictness: _strictness,
              dueAt: _dueAt,
              mode: _mode,
              acceptLate: _acceptLate,
              scoreOnly: _scoreOnly,
            );
        if (!mounted) return;
        showMessage(
          context,
          _mode == AssignmentMode.freeform
              ? 'สร้างการบ้านแล้ว เพิ่มเฉลยได้เลย'
              : 'สร้างการบ้านแล้ว เพิ่มคำถามได้เลย',
        );
        context.pushReplacement(AppRoutes.assignment(created.id));
      }
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.existing != null;
    final classrooms = ref.watch(classroomsProvider);
    final subjects = ref.watch(subjectsProvider);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(editing ? 'แก้ไขการบ้าน' : 'สร้างการบ้าน')),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            TextFormField(
              controller: _title,
              decoration: const InputDecoration(
                labelText: 'ชื่อการบ้าน',
                hintText: 'เช่น เศษส่วน ชุดที่ 3',
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อการบ้าน' : null,
            ),
            const SizedBox(height: 16),
            if (!editing) ...[
              DropdownButtonFormField<int>(
                initialValue: _classroomId,
                decoration: const InputDecoration(labelText: 'ห้องเรียน'),
                items: [
                  for (final c in classrooms.value ?? const [])
                    DropdownMenuItem(
                      value: c.id,
                      child: Text(
                        '${c.name} (${gradeLevelLabel(c.gradeLevel)})',
                      ),
                    ),
                ],
                onChanged: (v) => setState(() => _classroomId = v),
                validator: (v) => v == null ? 'เลือกห้องเรียน' : null,
              ),
              if (classrooms.hasError)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    'โหลดห้องเรียนไม่ได้: ${apiErrorMessage(classrooms.error!)}',
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                ),
              const SizedBox(height: 16),
              DropdownButtonFormField<int>(
                initialValue: _subjectId,
                decoration: const InputDecoration(labelText: 'วิชา'),
                items: [
                  for (final s in subjects.value ?? const [])
                    DropdownMenuItem(value: s.id, child: Text(s.name)),
                ],
                onChanged: (v) => setState(() => _subjectId = v),
                validator: (v) => v == null ? 'เลือกวิชา' : null,
              ),
              if (subjects.hasError)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    'โหลดรายวิชาไม่ได้: ${apiErrorMessage(subjects.error!)}',
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                ),
              const SizedBox(height: 16),
            ],
            Text('รูปแบบการบ้าน', style: theme.textTheme.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<AssignmentMode>(
              showSelectedIcon: false,
              segments: [
                for (final m in AssignmentMode.values)
                  ButtonSegment(value: m, label: Text(m.label)),
              ],
              selected: {_mode},
              onSelectionChanged: _modeEditable
                  ? (s) => setState(() => _mode = s.first)
                  : null,
            ),
            const SizedBox(height: 4),
            Text(
              _mode == AssignmentMode.freeform
                  ? 'นักเรียนทำในสมุดหรือใบงานของครูเอง ตรวจจากรูปทั้งหน้า '
                        'ไม่ต้องพิมพ์ใบงาน ต้องอนุมัติเฉลยก่อนเริ่มตรวจ'
                  : 'พิมพ์ใบงานของแอปที่มี QR และกรอบคำตอบ แล้วสแกนตรวจ',
              style: theme.textTheme.bodySmall,
            ),
            if (!_modeEditable)
              Text(
                'เปลี่ยนรูปแบบไม่ได้หลังสร้าง layout หรือมีงานส่งแล้ว',
                style: theme.textTheme.bodySmall,
              ),
            const SizedBox(height: 16),
            Text('ความเข้มงวดในการตรวจ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<Strictness>(
              segments: [
                for (final s in Strictness.values)
                  ButtonSegment(value: s, label: Text(s.label)),
              ],
              selected: {_strictness},
              onSelectionChanged: (s) => setState(() => _strictness = s.first),
            ),
            const SizedBox(height: 16),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event_outlined),
              title: Text(
                _dueAt == null
                    ? 'ไม่กำหนดวันส่ง'
                    : 'ส่งภายใน ${formatThaiDate(_dueAt!)}',
              ),
              trailing: _dueAt == null
                  ? null
                  : IconButton(
                      tooltip: 'ล้างวันส่ง',
                      icon: const Icon(Icons.clear),
                      onPressed: () => setState(() => _dueAt = null),
                    ),
              onTap: _pickDue,
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('รับงานส่งช้า'),
              subtitle: const Text(
                'งานที่ส่งหลังกำหนดยังตรวจได้ และมีป้าย "ส่งช้า"',
              ),
              value: _acceptLate,
              onChanged: (v) => setState(() => _acceptLate = v),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('เฉพาะคะแนน'),
              subtitle: const Text(
                'นักเรียนเห็นคะแนนและข้อความสำเร็จรูป '
                'ไม่ให้ AI เขียนคำอธิบาย ประหยัดค่าใช้จ่าย',
              ),
              value: _scoreOnly,
              onChanged: (v) => setState(() => _scoreOnly = v),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: Text(editing ? 'บันทึก' : 'สร้างการบ้าน'),
            ),
          ],
        ),
      ),
    );
  }
}
