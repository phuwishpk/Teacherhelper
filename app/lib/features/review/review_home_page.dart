import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_page.dart';
import '../assignments/assignments_providers.dart';
import '../classrooms/classrooms_providers.dart';
import 'review_providers.dart';

/// "ตรวจทาน" tab of the teacher shell: open appeals and the assignments
/// whose worksheets can be reviewed (everything past `draft`).
class ReviewHomePage extends ConsumerWidget {
  const ReviewHomePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final assignments = ref.watch(assignmentsProvider);
    final appeals = ref.watch(openAppealsProvider);
    final classrooms = ref.watch(classroomsProvider).value ?? const [];
    final classroomNames = {for (final c in classrooms) c.id: c.name};
    final theme = Theme.of(context);

    return AsyncView(
      value: assignments,
      onRetry: () => ref.read(assignmentsProvider.notifier).refresh(),
      data: (list) {
        final reviewable = list.where((a) => !a.isDraft).toList();
        final openAppeals = appeals.value?.length ?? 0;
        return RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(openAppealsProvider);
            await ref.read(assignmentsProvider.notifier).refresh();
          },
          child: ContentColumn(
            child: ListView(
              children: [
                Card(
                  child: ListTile(
                    leading: Badge.count(
                      count: openAppeals,
                      isLabelVisible: openAppeals > 0,
                      child: const Icon(Icons.feedback_outlined),
                    ),
                    title: const Text('คำขอให้ตรวจใหม่'),
                    subtitle: Text(
                      appeals.hasError
                          ? 'โหลดคำขอไม่ได้ แตะเพื่อลองอีกครั้ง'
                          : openAppeals == 0
                          ? 'ไม่มีคำขอที่รอตอบ'
                          : 'รอตอบ $openAppeals รายการ',
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push(AppRoutes.appeals),
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'เลือกการบ้านที่จะตรวจทาน',
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                if (reviewable.isEmpty)
                  const EmptyView(
                    icon: Icons.rate_review_outlined,
                    title: 'ไม่มีงานรอตรวจทาน',
                    message:
                        'เมื่อสแกนใบงานแล้ว ระบบจะตรวจให้ก่อนและเรียงข้อที่ควรดูไว้ที่นี่ '
                        'นักเรียนจะเห็นผลหลังคุณกดเผยแพร่',
                  ),
                for (final a in reviewable)
                  Card(
                    child: ListTile(
                      title: Text(a.title),
                      subtitle: Text(
                        [
                          ?(a.classroomName ?? classroomNames[a.classroomId]),
                          if (a.dueAt != null)
                            'ส่ง ${formatThaiDate(a.dueAt!)}',
                          if (a.needsReviewCount case final n? when n > 0)
                            'รอตรวจทาน $n ข้อ',
                        ].join(' · '),
                      ),
                      trailing: StatusChip(
                        label: assignmentStatusLabel(a.status),
                        color: assignmentStatusColor(context, a.status),
                      ),
                      onTap: () => context.push(AppRoutes.review(a.id)),
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
