import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'attendance_models.dart';
import 'attendance_repository.dart';

/// `/student/attendance` (DESIGN §29.5): the student's own attendance,
/// one card per course with every checked period, newest first.
class MyAttendanceScreen extends ConsumerWidget {
  const MyAttendanceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final courses = ref.watch(myAttendanceProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('การเข้าเรียนของฉัน')),
      body: AsyncView(
        value: courses,
        onRetry: () => ref.invalidate(myAttendanceProvider),
        data: (list) => list.isEmpty
            ? const EmptyView(
                icon: Icons.how_to_reg_outlined,
                title: 'ยังไม่มีการเช็คชื่อ',
                message: 'เมื่อครูเช็คชื่อ ประวัติการเข้าเรียนจะแสดงที่นี่',
              )
            : RefreshIndicator(
                onRefresh: () => ref.refresh(myAttendanceProvider.future),
                child: ContentColumn(
                  child: ListView(
                    children: [for (final c in list) _CourseCard(course: c)],
                  ),
                ),
              ),
      ),
    );
  }
}

class _CourseCard extends StatelessWidget {
  const _CourseCard({required this.course});

  final MyAttendanceCourse course;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Card(
      key: ValueKey('my_attendance_${course.courseId}'),
      child: ExpansionTile(
        shape: const Border(),
        collapsedShape: const Border(),
        title: Text(course.courseTitle),
        subtitle: Text(
          [
            if (course.classroomName.isNotEmpty) course.classroomName,
            course.counts.summary,
          ].join(' · '),
        ),
        trailing: Text(
          formatAttendanceRate(course.rate),
          style: theme.textTheme.titleLarge?.copyWith(
            color: course.rate != null && course.rate! < 0.8
                ? scheme.error
                : scheme.primary,
          ),
        ),
        children: [
          for (final s in course.sessions)
            ListTile(
              dense: true,
              title: Text(
                [
                  formatThaiDate(s.heldOn),
                  if (periodLabel(s.periodNo).isNotEmpty)
                    periodLabel(s.periodNo),
                ].join(' · '),
              ),
              subtitle: s.note == null ? null : Text(s.note!),
              trailing: StatusChip(
                label: s.status.label,
                color: s.status.color(scheme),
              ),
            ),
        ],
      ),
    );
  }
}
