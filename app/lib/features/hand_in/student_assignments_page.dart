import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../student/student_labels.dart';
import 'hand_in_models.dart';
import 'hand_in_repository.dart';

/// Student tab "ส่งงาน" (DESIGN §19.6, §19.11, §24.11): the ready
/// assignments of every classroom of the student in one list, grouped by
/// subject with the classroom label, soonest due first within a subject;
/// chips switch to one subject. Each opens the hand-in screen; a published
/// one opens its result instead.
class StudentAssignmentsPage extends ConsumerStatefulWidget {
  const StudentAssignmentsPage({super.key, this.now});

  /// Clock for the overdue labels (tests).
  final DateTime Function()? now;

  @override
  ConsumerState<StudentAssignmentsPage> createState() =>
      _StudentAssignmentsPageState();
}

class _StudentAssignmentsPageState
    extends ConsumerState<StudentAssignmentsPage> {
  /// The [SubjectTag.key] shown, or null for every subject.
  String? _subject;

  @override
  Widget build(BuildContext context) {
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
        final time = (widget.now ?? DateTime.now)();
        final sections = groupBySubject(items, (a) => a.tag);
        final tags = [for (final s in sections) s.tag];
        final selected = tags.any((t) => t.key == _subject) ? _subject : null;
        return RefreshIndicator(
          onRefresh: refresh,
          child: ContentColumn(
            child: ListView(
              children: [
                SubjectFilterBar(
                  tags: tags,
                  selected: selected,
                  onSelected: (key) => setState(() => _subject = key),
                ),
                for (final section in sections)
                  if (selected == null || section.tag.key == selected) ...[
                    SubjectSectionHeader(tag: section.tag),
                    for (final a in section.items)
                      StudentAssignmentCard(assignment: a, now: time),
                  ],
              ],
            ),
          ),
        );
      },
    );
  }
}

/// One assignment of the student: due time, hand-in labels; opens the
/// hand-in screen or, once published, the result.
class StudentAssignmentCard extends StatelessWidget {
  const StudentAssignmentCard({
    super.key,
    required this.assignment,
    required this.now,
  });

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
              a.dueAt != null
                  ? 'กำหนดส่ง ${formatThaiDateTime(a.dueAt!)}'
                  : 'ไม่มีกำหนดส่ง',
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

/// "ส่งแล้ว", "ส่งช้า", "เลยกำหนด", "ปิดรับแล้ว", "ห้องเก่า", "ประกาศผลแล้ว".
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
  if (!a.isSubmitted && !a.canSubmit && (a.classroom?.closed ?? false))
    StatusChip(label: 'ห้องเก่า ส่งไม่ได้แล้ว', color: scheme.outline)
  else if (!a.isSubmitted && !a.canSubmit)
    StatusChip(label: 'ปิดรับแล้ว', color: scheme.error)
  else if (!a.isSubmitted && a.isOverdue(now))
    StatusChip(label: 'เลยกำหนด ส่งได้แต่จะติดป้ายส่งช้า', color: scheme.error),
];
