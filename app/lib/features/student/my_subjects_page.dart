import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../gradebook/student_grades.dart' show GradeBadge;
import 'student_labels.dart';
import 'student_overview.dart';

/// The student's home "วิชาของฉัน" (DESIGN §24.11, §24.13): one card per
/// course and classroom of every classroom the student is in, with the
/// classroom label, what is left to hand in, the published results and the
/// grade. Closed classrooms fold away under "ห้องเก่า". A card opens the
/// subject: its work, results, grade and charts.
class MySubjectsPage extends ConsumerWidget {
  const MySubjectsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overview = ref.watch(studentOverviewProvider);
    Future<void> refresh() => ref.refresh(studentOverviewProvider.future);
    return AsyncView(
      value: overview,
      onRetry: () => ref.invalidate(studentOverviewProvider),
      data: (o) {
        if (o.classrooms.isEmpty || o.groups.isEmpty) {
          final rooms = [for (final c in o.classrooms) c.text].join(', ');
          return RefreshIndicator(
            onRefresh: refresh,
            child: ListView(
              children: [
                if (o.classrooms.isEmpty)
                  const EmptyView(
                    key: ValueKey('subjects_no_classroom'),
                    icon: Icons.meeting_room_outlined,
                    title: 'ยังไม่ได้อยู่ในห้องเรียน',
                    message:
                        'เมื่อครูเพิ่มเราเข้าห้องเรียนแล้ว '
                        'รายวิชาของห้องจะขึ้นที่นี่',
                  )
                else
                  EmptyView(
                    key: const ValueKey('subjects_empty'),
                    icon: Icons.menu_book_outlined,
                    title: 'ยังไม่มีรายวิชา',
                    message:
                        'ห้อง $rooms ยังไม่มีรายวิชาหรืองานที่ครูสั่ง '
                        'เมื่อครูสั่งงานแล้ว รายวิชาจะขึ้นที่นี่',
                  ),
              ],
            ),
          );
        }
        final open = o.openGroups;
        final closed = o.closedGroups;
        final theme = Theme.of(context);
        final todo = o.todoCount;
        return RefreshIndicator(
          onRefresh: refresh,
          child: ContentColumn(
            child: ListView(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        todo > 0 ? 'ต้องส่งอีก $todo งาน' : 'ไม่มีงานค้าง',
                        key: const ValueKey('subjects_todo_total'),
                        style: theme.textTheme.titleMedium,
                      ),
                    ),
                    TextButton.icon(
                      key: const ValueKey('subjects_all_grades'),
                      onPressed: () => context.push(AppRoutes.myGrades),
                      icon: const Icon(Icons.school_outlined),
                      label: const Text('เกรดทั้งหมด'),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                if (open.isEmpty)
                  const Card(
                    child: ListTile(
                      key: ValueKey('subjects_only_old'),
                      leading: Icon(Icons.info_outline),
                      title: Text('ไม่มีรายวิชาในห้องที่เปิดอยู่'),
                      subtitle: Text('ดูผลและเกรดของห้องเก่าได้ด้านล่าง'),
                    ),
                  ),
                for (final g in open) SubjectCard(group: g),
                if (closed.isNotEmpty)
                  Card(
                    clipBehavior: Clip.antiAlias,
                    child: ExpansionTile(
                      key: const ValueKey('subjects_old'),
                      leading: const Icon(Icons.inventory_2_outlined),
                      title: Text('ห้องเก่า (${closed.length} รายวิชา)'),
                      subtitle: const Text('อ่านอย่างเดียว ดูผลและเกรดเดิมได้'),
                      childrenPadding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
                      children: [for (final g in closed) SubjectCard(group: g)],
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

/// One subject of "วิชาของฉัน": title, classroom, teacher, to-do and
/// results counts and the published grade; opens the subject.
class SubjectCard extends StatelessWidget {
  const SubjectCard({super.key, required this.group});

  final SubjectGroup group;

  @override
  Widget build(BuildContext context) {
    final g = group;
    final tag = g.tag;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final chips = <Widget>[
      if (g.todoCount > 0)
        StatusChip(label: 'ต้องส่ง ${g.todoCount} งาน', color: scheme.error)
      else if (!g.classroom.closed)
        StatusChip(label: 'ไม่มีงานค้าง', color: scheme.primary),
      if (g.resultsCount > 0)
        StatusChip(
          label: 'ผล ${g.resultsCount} รายการ',
          color: scheme.secondary,
        ),
    ];
    return Card(
      key: ValueKey('subject_${tag.key}'),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push(AppRoutes.studentSubject(tag.key)),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 12, 12),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tag.title, style: theme.textTheme.titleMedium),
                    const SizedBox(height: 4),
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        ClassroomChip(tag: tag),
                        if (g.teacherName != null && g.teacherName!.isNotEmpty)
                          Text(
                            g.teacherName!,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                          ),
                      ],
                    ),
                    if (chips.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Wrap(spacing: 6, runSpacing: 4, children: chips),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              if (g.hasGrade)
                GradeBadge(text: g.gradeText)
              else
                const Icon(Icons.chevron_right),
            ],
          ),
        ),
      ),
    );
  }
}
