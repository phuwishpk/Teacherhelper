import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'hand_in_models.dart';
import 'hand_in_repository.dart';

/// Student tab "ส่งงาน" (DESIGN §19.6, §19.11): the ready assignments of the
/// student's classrooms, soonest due first. Each opens the hand-in screen;
/// a published one opens its result instead.
class StudentAssignmentsPage extends ConsumerWidget {
  const StudentAssignmentsPage({super.key, this.now});

  /// Clock for the overdue labels (tests).
  final DateTime Function()? now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(studentAssignmentsProvider);
    Future<void> refresh() => ref.refresh(studentAssignmentsProvider.future);
    return AsyncView(
      value: list,
      onRetry: () => ref.invalidate(studentAssignmentsProvider),
      data: (items) {
        if (items.isEmpty) {
          return RefreshIndicator(
            onRefresh: refresh,
            child: ListView(
              children: const [
                EmptyView(
                  icon: Icons.task_alt,
                  title: 'ยังไม่มีงานที่ต้องส่ง',
                  message:
                      'เมื่อครูเปิดรับงาน การบ้านจะขึ้นที่นี่ '
                      'ส่งได้ด้วยการถ่ายรูปหรือเลือกไฟล์',
                ),
              ],
            ),
          );
        }
        final time = (now ?? DateTime.now)();
        return RefreshIndicator(
          onRefresh: refresh,
          child: ContentColumn(
            child: ListView.builder(
              itemCount: items.length,
              itemBuilder: (context, i) =>
                  _AssignmentCard(assignment: items[i], now: time),
            ),
          ),
        );
      },
    );
  }
}

class _AssignmentCard extends StatelessWidget {
  const _AssignmentCard({required this.assignment, required this.now});

  final StudentAssignment assignment;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final a = assignment;
    final scheme = Theme.of(context).colorScheme;
    final chips = studentAssignmentChips(a, now, scheme);
    return Card(
      key: ValueKey('student_assignment_${a.id}'),
      child: ListTile(
        leading: Icon(
          a.isSubmitted ? Icons.task_alt : Icons.assignment_outlined,
          color: a.isSubmitted ? scheme.primary : null,
        ),
        title: Text(a.title),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              [
                ?a.subjectName,
                ?a.classroomName,
                if (a.dueAt != null)
                  'กำหนดส่ง ${formatThaiDateTime(a.dueAt!)}'
                else
                  'ไม่มีกำหนดส่ง',
              ].join(' · '),
            ),
            if (chips.isNotEmpty) ...[
              const SizedBox(height: 6),
              Wrap(spacing: 6, runSpacing: 4, children: chips),
            ],
          ],
        ),
        isThreeLine: chips.isNotEmpty,
        trailing: const Icon(Icons.chevron_right),
        onTap: () {
          final submissionId = a.submissionId;
          if (a.isPublished && submissionId != null) {
            context.push(AppRoutes.studentResult(submissionId));
          } else {
            context.push(AppRoutes.studentHandIn(a.id), extra: a);
          }
        },
      ),
    );
  }
}

/// "ส่งแล้ว", "ส่งช้า", "เลยกำหนด", "ปิดรับแล้ว", "ประกาศผลแล้ว".
List<Widget> studentAssignmentChips(
  StudentAssignment a,
  DateTime now,
  ColorScheme scheme,
) => [
  if (a.isPublished)
    StatusChip(label: 'ประกาศผลแล้ว', color: scheme.primary)
  else if (a.isSubmitted)
    StatusChip(
      label: a.submittedAt == null
          ? 'ส่งแล้ว'
          : 'ส่งแล้ว ${formatThaiDateTime(a.submittedAt!)}',
      color: scheme.primary,
    ),
  if (a.late) StatusChip(label: 'ส่งช้า', color: scheme.error),
  if (!a.isSubmitted && !a.canSubmit)
    StatusChip(label: 'ปิดรับแล้ว', color: scheme.error)
  else if (!a.isSubmitted && a.isOverdue(now))
    StatusChip(label: 'เลยกำหนด ส่งได้แต่จะติดป้ายส่งช้า', color: scheme.error),
];
