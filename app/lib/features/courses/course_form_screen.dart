import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../assignments/question.dart';
import '../classrooms/classrooms_providers.dart';
import 'course_models.dart';
import 'courses_providers.dart';
import 'courses_repository.dart';
import 'indicator_widgets.dart';

/// `/courses/:id/edit`: the course handed over as route `extra`, or loaded
/// by id (a deep link never opens the form in create mode).
class CourseEditScreen extends ConsumerWidget {
  const CourseEditScreen({super.key, required this.courseId, this.initial});

  final int courseId;
  final Course? initial;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (initial case final c?
        when c.id == courseId && c.indicators.length == c.indicatorCount) {
      return CourseFormScreen(existing: c);
    }
    final detail = ref.watch(courseDetailProvider(courseId));
    return detail.when(
      skipLoadingOnRefresh: true,
      data: (c) => CourseFormScreen(existing: c),
      loading: () => Scaffold(
        appBar: AppBar(title: const Text('แก้ไขรายวิชา')),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: const Text('แก้ไขรายวิชา')),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(courseDetailProvider(courseId)),
        ),
      ),
    );
  }
}

/// Create a course (รายวิชา, DESIGN §20.1) once and bind it to any of the
/// teacher's classrooms, or edit it. Its indicators can be picked here too.
class CourseFormScreen extends ConsumerStatefulWidget {
  const CourseFormScreen({
    super.key,
    this.existing,
    this.initialClassroomId,
    this.popOnCreate = false,
  });

  final Course? existing;
  final int? initialClassroomId;

  /// Pop the new course (to the assignment form that asked for it) instead
  /// of opening it.
  final bool popOnCreate;

  @override
  ConsumerState<CourseFormScreen> createState() => _CourseFormScreenState();
}

class _CourseFormScreenState extends ConsumerState<CourseFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final Course? _c = widget.existing;
  late final _code = TextEditingController(text: _c?.code ?? '');
  late final _name = TextEditingController(text: _c?.name ?? '');
  late final _year = TextEditingController(
    text: '${_c?.academicYear ?? currentThaiYear()}',
  );
  late final _hoursText = TextEditingController(
    text: _c?.hours?.toString() ?? '',
  );
  late final _description = TextEditingController(text: _c?.description ?? '');
  late int? _subjectId = _c?.subjectId;
  late int? _grade = _c?.gradeLevel;
  late int _semester = _c?.semester ?? 1;
  late Set<int> _classroomIds = {
    ...?_c?.classroomIds,
    ?widget.initialClassroomId,
  };
  late List<Skill> _indicators = _c?.indicators ?? const [];
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    _name.dispose();
    _year.dispose();
    _hoursText.dispose();
    _description.dispose();
    super.dispose();
  }

  CourseDraft get _draft => CourseDraft(
    code: _code.text.trim(),
    name: _name.text.trim(),
    subjectId: _subjectId!,
    gradeLevel: _grade!,
    semester: _semester,
    academicYear: int.parse(_year.text.trim()),
    hours: int.tryParse(_hoursText.text.trim()),
    description: _description.text.trim().isEmpty
        ? null
        : _description.text.trim(),
  );

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final repo = ref.read(coursesRepositoryProvider);
    try {
      if (_c case final c?) {
        await repo.update(c.id, _draft);
        final classrooms = _classroomIds.toList()..sort();
        final before = [...c.classroomIds]..sort();
        if (classrooms.join(',') != before.join(',')) {
          await repo.setClassrooms(c.id, classrooms);
        }
        final ids = [for (final s in _indicators) s.id]..sort();
        final had = [for (final s in c.indicators) s.id]..sort();
        if (ids.join(',') != had.join(',')) {
          await repo.setIndicators(c.id, ids);
        }
        invalidateCourses(ref);
        if (!mounted) return;
        showMessage(context, 'บันทึกรายวิชาแล้ว');
        context.pop();
      } else {
        final created = await repo.create(
          _draft,
          classroomIds: _classroomIds.toList(),
          skillIds: [for (final s in _indicators) s.id],
        );
        invalidateCourses(ref);
        if (!mounted) return;
        if (widget.popOnCreate) {
          showMessage(context, 'สร้างรายวิชาแล้ว');
          context.pop(created);
          return;
        }
        showMessage(context, 'สร้างรายวิชาแล้ว เพิ่มหน่วยและแผนการสอนได้เลย');
        context.pushReplacement(AppRoutes.course(created.id));
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
    final subjects = ref.watch(subjectsProvider);
    final classrooms = ref.watch(classroomsProvider);
    final editing = _c != null;
    final lockedSubject = (_c?.assignmentCount ?? 0) > 0;

    return Scaffold(
      appBar: AppBar(title: Text(editing ? 'แก้ไขรายวิชา' : 'สร้างรายวิชา')),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 130,
                  child: TextFormField(
                    key: const ValueKey('course_code'),
                    controller: _code,
                    decoration: const InputDecoration(
                      labelText: 'รหัสวิชา',
                      hintText: 'ค15101',
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'กรอกรหัส'
                        : v.trim().length > 20
                        ? 'ยาวเกิน 20 ตัว'
                        : null,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    key: const ValueKey('course_name'),
                    controller: _name,
                    decoration: const InputDecoration(
                      labelText: 'ชื่อรายวิชา',
                      hintText: 'คณิตศาสตร์ 5',
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'กรอกชื่อรายวิชา'
                        : null,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            DropdownButtonFormField<int>(
              key: const ValueKey('course_subject'),
              initialValue: _subjectId,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: 'กลุ่มสาระ',
                helperText: lockedSubject
                    ? 'เปลี่ยนกลุ่มสาระไม่ได้ เพราะมีการบ้านใช้รายวิชานี้แล้ว'
                    : null,
              ),
              items: [
                for (final s in subjects.value ?? const <Subject>[])
                  DropdownMenuItem(value: s.id, child: Text(s.name)),
              ],
              onChanged: lockedSubject
                  ? null
                  : (v) => setState(() => _subjectId = v),
              validator: (v) => v == null ? 'เลือกกลุ่มสาระ' : null,
            ),
            if (subjects.hasError)
              Text(
                'โหลดกลุ่มสาระไม่ได้: ${apiErrorMessage(subjects.error!)}',
                style: TextStyle(color: theme.colorScheme.error),
              ),
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: DropdownButtonFormField<int>(
                    key: const ValueKey('course_grade'),
                    initialValue: _grade,
                    decoration: const InputDecoration(labelText: 'ชั้น'),
                    items: [
                      for (var g = 1; g <= 12; g++)
                        DropdownMenuItem(
                          value: g,
                          child: Text(gradeLevelLabel(g)),
                        ),
                    ],
                    onChanged: (v) => setState(() => _grade = v),
                    validator: (v) => v == null ? 'เลือกชั้น' : null,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    key: const ValueKey('course_year'),
                    controller: _year,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'ปีการศึกษา (พ.ศ.)',
                    ),
                    validator: (v) {
                      final y = int.tryParse(v?.trim() ?? '');
                      return y == null || y < 2500 || y > 2700
                          ? 'กรอกปี พ.ศ. เช่น ${currentThaiYear()}'
                          : null;
                    },
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            Text('ภาคเรียน', style: theme.textTheme.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<int>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 1, label: Text('ภาค 1')),
                ButtonSegment(value: 2, label: Text('ภาค 2')),
                ButtonSegment(value: 0, label: Text('ทั้งปี')),
              ],
              selected: {_semester},
              onSelectionChanged: (s) => setState(() => _semester = s.first),
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('course_hours'),
              controller: _hoursText,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(
                labelText: 'จำนวนชั่วโมง (ไม่บังคับ)',
              ),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _description,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'คำอธิบายรายวิชา (ไม่บังคับ)',
                alignLabelWithHint: true,
              ),
            ),
            const SizedBox(height: 16),
            Text(
              'ห้องเรียนที่ใช้รายวิชานี้',
              style: theme.textTheme.labelLarge,
            ),
            const SizedBox(height: 8),
            AsyncView(
              value: classrooms,
              data: (list) => list.isEmpty
                  ? Text(
                      'ยังไม่มีห้องเรียน สร้างห้องก่อนแล้วค่อยผูกได้ภายหลัง',
                      style: theme.textTheme.bodySmall,
                    )
                  : Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final c in list)
                          FilterChip(
                            key: ValueKey('course_classroom_${c.id}'),
                            label: Text(c.name),
                            selected: _classroomIds.contains(c.id),
                            onSelected: (v) => setState(() {
                              _classroomIds = {..._classroomIds};
                              v
                                  ? _classroomIds.add(c.id)
                                  : _classroomIds.remove(c.id);
                            }),
                          ),
                      ],
                    ),
            ),
            const SizedBox(height: 16),
            IndicatorsField(
              label: 'ตัวชี้วัดของรายวิชา',
              indicators: _indicators,
              subjectId: _subjectId,
              grade: _grade,
              onChanged: (v) => setState(() => _indicators = v),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('course_save'),
              onPressed: _busy ? null : _submit,
              child: Text(editing ? 'บันทึก' : 'สร้างรายวิชา'),
            ),
          ],
        ),
      ),
    );
  }
}
