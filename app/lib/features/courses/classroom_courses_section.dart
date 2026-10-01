import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classroom.dart';
import '../classrooms/course_requests.dart';
import '../classrooms/request_classroom_screen.dart';
import 'course_models.dart';
import 'courses_providers.dart';
import 'courses_repository.dart';
import 'courses_screen.dart';

/// "รายวิชา" on a classroom's page: the courses taught in it (`GET
/// /classrooms/{id}/courses`, DESIGN §24.12 B), each with a link to its
/// charts in this classroom (§20.4). The homeroom teacher sees every
/// course with its teacher (another teacher's course is read-only) and
/// adds their own; a subject teacher sees their own and asks for another
/// one (§24.7, §24.13).
class ClassroomCoursesSection extends ConsumerWidget {
  const ClassroomCoursesSection({super.key, required this.classroom});

  final Classroom classroom;

  int get classroomId => classroom.id;

  /// A closed room ("ห้องเก่า", DESIGN §24.6) binds or unbinds nothing.
  bool get readOnly => classroom.isClosed;

  Future<void> _unbind(
    BuildContext context,
    WidgetRef ref,
    ClassroomCourse c,
  ) async {
    final ok = await confirm(
      context,
      title: 'เลิกผูก ${c.title} กับห้อง ${classroom.name}?',
      message: c.isMine
          ? 'สร้างการบ้านของรายวิชานี้ในห้องนี้ไม่ได้อีก ผูกใหม่ได้ภายหลัง'
          : '${c.teacher?.name ?? 'ครูผู้สอน'} จะสั่งงานรายวิชานี้ในห้องนี้ไม่ได้อีก '
                'และไม่เห็นห้องนี้ในรายการห้องเรียน',
      confirmLabel: 'เลิกผูก',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await ref.read(coursesRepositoryProvider).unbind(classroomId, c.id);
      invalidateCourses(ref);
      if (context.mounted) showMessage(context, 'เลิกผูก ${c.title} แล้ว');
      // A subject teacher's last course: the room leaves their list.
      if (classroom.isSubject && context.mounted) {
        final left = await ref.read(
          classroomTeachingProvider(classroomId).future,
        );
        if (left.isEmpty && context.mounted) context.pop();
      }
    } catch (e) {
      if (context.mounted) showMessage(context, courseRequestErrorText(e));
    }
  }

  Widget? _addButton(BuildContext context, WidgetRef ref) {
    if (readOnly) return null;
    if (classroom.isHomeroom) {
      return TextButton.icon(
        key: const ValueKey('classroom_add_course'),
        onPressed: () => startNewCourse(context, ref, classroomId: classroomId),
        icon: const Icon(Icons.add),
        label: const Text('เพิ่มรายวิชา'),
      );
    }
    return TextButton.icon(
      key: const ValueKey('classroom_request_course'),
      onPressed: () => showCourseRequestDialog(
        context,
        room: DirectoryClassroom.of(classroom),
      ),
      icon: const Icon(Icons.add),
      label: const Text('ขอผูกรายวิชาอื่น'),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final courses = ref.watch(classroomTeachingProvider(classroomId));
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            title: Text('รายวิชา', style: theme.textTheme.titleMedium),
            subtitle: Text(
              classroom.isHomeroom
                  ? 'รายวิชาที่สอนห้องนี้ทั้งหมด การบ้านใหม่ของคุณเลือกได้จากรายวิชาของคุณ'
                  : 'รายวิชาของคุณในห้องนี้ ผลของรายวิชาอื่นไม่แสดง',
            ),
            trailing: _addButton(context, ref),
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
                      _courseTile(context, ref, c),
                      _chartsTile(context, c),
                    ],
                  ],
          ),
        ],
      ),
    );
  }

  Widget _courseTile(BuildContext context, WidgetRef ref, ClassroomCourse c) {
    final theme = Theme.of(context);
    final canUnbind = !readOnly && (classroom.isHomeroom || c.isMine);
    return ListTile(
      key: ValueKey('classroom_course_${c.id}'),
      leading: const Icon(Icons.menu_book_outlined),
      title: Text(c.title),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(c.termLabel),
          if (!c.isMine)
            Text(
              'สอนโดย ${c.teacher?.name ?? 'ครูท่านอื่น'} · ดูผลได้อย่างเดียว',
              key: ValueKey('classroom_course_teacher_${c.id}'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.tertiary,
              ),
            ),
        ],
      ),
      trailing: canUnbind
          ? PopupMenuButton<String>(
              key: ValueKey('classroom_course_menu_${c.id}'),
              tooltip: 'ตัวเลือกรายวิชา',
              onSelected: (v) => switch (v) {
                'open' => context.push(AppRoutes.course(c.id)),
                'unbind' => _unbind(context, ref, c),
                _ => null,
              },
              itemBuilder: (context) => [
                if (c.isMine)
                  const PopupMenuItem(
                    value: 'open',
                    child: ListTile(
                      leading: Icon(Icons.menu_book_outlined),
                      title: Text('เปิดรายวิชา'),
                    ),
                  ),
                const PopupMenuItem(
                  value: 'unbind',
                  child: ListTile(
                    leading: Icon(Icons.link_off),
                    title: Text('เลิกผูกกับห้องนี้'),
                  ),
                ),
              ],
            )
          : (c.isMine ? const Icon(Icons.chevron_right) : null),
      // Another teacher's course has no page for the homeroom teacher.
      onTap: c.isMine ? () => context.push(AppRoutes.course(c.id)) : null,
    );
  }

  Widget _chartsTile(BuildContext context, ClassroomCourse c) {
    final label = c.code.isEmpty ? c.name : c.code;
    // The course charts are the course owner's; the homeroom teacher reads
    // another teacher's course on the room's heatmap (DESIGN §24.8).
    return ListTile(
      key: ValueKey('classroom_course_charts_${c.id}'),
      dense: true,
      contentPadding: const EdgeInsets.only(left: 72, right: 16),
      leading: Icon(c.isMine ? Icons.radar : Icons.grid_on_outlined),
      title: Text(c.isMine ? 'กราฟรายวิชา $label' : 'ทักษะของห้องตาม $label'),
      subtitle: Text(
        c.isMine
            ? 'เรดาร์ของห้อง ร้อยละที่ผ่าน และรายคน'
            : 'heatmap ของตัวชี้วัดในรายวิชานี้',
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push(
        c.isMine
            ? AppRoutes.courseCharts(c.id, classroomId: classroomId)
            : AppRoutes.classroomMastery(classroomId, courseId: c.id),
      ),
    );
  }
}
