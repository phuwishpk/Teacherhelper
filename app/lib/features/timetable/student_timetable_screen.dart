import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'timetable_models.dart';
import 'timetable_repository.dart';
import 'timetable_screen.dart' show TimetableGrid;

/// `/student/timetable` (DESIGN §29.12): the student's week, one card per
/// day on a phone and the grid from a tablet up.
class StudentTimetableScreen extends ConsumerStatefulWidget {
  const StudentTimetableScreen({super.key});

  @override
  ConsumerState<StudentTimetableScreen> createState() =>
      _StudentTimetableScreenState();
}

class _StudentTimetableScreenState
    extends ConsumerState<StudentTimetableScreen> {
  String? _week;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final week = ref.watch(studentTimetableProvider(_week));
    final wide = MediaQuery.sizeOf(context).width >= 600;
    return Scaffold(
      appBar: AppBar(title: const Text('ตารางเรียน')),
      body: AsyncView(
        value: week,
        onRetry: () => ref.invalidate(studentTimetableProvider(_week)),
        data: (w) {
          final end = w.weekStart.add(const Duration(days: 6));
          return ContentColumn(
            maxWidth: 1100,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            child: ListView(
              children: [
                Row(
                  children: [
                    IconButton(
                      key: const ValueKey('week_prev'),
                      tooltip: 'สัปดาห์ก่อน',
                      icon: const Icon(Icons.chevron_left),
                      onPressed: () => setState(
                        () => _week = isoDate(
                          w.weekStart.subtract(const Duration(days: 7)),
                        ),
                      ),
                    ),
                    Expanded(
                      child: Text(
                        'สัปดาห์ ${shortThaiDate(w.weekStart)} – ${shortThaiDate(end)} ${end.year + 543}',
                        key: const ValueKey('week_label'),
                        textAlign: TextAlign.center,
                        style: theme.textTheme.titleMedium,
                      ),
                    ),
                    IconButton(
                      key: const ValueKey('week_next'),
                      tooltip: 'สัปดาห์ถัดไป',
                      icon: const Icon(Icons.chevron_right),
                      onPressed: () => setState(
                        () => _week = isoDate(
                          w.weekStart.add(const Duration(days: 7)),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                if (w.slots.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 32),
                    child: EmptyView(
                      icon: Icons.calendar_month_outlined,
                      title: 'ยังไม่มีตารางเรียน',
                      message:
                          'เมื่อครูใส่คาบในตารางสอนแล้ว ตารางเรียนจะขึ้นที่นี่',
                    ),
                  )
                else if (wide)
                  TimetableGrid(week: w)
                else
                  for (final d in w.weekdays) _DayCard(week: w, weekday: d),
              ],
            ),
          );
        },
      ),
    );
  }
}

String _cancelLabel(String? note) =>
    (note == null || note.isEmpty) ? 'ยกเลิก' : 'ยกเลิก: $note';

class _DayCard extends StatelessWidget {
  const _DayCard({required this.week, required this.weekday});

  final TimetableWeek week;
  final int weekday;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final slots = [
      for (final s in week.slots)
        if (s.weekday == weekday) s,
    ];
    return Card(
      key: ValueKey('student_day_$weekday'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: Text(
              '${weekdayNames[weekday - 1]} ${shortThaiDate(week.dateOf(weekday))}',
              style: theme.textTheme.titleMedium,
            ),
          ),
          if (slots.isEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: Text('ไม่มีคาบ', style: theme.textTheme.bodySmall),
            ),
          for (final s in slots)
            ListTile(
              dense: true,
              leading: Text(
                s.periodNo == null ? '–' : '${s.periodNo}',
                style: theme.textTheme.titleMedium,
              ),
              title: Text(
                '${s.courseCode ?? ''} ${s.courseName ?? ''}'.trim(),
                style: week.isCancelled(s)
                    ? const TextStyle(decoration: TextDecoration.lineThrough)
                    : null,
              ),
              subtitle: Text(
                [
                  if (s.timeLabel.isNotEmpty) s.timeLabel,
                  ?s.classroomName,
                  ?s.teacherName,
                  if (week.isCancelled(s))
                    _cancelLabel(week.cancellationOf(s)?.note),
                ].join(' · '),
              ),
            ),
        ],
      ),
    );
  }
}
