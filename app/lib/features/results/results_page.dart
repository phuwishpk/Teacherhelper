import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../review/review_labels.dart';
import '../student/student_labels.dart';
import 'results_repository.dart';
import 'retake_notice.dart';
import 'student_result.dart';

/// "ผลการบ้าน" tab: published results only (DESIGN §13) of every classroom
/// of the student, grouped by subject with the classroom label (§24.11) and
/// newest first within a subject; chips switch to one subject. A notice
/// heads the list for each Classroom submission the teacher sent back for a
/// new photo (§18.2). Tapping a result opens the per-question detail (crop,
/// explanation, appeal).
class ResultsPage extends ConsumerStatefulWidget {
  const ResultsPage({super.key});

  @override
  ConsumerState<ResultsPage> createState() => _ResultsPageState();
}

class _ResultsPageState extends ConsumerState<ResultsPage> {
  /// The [SubjectTag.key] shown, or null for every subject.
  String? _subject;

  Future<void> _refresh() async {
    ref.invalidate(studentRetakeRequestsProvider);
    await ref.read(studentResultsProvider.notifier).refresh();
  }

  @override
  Widget build(BuildContext context) {
    final results = ref.watch(studentResultsProvider);
    // Supplementary: an error just shows no notice.
    final retakes = ref.watch(studentRetakeRequestsProvider).value ?? const [];
    final notices = [
      for (final r in retakes)
        RetakeNotice(
          key: ValueKey('retake_${r.id}'),
          title: r.title,
          reason: r.reason,
          requestedAt: r.requestedAt,
          alternateLink: r.alternateLink,
        ),
    ];
    return AsyncView(
      value: results,
      onRetry: _refresh,
      data: (list) {
        if (list.isEmpty && notices.isEmpty) {
          return const EmptyView(
            icon: Icons.inbox_outlined,
            title: 'ยังไม่มีผลการบ้าน',
            message:
                'เมื่อครูตรวจและเผยแพร่ผลแล้ว จะเห็นคะแนนและจุดที่ผิดที่นี่',
          );
        }
        final sections = groupBySubject(list, (r) => r.tag);
        final tags = [for (final s in sections) s.tag];
        final selected = tags.any((t) => t.key == _subject) ? _subject : null;
        return RefreshIndicator(
          onRefresh: _refresh,
          child: ContentColumn(
            child: ListView(
              children: [
                ...notices,
                SubjectFilterBar(
                  tags: tags,
                  selected: selected,
                  onSelected: (key) => setState(() => _subject = key),
                ),
                for (final section in sections)
                  if (selected == null || section.tag.key == selected) ...[
                    SubjectSectionHeader(tag: section.tag),
                    for (final r in section.items) StudentResultTile(result: r),
                  ],
              ],
            ),
          ),
        );
      },
    );
  }
}

/// One published result: title, publish date and the total.
class StudentResultTile extends StatelessWidget {
  const StudentResultTile({super.key, required this.result});

  final StudentResult result;

  @override
  Widget build(BuildContext context) {
    final r = result;
    final score = r.totalScore == null
        ? null
        : r.maxScore == null
        ? formatScore(r.totalScore)
        : '${formatScore(r.totalScore)}/${formatScore(r.maxScore)}';
    final subtitle = [
      if (r.publishedAt != null) 'เผยแพร่ ${formatThaiDate(r.publishedAt!)}',
      if (r.totalOverridden) totalOverriddenNote,
    ].join(' · ');
    return Card(
      key: ValueKey('student_result_${r.submissionId}'),
      child: ListTile(
        leading: const Icon(Icons.assignment_turned_in_outlined),
        title: Text(r.title),
        subtitle: subtitle.isEmpty ? null : Text(subtitle),
        trailing: score == null
            ? null
            : Text(score, style: Theme.of(context).textTheme.titleLarge),
        onTap: () => context.push(AppRoutes.studentResult(r.submissionId)),
      ),
    );
  }
}
