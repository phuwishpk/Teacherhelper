import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'gradebook_models.dart';
import 'gradebook_providers.dart';

/// Student "เกรดของฉัน" (DESIGN §23.7, §23.9, §24.11): the courses whose
/// grades the teacher published, newest first, each with its classroom. Only the student's own grade; no class
/// average or ranking (§23.12).
class MyGradesPage extends ConsumerWidget {
  const MyGradesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final grades = ref.watch(myGradesProvider);
    return AsyncView(
      value: grades,
      onRetry: () => ref.invalidate(myGradesProvider),
      data: (list) {
        if (list.isEmpty) {
          return const EmptyView(
            icon: Icons.school_outlined,
            title: 'ยังไม่มีเกรดที่ประกาศ',
            message:
                'เมื่อครูประกาศเกรดของรายวิชาแล้ว จะเห็นเกรดและคะแนนที่นี่',
          );
        }
        return RefreshIndicator(
          onRefresh: () => ref.refresh(myGradesProvider.future),
          child: ContentColumn(
            child: ListView(
              children: [
                for (final g in list)
                  Card(
                    child: ListTile(
                      key: ValueKey(
                        g.classroomId == null
                            ? 'my_grade_${g.courseId}'
                            : 'my_grade_${g.courseId}_${g.classroomId}',
                      ),
                      leading: const Icon(Icons.school_outlined),
                      title: Text(g.courseTitle),
                      subtitle: Text(
                        [
                          ?g.classroom?.text,
                          if (g.totalRounded != null)
                            'คะแนนรวม ${g.totalRounded}',
                          if (g.publishedAt != null)
                            'ประกาศ ${formatThaiDate(g.publishedAt!)}',
                        ].join(' · '),
                      ),
                      trailing: GradeBadge(text: g.gradeText),
                      onTap: () => context.push(
                        AppRoutes.myCourseGrade(
                          g.courseId,
                          classroomId: g.classroomId,
                        ),
                      ),
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

/// `/student/grades`: [MyGradesPage] on its own screen, opened from
/// "วิชาของฉัน" (DESIGN §24.13).
class MyGradesScreen extends StatelessWidget {
  const MyGradesScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('เกรดของฉัน')),
    body: const MyGradesPage(),
  );
}

/// A grade ("3", "ร", "–") in a filled box.
class GradeBadge extends StatelessWidget {
  const GradeBadge({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      constraints: const BoxConstraints(minWidth: 48),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        text.isEmpty ? '–' : text,
        textAlign: TextAlign.center,
        style: theme.textTheme.titleLarge?.copyWith(
          color: theme.colorScheme.onPrimaryContainer,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/// `/student/courses/:id/grade?classroom=`: the student's published grade
/// of one course with each category (percent and weighted points) and its
/// items (score out of full marks, "ยกเว้น", "ตัดออก").
class StudentGradeScreen extends ConsumerWidget {
  const StudentGradeScreen({
    super.key,
    required this.courseId,
    this.classroomId,
  });

  final int courseId;

  /// One classroom's publication; null = the newest (DESIGN §24.26).
  final int? classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(
      myCourseGradeProvider((courseId: courseId, classroomId: classroomId)),
    );
    return Scaffold(
      appBar: AppBar(
        title: Text(detail.value?.summary.courseTitle ?? 'เกรดของฉัน'),
      ),
      body: StudentGradeBody(courseId: courseId, classroomId: classroomId),
    );
  }
}

/// The grade card and the breakdown of one course, also the "เกรด" tab of
/// a subject in "วิชาของฉัน" (DESIGN §24.13).
class StudentGradeBody extends ConsumerWidget {
  const StudentGradeBody({super.key, required this.courseId, this.classroomId});

  final int courseId;
  final int? classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final query = (courseId: courseId, classroomId: classroomId);
    final detail = ref.watch(myCourseGradeProvider(query));
    final theme = Theme.of(context);
    return detail.when(
      skipLoadingOnRefresh: true,
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => apiStatusCode(e) == 404
          ? const EmptyView(
              icon: Icons.hourglass_empty,
              title: 'ยังไม่ได้ประกาศเกรด',
              message: 'ครูยังไม่ได้ประกาศเกรดของรายวิชานี้',
            )
          : ErrorView(
              message: apiErrorMessage(e),
              onRetry: () => ref.invalidate(myCourseGradeProvider(query)),
            ),
      data: (d) => ContentColumn(
        child: ListView(
          padding: const EdgeInsets.only(bottom: 24),
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    GradeBadge(text: d.summary.gradeText),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            d.summary.gradeText.isEmpty
                                ? 'ยังไม่มีเกรด'
                                : 'เกรด ${d.summary.gradeText}',
                            style: theme.textTheme.titleMedium,
                          ),
                          if (d.total != null)
                            Text(
                              'คะแนนรวม ${formatGbNumber(d.total)} '
                              '(ปัดเป็น ${d.summary.totalRounded ?? '–'})',
                              key: const ValueKey('my_grade_total'),
                            ),
                          if (d.summary.classroom != null)
                            Text('ห้อง ${d.summary.classroom!.text}'),
                          if (d.summary.publishedAt != null)
                            Text(
                              'ประกาศ ${formatThaiDateTime(d.summary.publishedAt!)}',
                              style: theme.textTheme.bodySmall,
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            for (final c in d.breakdown) _CategoryCard(category: c),
          ],
        ),
      ),
    );
  }
}

class _CategoryCard extends StatelessWidget {
  const _CategoryCard({required this.category});

  final BreakdownCategory category;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = category;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        key: ValueKey('my_category_${c.name}'),
        title: Text('${c.name} (${formatGbNumber(c.weight)}%)'),
        subtitle: Text(
          c.percent == null
              ? 'ไม่มีคะแนนในหมวดนี้'
              : 'ได้ ${formatGbNumber(c.percent)}% '
                    '= ${formatGbNumber(c.points)} จาก ${formatGbNumber(c.weight)} คะแนน',
        ),
        children: [
          for (final i in c.items)
            ListTile(
              dense: true,
              title: Text(i.name),
              subtitle: _itemNote(i) == null ? null : Text(_itemNote(i)!),
              trailing: Text(
                i.state == CellState.excused
                    ? 'ยกเว้น'
                    : i.score == null
                    ? '0/${formatGbNumber(i.max)}'
                    : '${formatGbNumber(i.score)}/${formatGbNumber(i.max)}',
                style: i.dropped
                    ? TextStyle(
                        decoration: TextDecoration.lineThrough,
                        color: theme.colorScheme.outline,
                      )
                    : theme.textTheme.titleSmall,
              ),
            ),
        ],
      ),
    );
  }

  static String? _itemNote(BreakdownItem i) {
    final notes = [
      if (i.state == CellState.missing) 'ไม่ส่ง / ไม่มีคะแนน',
      if (i.state == CellState.pending) 'รอประกาศผล (ไม่นับ)',
      if (i.state == CellState.notDue) 'ยังไม่ถึงกำหนด (ไม่นับ)',
      if (i.dropped) 'ตัดออก (คะแนนต่ำสุดของหมวด)',
    ];
    return notes.isEmpty ? null : notes.join(' · ');
  }
}
