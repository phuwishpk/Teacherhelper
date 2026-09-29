import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'assignment.dart';
import 'assignments_providers.dart';

String assignmentStatusLabel(String status) => switch (status) {
  'draft' => 'ร่าง',
  'ready' => 'พร้อมใช้',
  'closed' => 'ปิดแล้ว',
  _ => status,
};

Color assignmentStatusColor(BuildContext context, String status) {
  final scheme = Theme.of(context).colorScheme;
  return switch (status) {
    'ready' => Colors.green.shade700,
    'closed' => scheme.outline,
    _ => scheme.secondary,
  };
}

/// "การบ้าน" tab of the teacher shell. A body only, like `ClassroomsPage`:
/// the shell's Scaffold shows [AssignmentsFab] for it.
class AssignmentsPage extends ConsumerStatefulWidget {
  const AssignmentsPage({super.key});

  @override
  ConsumerState<AssignmentsPage> createState() => _AssignmentsPageState();
}

class _AssignmentsPageState extends ConsumerState<AssignmentsPage> {
  int? _classroomFilter;

  @override
  Widget build(BuildContext context) {
    final assignments = ref.watch(assignmentsProvider);
    final classrooms = ref.watch(classroomsProvider).value ?? const [];
    final classroomNames = {for (final c in classrooms) c.id: c.name};

    return AsyncView(
      value: assignments,
      onRetry: () => ref.read(assignmentsProvider.notifier).refresh(),
      data: (list) {
        if (list.isEmpty) {
          return const EmptyView(
            icon: Icons.assignment_outlined,
            title: 'ยังไม่มีการบ้าน',
            message: 'สร้างการบ้าน เลือกตัวชี้วัด แล้วพิมพ์ใบงานแยกรายนักเรียน',
          );
        }
        final visible = _classroomFilter == null
            ? list
            : list.where((a) => a.classroomId == _classroomFilter).toList();
        return RefreshIndicator(
          onRefresh: () => ref.read(assignmentsProvider.notifier).refresh(),
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
            child: ListView(
              children: [
                if (classrooms.length > 1)
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        ChoiceChip(
                          label: const Text('ทุกห้อง'),
                          selected: _classroomFilter == null,
                          onSelected: (_) =>
                              setState(() => _classroomFilter = null),
                        ),
                        for (final c in classrooms) ...[
                          const SizedBox(width: 8),
                          ChoiceChip(
                            label: Text(c.name),
                            selected: _classroomFilter == c.id,
                            onSelected: (_) =>
                                setState(() => _classroomFilter = c.id),
                          ),
                        ],
                      ],
                    ),
                  ),
                const SizedBox(height: 8),
                for (final a in visible)
                  _AssignmentCard(
                    assignment: a,
                    classroomName:
                        a.classroomName ?? classroomNames[a.classroomId],
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

/// "สร้างการบ้าน" button the shell shows while this tab is selected.
class AssignmentsFab extends StatelessWidget {
  const AssignmentsFab({super.key});

  @override
  Widget build(BuildContext context) {
    return FloatingActionButton.extended(
      heroTag: 'assignment_new',
      onPressed: () => context.push(AppRoutes.assignmentNew),
      icon: const Icon(Icons.add),
      label: const Text('สร้างการบ้าน'),
    );
  }
}

class _AssignmentCard extends StatelessWidget {
  const _AssignmentCard({required this.assignment, this.classroomName});

  final Assignment assignment;
  final String? classroomName;

  @override
  Widget build(BuildContext context) {
    final a = assignment;
    final scheme = Theme.of(context).colorScheme;
    final parts = [
      ?classroomName,
      ?(a.courseLabel ?? a.subjectName),
      if (a.dueAt != null) 'ส่ง ${formatThaiDate(a.dueAt!)}',
      if (a.currentLayoutVersion != null) 'layout v${a.currentLayoutVersion}',
    ];
    final chips = [
      if (a.fromClassroomWeb)
        StatusChip(label: 'สร้างในเว็บ Classroom', color: scheme.tertiary),
      if (a.isFreeform && !a.keyApproved && a.status != 'closed')
        StatusChip(label: 'รออนุมัติเฉลย', color: scheme.error),
    ];
    return Card(
      key: ValueKey('assignment_card_${a.id}'),
      child: ListTile(
        title: Text(a.title),
        subtitle: parts.isEmpty && chips.isEmpty
            ? null
            : Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (parts.isNotEmpty) Text(parts.join(' · ')),
                  if (chips.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Wrap(spacing: 6, runSpacing: 4, children: chips),
                  ],
                ],
              ),
        trailing: StatusChip(
          label: assignmentStatusLabel(a.status),
          color: assignmentStatusColor(context, a.status),
        ),
        onTap: () => context.push(AppRoutes.assignment(a.id)),
      ),
    );
  }
}
