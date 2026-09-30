import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../google_classroom/google_providers.dart';
import 'classrooms_providers.dart';

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
          return const EmptyView(
            icon: Icons.groups_outlined,
            title: 'ยังไม่มีห้องเรียน',
            message:
                'สร้างห้องเรียน เพิ่มรายชื่อนักเรียน แล้วพิมพ์บัตร QR สำหรับเข้าสู่ระบบ',
          );
        }
        return RefreshIndicator(
          onRefresh: () => ref.read(classroomsProvider.notifier).refresh(),
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
            child: ListView.builder(
              itemCount: list.length,
              itemBuilder: (context, i) {
                final c = list[i];
                return Card(
                  child: ListTile(
                    leading: CircleAvatar(
                      child: Text(gradeLevelLabel(c.gradeLevel)),
                    ),
                    title: Text(c.name),
                    subtitle: Text(
                      'ปีการศึกษา ${c.academicYear} · รหัสห้อง ${c.classCode}'
                      '${c.studentCount != null ? ' · นักเรียน ${c.studentCount} คน' : ''}',
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push(AppRoutes.classroom(c.id)),
                  ),
                );
              },
            ),
          ),
        );
      },
    );
  }
}

/// "สร้างห้องเรียน" button the shell shows while this tab is selected, with
/// "นำเข้าจาก Google Classroom" above it when the server has Google
/// Classroom set up (DESIGN §19.2).
class ClassroomsFab extends ConsumerWidget {
  const ClassroomsFab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
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
