import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../google_classroom/google_providers.dart';
import '../home/teacher_attention.dart' show teacherAttentionProvider;
import 'classroom.dart';
import 'classrooms_providers.dart';
import 'request_classroom_screen.dart';

/// "ห้องเรียน" tab of the teacher shell. It is a body only: the shell's
/// Scaffold shows [ClassroomsFab] for it, so the root ScaffoldMessenger has
/// a single Scaffold to put a SnackBar on (a nested Scaffold would show
/// every message twice).
class ClassroomsPage extends ConsumerWidget {
  const ClassroomsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classrooms = ref.watch(classroomsProvider);
    return AsyncView(
      value: classrooms,
      onRetry: () => ref.read(classroomsProvider.notifier).refresh(),
      data: (list) {
        if (list.isEmpty) {
          return const Column(
            children: [
              ContentColumn(
                padding: EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: SharedHomeroomBar(),
              ),
              Expanded(
                child: EmptyView(
                  icon: Icons.groups_outlined,
                  title: 'ยังไม่มีห้องเรียน',
                  message:
                      'สร้างห้องเรียน เพิ่มรายชื่อนักเรียน แล้วพิมพ์บัตร QR สำหรับเข้าสู่ระบบ',
                ),
              ),
              ContentColumn(
                padding: EdgeInsets.fromLTRB(16, 0, 16, 96),
                child: ClosedClassroomsSection(),
              ),
            ],
          );
        }
        return RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(closedClassroomsProvider);
            await ref.read(classroomsProvider.notifier).refresh();
          },
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
            child: ListView.builder(
              itemCount: list.length + 2,
              itemBuilder: (context, i) => i == 0
                  ? const SharedHomeroomBar()
                  : i == list.length + 1
                  ? const ClosedClassroomsSection()
                  : ClassroomCard(classroom: list[i - 1]),
            ),
          ),
        );
      },
    );
  }
}

/// One classroom of the list with the teacher's role in it (DESIGN §24.13).
class ClassroomCard extends StatelessWidget {
  const ClassroomCard({super.key, required this.classroom});

  final Classroom classroom;

  @override
  Widget build(BuildContext context) {
    final c = classroom;
    final closedAt = c.closedAt;
    return Card(
      key: ValueKey('classroom_card_${c.id}'),
      child: ListTile(
        leading: CircleAvatar(child: Text(gradeLevelLabel(c.gradeLevel))),
        title: Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(c.name),
            StatusChip(
              key: ValueKey('classroom_role_${c.id}'),
              label: c.myRole.label,
              color: c.isHomeroom
                  ? Theme.of(context).colorScheme.primary
                  : Theme.of(context).colorScheme.tertiary,
            ),
          ],
        ),
        subtitle: Text(
          [
            // A shared homeroom shows whose room it is (DESIGN §24.13).
            if (c.isSubject && c.homeroomTeacher != null)
              'ครูประจำชั้น ${c.homeroomTeacher!.name}',
            'ปีการศึกษา ${c.academicYear}',
            'รหัสห้อง ${c.classCode}',
            if (c.studentCount != null) 'นักเรียน ${c.studentCount} คน',
            if (closedAt != null)
              'ปิดเมื่อ ${formatThaiDate(closedAt.toLocal())}',
          ].join(' · '),
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => context.push(AppRoutes.classroom(c.id)),
      ),
    );
  }
}

/// Shared homerooms (DESIGN §24.7, §24.13) above the list: ask to teach
/// in another teacher's room, and the requests with a badge counting the
/// ones that wait for this teacher's decision.
class SharedHomeroomBar extends ConsumerWidget {
  const SharedHomeroomBar({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending =
        ref.watch(teacherAttentionProvider).value?.courseRequestsPending ?? 0;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          CourseRequestsButton(pending: pending),
          TextButton.icon(
            key: const ValueKey('request_other_classroom'),
            onPressed: () => context.push(AppRoutes.courseRequestNew()),
            icon: const Icon(Icons.group_add_outlined),
            label: const Text('ขอสอนห้องของครูท่านอื่น'),
          ),
        ],
      ),
    );
  }
}

/// "ห้องเก่า" (DESIGN §24.6, §24.13): the closed rooms, folded until the
/// teacher opens the section, which is when they are loaded.
class ClosedClassroomsSection extends StatelessWidget {
  const ClosedClassroomsSection({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: ExpansionTile(
        key: const ValueKey('closed_classrooms'),
        leading: const Icon(Icons.inventory_2_outlined),
        title: const Text('ห้องเก่า'),
        subtitle: const Text('ห้องที่ปิดแล้ว ดูผลและส่งออกได้ แก้ไขไม่ได้'),
        tilePadding: const EdgeInsets.symmetric(horizontal: 8),
        childrenPadding: EdgeInsets.zero,
        children: const [_ClosedClassroomsList()],
      ),
    );
  }
}

class _ClosedClassroomsList extends ConsumerWidget {
  const _ClosedClassroomsList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ref
        .watch(closedClassroomsProvider)
        .when(
          skipLoadingOnRefresh: true,
          loading: () => const Padding(
            padding: EdgeInsets.all(16),
            child: LinearProgressIndicator(),
          ),
          error: (e, _) => ListTile(
            title: Text(apiErrorMessage(e)),
            trailing: TextButton(
              onPressed: () => ref.invalidate(closedClassroomsProvider),
              child: const Text('ลองใหม่'),
            ),
          ),
          data: (list) => list.isEmpty
              ? const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text('ยังไม่มีห้องเก่า'),
                )
              : Column(
                  children: [for (final c in list) ClassroomCard(classroom: c)],
                ),
        );
  }
}

/// "สร้างห้องเรียน" button the shell shows while this tab is selected, with
/// "นำเข้าจาก Google Classroom" above it when the server has Google
/// Classroom set up (DESIGN §19.2).
class ClassroomsFab extends ConsumerWidget {
  const ClassroomsFab({super.key, this.inline = false});

  /// Buttons in a row for the page header of the desktop layout, instead
  /// of floating action buttons (DESIGN §27.3).
  final bool inline;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (inline) {
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (ref.watch(googleClassroomEnabledProvider)) ...[
            OutlinedButton.icon(
              key: const ValueKey('classroom_import_google'),
              onPressed: () => context.push(AppRoutes.classroomImportGoogle),
              icon: const Icon(Icons.cloud_download_outlined, size: 20),
              label: const Text('นำเข้าจาก Google Classroom'),
            ),
            const SizedBox(width: 8),
          ],
          FilledButton.icon(
            onPressed: () => context.push(AppRoutes.classroomNew),
            icon: const Icon(Icons.add, size: 20),
            label: const Text('สร้างห้องเรียน'),
          ),
        ],
      );
    }
    final create = FloatingActionButton.extended(
      heroTag: 'classroom_new',
      onPressed: () => context.push(AppRoutes.classroomNew),
      icon: const Icon(Icons.add),
      label: const Text('สร้างห้องเรียน'),
    );
    if (!ref.watch(googleClassroomEnabledProvider)) return create;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        FloatingActionButton.extended(
          key: const ValueKey('classroom_import_google'),
          heroTag: 'classroom_import_google',
          // The second action is quieter than "สร้างห้องเรียน" below it.
          backgroundColor: Theme.of(context).colorScheme.surfaceContainerLow,
          foregroundColor: Theme.of(context).colorScheme.primary,
          onPressed: () => context.push(AppRoutes.classroomImportGoogle),
          icon: const Icon(Icons.cloud_download_outlined),
          label: const Text('นำเข้าจาก Google Classroom'),
        ),
        const SizedBox(height: 12),
        create,
      ],
    );
  }
}
