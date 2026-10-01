import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import 'courses_providers.dart';
import 'courses_screen.dart';

/// "รายวิชา" on a classroom's page: the courses bound to it (an assignment
/// of the classroom picks one of them, DESIGN §20.1), each with a link to
/// its charts in this classroom (§20.4), and "เพิ่มรายวิชา".
class ClassroomCoursesSection extends ConsumerWidget {
  const ClassroomCoursesSection({
    super.key,
    required this.classroomId,
    this.readOnly = false,
  });

  final int classroomId;

  /// A closed room ("ห้องเก่า", DESIGN §24.6) binds no new course.
  final bool readOnly;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final courses = ref.watch(classroomCoursesProvider(classroomId));
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            title: Text('รายวิชา', style: theme.textTheme.titleMedium),
            subtitle: const Text(
              'การบ้านใหม่ของห้องนี้เลือกได้จากรายวิชาเหล่านี้',
            ),
            trailing: readOnly
                ? null
                : TextButton.icon(
                    key: const ValueKey('classroom_add_course'),
                    onPressed: () =>
                        startNewCourse(context, ref, classroomId: classroomId),
                    icon: const Icon(Icons.add),
                    label: const Text('เพิ่มรายวิชา'),
                  ),
          ),
          ...courses.when(
            skipLoadingOnRefresh: true,
            // Not an endless animation: a pending load must not keep the
            // page from settling.
            loading: () => const [
              Padding(
                padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Text('กำลังโหลดรายวิชา…'),
              ),
            ],
            error: (e, _) => [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Text(
                  'โหลดรายวิชาไม่ได้: ${apiErrorMessage(e)}',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            ],
            data: (list) => list.isEmpty
                ? [
                    const Padding(
                      padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
                      child: Text('ยังไม่มีรายวิชาผูกกับห้องนี้'),
                    ),
                  ]
                : [
                    for (final c in list) ...[
                      const Divider(height: 1),
                      ListTile(
                        key: ValueKey('classroom_course_${c.id}'),
                        leading: const Icon(Icons.menu_book_outlined),
                        title: Text(c.title),
                        subtitle: Text(
                          '${c.termLabel} · แผน ${c.lessonPlanCount}',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => context.push(AppRoutes.course(c.id)),
                      ),
                      ListTile(
                        key: ValueKey('classroom_course_charts_${c.id}'),
                        dense: true,
                        contentPadding: const EdgeInsets.only(
                          left: 72,
                          right: 16,
                        ),
                        leading: const Icon(Icons.radar),
                        title: Text(
                          'กราฟรายวิชา ${c.code.isEmpty ? c.name : c.code}',
                        ),
                        subtitle: const Text(
                          'เรดาร์ของห้อง ร้อยละที่ผ่าน และรายคน',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => context.push(
                          AppRoutes.courseCharts(
                            c.id,
                            classroomId: classroomId,
                          ),
                        ),
                      ),
                    ],
                  ],
          ),
        ],
      ),
    );
  }
}
