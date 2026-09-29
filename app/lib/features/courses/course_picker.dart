import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import 'course_models.dart';
import 'courses_providers.dart';

/// "สร้างรายวิชา" for a classroom that has none yet: the course form with
/// the classroom ticked; resolves to the new course (null when cancelled).
Future<Course?> createCourseFor(
  BuildContext context,
  WidgetRef ref,
  int classroomId,
) async {
  final created = await context.push<Course>(
    AppRoutes.courseNewFor(classroomId, pick: true),
  );
  ref.invalidate(classroomCoursesProvider(classroomId));
  return created;
}

/// The courses of a classroom as a dropdown (an assignment must pick one,
/// DESIGN §20.1). With no course bound yet it offers "สร้างรายวิชา".
class ClassroomCourseField extends ConsumerWidget {
  const ClassroomCourseField({
    super.key,
    required this.classroomId,
    required this.value,
    required this.onChanged,
    this.fieldKey,
    this.isRequired = true,
  });

  final int classroomId;
  final int? value;
  final ValueChanged<Course?> onChanged;
  final Key? fieldKey;

  /// False while editing an older assignment that never had a course.
  final bool isRequired;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final courses = ref.watch(classroomCoursesProvider(classroomId));
    return courses.when(
      skipLoadingOnRefresh: true,
      loading: () => const LinearProgressIndicator(),
      error: (e, _) => Text(
        'โหลดรายวิชาไม่ได้: ${apiErrorMessage(e)}',
        style: TextStyle(color: theme.colorScheme.error),
      ),
      data: (list) {
        if (list.isEmpty) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'ห้องนี้ยังไม่มีรายวิชา สร้างรายวิชาและผูกกับห้องนี้ก่อน',
                style: TextStyle(color: theme.colorScheme.error),
              ),
              TextButton.icon(
                key: const ValueKey('course_create_for_classroom'),
                onPressed: () async {
                  final created = await createCourseFor(
                    context,
                    ref,
                    classroomId,
                  );
                  if (created != null) onChanged(created);
                },
                icon: const Icon(Icons.add),
                label: const Text('สร้างรายวิชา'),
              ),
            ],
          );
        }
        final current = list.any((c) => c.id == value) ? value : null;
        return DropdownButtonFormField<int>(
          key: fieldKey ?? ValueKey('course_field_$classroomId'),
          initialValue: current,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'รายวิชา'),
          items: [
            for (final c in list)
              DropdownMenuItem(
                value: c.id,
                child: Text(c.title, overflow: TextOverflow.ellipsis),
              ),
          ],
          onChanged: (id) =>
              onChanged(list.where((c) => c.id == id).firstOrNull),
          validator: (v) => isRequired && v == null ? 'เลือกรายวิชา' : null,
        );
      },
    );
  }
}

/// The lesson plans of a course as a dropdown ("ไม่ผูกแผน" = null).
class LessonPlanField extends ConsumerWidget {
  const LessonPlanField({
    super.key,
    required this.courseId,
    required this.value,
    required this.onChanged,
  });

  final int courseId;
  final int? value;
  final ValueChanged<int?> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final course = ref.watch(courseDetailProvider(courseId));
    final plans = course.value?.lessonPlans ?? const <LessonPlan>[];
    if (course.hasError) {
      return Text(
        'โหลดแผนการสอนไม่ได้: ${apiErrorMessage(course.error!)}',
        style: TextStyle(color: Theme.of(context).colorScheme.error),
      );
    }
    if (course.value == null) return const SizedBox.shrink();
    final units = {for (final u in course.value!.units) u.id: u};
    final current = plans.any((p) => p.id == value) ? value : null;
    return DropdownButtonFormField<int?>(
      key: ValueKey('lesson_plan_field_$courseId'),
      initialValue: current,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: 'แผนการสอน (ไม่บังคับ)',
        helperText: plans.isEmpty ? 'รายวิชานี้ยังไม่มีแผนการสอน' : null,
      ),
      items: [
        const DropdownMenuItem<int?>(value: null, child: Text('ไม่ผูกแผน')),
        for (final p in plans)
          DropdownMenuItem<int?>(
            value: p.id,
            child: Text(
              [
                if (units[p.unitId] case final u?) 'หน่วย ${u.position}',
                p.label,
              ].join(' · '),
              overflow: TextOverflow.ellipsis,
            ),
          ),
      ],
      onChanged: onChanged,
    );
  }
}

/// Asks for the course of an assignment that has none before its key is
/// approved (a Classroom website mirror, DESIGN §19.3, 422
/// `course_required`); pops the course id.
class CoursePickerDialog extends ConsumerStatefulWidget {
  const CoursePickerDialog({super.key, required this.classroomId});

  final int classroomId;

  @override
  ConsumerState<CoursePickerDialog> createState() => _CoursePickerDialogState();
}

class _CoursePickerDialogState extends ConsumerState<CoursePickerDialog> {
  int? _courseId;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เลือกรายวิชาแล้วอนุมัติเฉลย'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'งานนี้ยังไม่มีรายวิชา เลือกรายวิชาของห้องนี้ก่อน '
            'หลังอนุมัติ ระบบเริ่มตรวจงานที่นักเรียนส่งด้วยเฉลยนี้',
          ),
          const SizedBox(height: 12),
          ClassroomCourseField(
            fieldKey: const ValueKey('approve_course'),
            classroomId: widget.classroomId,
            value: _courseId,
            onChanged: (c) => setState(() => _courseId = c?.id),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('approve_course_confirm'),
          onPressed: _courseId == null
              ? null
              : () => Navigator.of(context).pop(_courseId),
          child: const Text('อนุมัติ'),
        ),
      ],
    );
  }
}
