import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/live_badge.dart';
import 'attendance_models.dart';
import 'attendance_repository.dart';

class TodayAttendanceCard extends ConsumerWidget {
  const TodayAttendanceCard({super.key, this.now});

  final DateTime? now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final courses = ref.watch(myAttendanceProvider).value;
    if (courses == null) return const SizedBox.shrink();

    final today = now ?? DateTime.now();
    final entries = [
      for (final course in courses)
        for (final entry in course.sessions)
          if (DateUtils.isSameDay(entry.heldOn, today))
            (course: course, entry: entry),
    ];
    if (entries.isEmpty) return const SizedBox.shrink();

    final scheme = Theme.of(context).colorScheme;
    return Card(
      key: const ValueKey('today_attendance'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            title: const Text('การเข้าเรียนวันนี้'),
            trailing: const LiveBadge(),
          ),
          for (final ({MyAttendanceCourse course, MyAttendanceEntry entry})
          row in entries)
            ListTile(
              key: ValueKey(
                'today_attendance_${row.course.courseId}_${row.entry.periodNo}',
              ),
              title: Text(row.course.courseTitle),
              subtitle: row.entry.periodNo == null
                  ? null
                  : Text(periodLabel(row.entry.periodNo)),
              trailing: Text(
                row.entry.status.label,
                style: TextStyle(color: row.entry.status.color(scheme)),
              ),
              onTap: () => context.push(AppRoutes.myAttendance),
            ),
        ],
      ),
    );
  }
}
