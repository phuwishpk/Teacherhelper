import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import '../gradebook/gradebook_models.dart';
import '../gradebook/gradebook_providers.dart';
import 'attendance_models.dart';
import 'attendance_repository.dart';

/// `/courses/:id/attendance?classroom=` (DESIGN §29.5): the attendance of
/// a course in one of its classrooms. "เช็คชื่อวันนี้" opens a new period;
/// below it the checked periods (newest first) or each student's totals.
class AttendanceScreen extends ConsumerStatefulWidget {
  const AttendanceScreen({
    super.key,
    required this.courseId,
    this.initialClassroomId,
  });

  final int courseId;
  final int? initialClassroomId;

  @override
  ConsumerState<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends ConsumerState<AttendanceScreen> {
  late int? _classroomId = widget.initialClassroomId;
  bool _byStudent = false;
  bool _busy = false;

  int get _courseId => widget.courseId;

  Future<void> _run(Future<String?> Function() action) async {
    setState(() => _busy = true);
    try {
      final message = await action();
      ref.invalidate(attendanceOverviewProvider);
      ref.invalidate(gradebookGridProvider);
      if (mounted && message != null) showMessage(context, message);
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editScores(AttendanceScores current) async {
    final scores = await showDialog<AttendanceScores>(
      context: context,
      builder: (_) => _ScoresDialog(initial: current),
    );
    if (scores == null || !mounted) return;
    await _run(() async {
      await ref
          .read(attendanceRepositoryProvider)
          .saveScores(_courseId, scores);
      return 'บันทึกค่าคะแนนของแต่ละสถานะแล้ว';
    });
  }

  Future<void> _addAutoItem(int classroomId) async {
    final GradebookSettings settings;
    try {
      settings = await ref.read(gradebookSettingsProvider(_courseId).future);
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
      return;
    }
    if (!mounted) return;
    if (!settings.configured || settings.categories.isEmpty) {
      final open = await confirm(
        context,
        title: 'ยังไม่ได้ตั้งค่าสมุดคะแนน',
        message:
            'ตั้งหมวดคะแนนของรายวิชานี้ก่อน แล้วกลับมาเลือกหมวดให้คะแนนการเข้าเรียน',
        confirmLabel: 'เปิดสมุดคะแนน',
      );
      if (open && mounted) context.push(AppRoutes.gradebook(_courseId));
      return;
    }
    final choice = await showDialog<({int categoryId, double maxPoints})>(
      context: context,
      builder: (_) => _AutoItemDialog(categories: settings.categories),
    );
    if (choice == null || !mounted) return;
    await _run(() async {
      await ref
          .read(attendanceRepositoryProvider)
          .addAutoItem(
            _courseId,
            classroomId,
            categoryId: choice.categoryId,
            maxPoints: choice.maxPoints,
          );
      return 'เพิ่มรายการ "การเข้าเรียน" ในสมุดคะแนนแล้ว';
    });
  }

  @override
  Widget build(BuildContext context) {
    final course = ref.watch(courseDetailProvider(_courseId));
    return Scaffold(
      appBar: AppBar(
        title: Text(
          course.value == null ? 'เช็คชื่อ' : 'เช็คชื่อ ${course.value!.code}',
        ),
        bottom: _busy
            ? const PreferredSize(
                preferredSize: Size.fromHeight(4),
                child: LinearProgressIndicator(),
              )
            : null,
      ),
      body: AsyncView(
        value: course,
        onRetry: () => ref.invalidate(courseDetailProvider(_courseId)),
        data: _body,
      ),
    );
  }

  Widget _body(Course c) {
    if (c.classrooms.isEmpty) {
      return const EmptyView(
        icon: Icons.groups_outlined,
        title: 'รายวิชานี้ยังไม่ผูกห้องเรียน',
        message: 'แก้ไขรายวิชาเพื่อเลือกห้องเรียน แล้วกลับมาเช็คชื่อ',
      );
    }
    final classroomId = c.classrooms.any((r) => r.id == _classroomId)
        ? _classroomId!
        : c.classrooms.first.id;
    final overview = ref.watch(
      attendanceOverviewProvider((
        courseId: _courseId,
        classroomId: classroomId,
      )),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (c.classrooms.length > 1)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.fromLTRB(
              context.pageGutter,
              12,
              context.pageGutter,
              0,
            ),
            child: Row(
              children: [
                for (final r in c.classrooms)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      key: ValueKey('attendance_classroom_${r.id}'),
                      label: Text(r.name),
                      selected: r.id == classroomId,
                      onSelected: (_) => setState(() => _classroomId = r.id),
                    ),
                  ),
              ],
            ),
          ),
        Expanded(
          child: AsyncView(
            value: overview,
            onRetry: () => ref.invalidate(attendanceOverviewProvider),
            data: (o) => _overview(o, classroomId),
          ),
        ),
      ],
    );
  }

  Widget _overview(AttendanceOverview o, int classroomId) {
    final theme = Theme.of(context);
    return ContentColumn(
      child: ListView(
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              FilledButton.icon(
                key: const ValueKey('attendance_new'),
                onPressed: () => context.push(
                  AppRoutes.attendanceNew(_courseId, classroomId),
                ),
                icon: const Icon(Icons.how_to_reg_outlined),
                label: const Text('เช็คชื่อวันนี้'),
              ),
              OutlinedButton.icon(
                key: const ValueKey('attendance_scores'),
                onPressed: _busy ? null : () => _editScores(o.scores),
                icon: const Icon(Icons.tune),
                label: const Text('ค่าคะแนนของแต่ละสถานะ'),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('คะแนนการเข้าเรียน', style: theme.textTheme.titleMedium),
                  const SizedBox(height: 4),
                  Text(
                    'มาตรงเวลา ${formatAttendanceNumber(o.scores.present)} · '
                    'มาสาย ${formatAttendanceNumber(o.scores.late)} · '
                    'ขาด ${formatAttendanceNumber(o.scores.absent)} · '
                    'ลากิจและลาป่วยไม่นับ',
                    key: const ValueKey('attendance_scores_text'),
                  ),
                  const SizedBox(height: 8),
                  if (o.autoItem case final item?)
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          'นับในสมุดคะแนน เต็ม ${formatAttendanceNumber(item.maxPoints)} คะแนน',
                          key: const ValueKey('attendance_auto_item'),
                        ),
                        TextButton(
                          onPressed: () => context.push(
                            AppRoutes.gradebook(
                              _courseId,
                              classroomId: classroomId,
                              column: 'i${item.id}',
                            ),
                          ),
                          child: const Text('เปิดสมุดคะแนน'),
                        ),
                      ],
                    )
                  else
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        const Text('ยังไม่นับเป็นคะแนนในสมุดคะแนนของห้องนี้'),
                        TextButton.icon(
                          key: const ValueKey('attendance_add_auto_item'),
                          onPressed: _busy
                              ? null
                              : () => _addAutoItem(classroomId),
                          icon: const Icon(Icons.add),
                          label: const Text('นับเป็นคะแนน'),
                        ),
                      ],
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              key: const ValueKey('attendance_view'),
              segments: [
                ButtonSegment(
                  value: false,
                  label: Text('ประวัติ (${o.sessions.length})'),
                ),
                const ButtonSegment(value: true, label: Text('สรุปรายคน')),
              ],
              selected: {_byStudent},
              onSelectionChanged: (v) => setState(() => _byStudent = v.first),
            ),
          ),
          const SizedBox(height: 8),
          if (o.sessions.isEmpty)
            const Card(
              child: Padding(
                padding: EdgeInsets.all(16),
                child: Text(
                  'ยังไม่เคยเช็คชื่อห้องนี้ในรายวิชานี้ กด "เช็คชื่อวันนี้" เพื่อเริ่ม',
                ),
              ),
            )
          else if (_byStudent)
            Card(
              child: Column(
                children: [for (final s in o.students) _studentTile(s)],
              ),
            )
          else
            Card(
              child: Column(
                children: [for (final s in o.sessions) _sessionTile(s)],
              ),
            ),
        ],
      ),
    );
  }

  Widget _sessionTile(AttendanceSession s) {
    final period = periodLabel(s.periodNo);
    return ListTile(
      key: ValueKey('attendance_session_${s.id}'),
      title: Text(
        [formatThaiDate(s.heldOn), if (period.isNotEmpty) period].join(' · '),
      ),
      subtitle: Text(
        [s.counts.summary, ?s.note].where((t) => t.isNotEmpty).join('\n'),
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push(AppRoutes.attendanceSession(_courseId, s.id)),
    );
  }

  Widget _studentTile(AttendanceStudentTotal s) {
    final theme = Theme.of(context);
    final low = s.rate != null && s.rate! < 0.8;
    return ListTile(
      key: ValueKey('attendance_student_${s.studentId}'),
      leading: Text(
        s.studentNumber?.toString() ?? '–',
        style: theme.textTheme.titleMedium,
      ),
      title: Text(s.inClassroom ? s.name : '${s.name} (ออกจากห้องแล้ว)'),
      subtitle: Text(
        s.counts.total == 0 ? 'ยังไม่มีการเช็คชื่อ' : s.counts.summary,
      ),
      trailing: Text(
        formatAttendanceRate(s.rate),
        style: theme.textTheme.titleMedium?.copyWith(
          color: low ? theme.colorScheme.error : null,
        ),
      ),
    );
  }
}

class _ScoresDialog extends StatefulWidget {
  const _ScoresDialog({required this.initial});

  final AttendanceScores initial;

  @override
  State<_ScoresDialog> createState() => _ScoresDialogState();
}

class _ScoresDialogState extends State<_ScoresDialog> {
  final _formKey = GlobalKey<FormState>();
  late final _present = TextEditingController(
    text: formatAttendanceNumber(widget.initial.present),
  );
  late final _late = TextEditingController(
    text: formatAttendanceNumber(widget.initial.late),
  );
  late final _absent = TextEditingController(
    text: formatAttendanceNumber(widget.initial.absent),
  );

  @override
  void dispose() {
    _present.dispose();
    _late.dispose();
    _absent.dispose();
    super.dispose();
  }

  String? _check(String? v) {
    final n = double.tryParse((v ?? '').trim());
    if (n == null || n < 0 || n > 1) return 'ใส่ค่า 0 ถึง 1';
    if ((n * 100 - (n * 100).round()).abs() > 1e-9) {
      return 'ทศนิยมไม่เกิน 2 ตำแหน่ง';
    }
    return null;
  }

  void _submit() {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    Navigator.of(context).pop(
      AttendanceScores(
        present: double.parse(_present.text.trim()),
        late: double.parse(_late.text.trim()),
        absent: double.parse(_absent.text.trim()),
      ),
    );
  }

  Widget _field(String key, String label, TextEditingController controller) {
    return TextFormField(
      key: ValueKey(key),
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
      decoration: InputDecoration(labelText: label),
      validator: _check,
    );
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('ค่าคะแนนของแต่ละสถานะ'),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'ใส่ค่า 0 ถึง 1 ต่อหนึ่งคาบ ใช้กับทุกห้องของรายวิชานี้ '
                'ลากิจและลาป่วยไม่ถูกนับ',
              ),
              const SizedBox(height: 8),
              _field('score_present', 'มาตรงเวลา', _present),
              _field('score_late', 'มาสาย', _late),
              _field('score_absent', 'ขาด', _absent),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('scores_save'),
          onPressed: _submit,
          child: const Text('บันทึก'),
        ),
      ],
    );
  }
}

class _AutoItemDialog extends StatefulWidget {
  const _AutoItemDialog({required this.categories});

  final List<CategoryDraft> categories;

  @override
  State<_AutoItemDialog> createState() => _AutoItemDialogState();
}

class _AutoItemDialogState extends State<_AutoItemDialog> {
  final _formKey = GlobalKey<FormState>();
  final _max = TextEditingController(text: '10');
  late int? _categoryId = widget.categories.first.id;

  @override
  void dispose() {
    _max.dispose();
    super.dispose();
  }

  void _submit() {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    Navigator.of(context).pop((
      categoryId: _categoryId!,
      maxPoints: double.parse(_max.text.trim()),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('นับการเข้าเรียนเป็นคะแนน'),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'ระบบเพิ่มรายการ "การเข้าเรียน" ในสมุดคะแนนของห้องนี้ '
                'และคิดคะแนนจากการเช็คชื่อให้เองทุกครั้งที่บันทึก',
              ),
              const SizedBox(height: 8),
              DropdownButtonFormField<int>(
                key: const ValueKey('auto_item_category'),
                initialValue: _categoryId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'หมวดคะแนน'),
                items: [
                  for (final c in widget.categories)
                    if (c.id != null)
                      DropdownMenuItem(
                        value: c.id,
                        child: Text('${c.name} (${formatGbNumber(c.weight)}%)'),
                      ),
                ],
                onChanged: (v) => setState(() => _categoryId = v),
                validator: (v) => v == null ? 'เลือกหมวดคะแนน' : null,
              ),
              TextFormField(
                key: const ValueKey('auto_item_max'),
                controller: _max,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                ],
                decoration: const InputDecoration(labelText: 'คะแนนเต็ม'),
                validator: (v) {
                  final n = double.tryParse((v ?? '').trim());
                  if (n == null || n <= 0 || n > 1000) {
                    return 'ใส่คะแนนเต็มมากกว่า 0 และไม่เกิน 1000';
                  }
                  return null;
                },
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('auto_item_save'),
          onPressed: _submit,
          child: const Text('เพิ่ม'),
        ),
      ],
    );
  }
}
