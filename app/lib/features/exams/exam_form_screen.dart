import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_providers.dart';
import '../classrooms/classrooms_providers.dart';
import '../courses/course_picker.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exams_repository.dart';

/// `/exams/:id/edit`: the settings form of a loaded exam.
class ExamEditScreen extends ConsumerWidget {
  const ExamEditScreen({super.key, required this.examId});

  final int examId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(examDetailProvider(examId));
    return detail.when(
      skipLoadingOnRefresh: true,
      data: (d) => ExamFormScreen(existing: d.exam),
      loading: () => Scaffold(
        appBar: AppBar(title: const Text('ตั้งค่าข้อสอบ')),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: const Text('ตั้งค่าข้อสอบ')),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(examDetailProvider(examId)),
        ),
      ),
    );
  }
}

/// Create an exam (DESIGN §22.1, §22.2): classroom and course, title, the
/// exam date (required, the gradebook counts from it), its length, how it
/// is graded (by the app or by the teacher, who then gives the full
/// marks), 1–4 shuffled versions and "ให้นักเรียนดูเฉลย". Editing changes
/// the same settings; the classroom stays.
class ExamFormScreen extends ConsumerStatefulWidget {
  const ExamFormScreen({super.key, this.existing, this.initialClassroomId});

  final Assignment? existing;
  final int? initialClassroomId;

  @override
  ConsumerState<ExamFormScreen> createState() => _ExamFormScreenState();
}

class _ExamFormScreenState extends ConsumerState<ExamFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.existing?.title);
  late final _duration = TextEditingController(
    text: widget.existing?.durationMinutes?.toString() ?? '',
  );
  late final _fullMarks = TextEditingController(
    text: widget.existing?.manualFullMarks == null
        ? ''
        : formatPoints(widget.existing!.manualFullMarks!),
  );
  late int? _classroomId =
      widget.existing?.classroomId ?? widget.initialClassroomId;
  late int? _courseId = widget.existing?.courseId;
  late int? _lessonPlanId = widget.existing?.lessonPlanId;
  late DateTime? _examDate = widget.existing?.dueAt?.toLocal();
  late ExamGradingMethod _method = ExamGradingMethod.fromApi(
    widget.existing?.gradingMethod,
  );
  late int _versions = widget.existing?.versionCount ?? 1;
  late bool _showKey = widget.existing?.showKeyToStudents ?? false;
  bool _busy = false;
  String? _error;
  bool _dateMissing = false;

  bool get _editing => widget.existing != null;

  /// Version count is structural: fixed once printed (§22.2).
  bool get _versionsLocked => widget.existing?.structureLockedAt != null;

  @override
  void dispose() {
    _title.dispose();
    _duration.dispose();
    _fullMarks.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _examDate ?? now,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now.add(const Duration(days: 365 * 2)),
      helpText: 'เลือกวันสอบ',
    );
    if (picked != null) {
      // The end of the exam day, local time; sent as UTC.
      setState(() {
        _examDate = DateTime(picked.year, picked.month, picked.day, 23, 59);
        _dateMissing = false;
      });
    }
  }

  ExamSettingsDraft _draft() => ExamSettingsDraft(
    title: _title.text.trim(),
    examDate: _examDate!,
    classroomId: _classroomId,
    courseId: _courseId,
    lessonPlanId: _lessonPlanId,
    durationMinutes: int.tryParse(_duration.text.trim()),
    gradingMethod: _method,
    versionCount: _versions,
    showKeyToStudents: _showKey,
    manualFullMarks: _method == ExamGradingMethod.manual
        ? double.tryParse(_fullMarks.text.trim())
        : null,
  );

  Future<void> _submit() async {
    final valid = _formKey.currentState!.validate();
    setState(() => _dateMissing = _examDate == null);
    if (!valid || _examDate == null) return;
    if (!_editing && (_classroomId == null || _courseId == null)) {
      setState(() => _error = 'เลือกห้องเรียนและรายวิชา');
      return;
    }
    final existing = widget.existing;
    if (existing != null &&
        existing.isManualExam &&
        _method == ExamGradingMethod.app) {
      final ok = await confirm(
        context,
        title: 'เปลี่ยนเป็นตรวจด้วยแอป?',
        message:
            'คะแนนที่กรอกเองในสมุดคะแนนของข้อสอบนี้จะถูกลบ '
            'และต้องอนุมัติเฉลยก่อนพิมพ์กระดาษคำตอบ',
        confirmLabel: 'เปลี่ยน',
      );
      if (!ok || !mounted) return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (existing != null) {
        final changes = _draft().toUpdateJson(existing);
        if (changes.isNotEmpty) {
          await ref
              .read(examDetailProvider(existing.id).notifier)
              .updateSettings(changes);
        }
        if (!mounted) return;
        showMessage(context, 'บันทึกแล้ว');
        context.pop();
      } else {
        final created = await ref
            .read(examsRepositoryProvider)
            .create(_draft());
        ref.invalidate(assignmentsProvider);
        if (!mounted) return;
        showMessage(context, 'สร้างข้อสอบแล้ว เพิ่มตอนและข้อได้เลย');
        context.pushReplacement(AppRoutes.exam(created.id));
      }
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final classrooms = ref.watch(classroomsProvider);
    final classroomId = _classroomId;
    final courseId = _courseId;
    final maxVersions = kExamMaxVersions;

    return Scaffold(
      appBar: AppBar(title: Text(_editing ? 'ตั้งค่าข้อสอบ' : 'สร้างข้อสอบ')),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            TextFormField(
              key: const ValueKey('exam_title'),
              controller: _title,
              decoration: const InputDecoration(
                labelText: 'ชื่อข้อสอบ',
                hintText: 'เช่น สอบกลางภาค คณิตศาสตร์',
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อข้อสอบ' : null,
            ),
            const SizedBox(height: 16),
            if (!_editing) ...[
              DropdownButtonFormField<int>(
                key: const ValueKey('exam_classroom'),
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
                onChanged: (v) => setState(() {
                  _classroomId = v;
                  _courseId = null;
                  _lessonPlanId = null;
                }),
                validator: (v) => v == null ? 'เลือกห้องเรียน' : null,
              ),
              const SizedBox(height: 16),
            ],
            if (classroomId != null) ...[
              ClassroomCourseField(
                classroomId: classroomId,
                value: courseId,
                isRequired: true,
                onChanged: (c) => setState(() {
                  if (c?.id != _courseId) _lessonPlanId = null;
                  _courseId = c?.id;
                }),
              ),
              const SizedBox(height: 16),
            ],
            if (courseId != null) ...[
              LessonPlanField(
                courseId: courseId,
                value: _lessonPlanId,
                onChanged: (v) => setState(() => _lessonPlanId = v),
              ),
              const SizedBox(height: 16),
            ],
            ListTile(
              key: const ValueKey('exam_date'),
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event_outlined),
              title: Text(
                _examDate == null
                    ? 'เลือกวันสอบ'
                    : 'วันสอบ ${formatThaiDate(_examDate!)}',
              ),
              subtitle: Text(
                _dateMissing
                    ? 'ข้อสอบต้องกำหนดวันสอบ'
                    : 'สมุดคะแนนนับคะแนนที่ยังว่างเป็น 0 หลังวันสอบ',
                style: _dateMissing
                    ? TextStyle(color: theme.colorScheme.error)
                    : null,
              ),
              onTap: _pickDate,
            ),
            TextFormField(
              key: const ValueKey('exam_duration'),
              controller: _duration,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(
                labelText: 'เวลาสอบ (นาที)',
                hintText: 'ไม่บังคับ พิมพ์บนปกเล่มข้อสอบ',
              ),
              validator: (v) {
                final t = v?.trim() ?? '';
                if (t.isEmpty) return null;
                final n = int.tryParse(t);
                return n == null || n < 1 || n > kExamMaxDurationMinutes
                    ? 'เวลาสอบ 1–$kExamMaxDurationMinutes นาที'
                    : null;
              },
            ),
            const SizedBox(height: 16),
            Text('วิธีตรวจ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<ExamGradingMethod>(
              key: const ValueKey('exam_method'),
              showSelectedIcon: false,
              segments: [
                for (final m in ExamGradingMethod.values)
                  ButtonSegment(value: m, label: Text(m.label)),
              ],
              selected: {_method},
              onSelectionChanged: (s) => setState(() => _method = s.first),
            ),
            const SizedBox(height: 4),
            Text(
              _method == ExamGradingMethod.app
                  ? 'พิมพ์กระดาษคำตอบให้นักเรียนฝน แล้วสแกนด้วยมือถือ '
                        'แอปให้คะแนนทันที ต้องอนุมัติเฉลยก่อนพิมพ์'
                  : 'ครูตรวจเองนอกแอปแล้วกรอกคะแนนรวมในสมุดคะแนน '
                        'พิมพ์เล่มข้อสอบได้ ไม่มีกระดาษคำตอบ ไม่ต้องอนุมัติเฉลย',
              style: theme.textTheme.bodySmall,
            ),
            if (_method == ExamGradingMethod.manual) ...[
              const SizedBox(height: 12),
              TextFormField(
                key: const ValueKey('exam_full_marks'),
                controller: _fullMarks,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(labelText: 'คะแนนเต็ม'),
                validator: (v) {
                  final n = double.tryParse(v?.trim() ?? '');
                  return n == null || n <= 0 || n > 9999.99
                      ? 'กรอกคะแนนเต็ม (มากกว่า 0)'
                      : null;
                },
              ),
            ],
            const SizedBox(height: 16),
            Text('จำนวนชุดข้อสอบ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<int>(
              key: const ValueKey('exam_versions'),
              showSelectedIcon: false,
              segments: [
                for (var n = 1; n <= maxVersions; n++)
                  ButtonSegment(value: n, label: Text('$n ชุด')),
              ],
              selected: {_versions},
              onSelectionChanged: _versionsLocked
                  ? null
                  : (s) => setState(() => _versions = s.first),
            ),
            const SizedBox(height: 4),
            Text(
              _versionsLocked
                  ? 'พิมพ์แล้ว เปลี่ยนจำนวนชุดต้องปลดล็อกโครงสร้างก่อน'
                  : _versions == 1
                  ? 'ชุดเดียว ตามลำดับที่พิมพ์ในแอป'
                  : 'ชุด ก–${examVersionLabel(_versions)}: ชุด ก เป็นลำดับต้นฉบับ '
                        'ชุดอื่นสลับข้อภายในตอนและสลับตัวเลือก',
              style: theme.textTheme.bodySmall,
            ),
            SwitchListTile(
              key: const ValueKey('exam_show_key'),
              contentPadding: EdgeInsets.zero,
              title: const Text('ให้นักเรียนดูเฉลย'),
              subtitle: const Text('นักเรียนเห็นเฉลยรายข้อหลังครูประกาศผล'),
              value: _showKey,
              onChanged: (v) => setState(() => _showKey = v),
            ),
            // The gradebook (§23, build 4) adds the category picker here.
            const InputDecorator(
              key: ValueKey('exam_category_placeholder'),
              decoration: InputDecoration(
                labelText: 'หมวดคะแนน',
                helperText: 'เลือกได้เมื่อเปิดสมุดคะแนนของรายวิชา (เร็ว ๆ นี้)',
                enabled: false,
              ),
              child: Text('ยังไม่ระบุหมวด'),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('exam_submit'),
              onPressed: _busy ? null : _submit,
              child: Text(_editing ? 'บันทึก' : 'สร้างข้อสอบ'),
            ),
          ],
        ),
      ),
    );
  }
}
