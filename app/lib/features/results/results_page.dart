import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../review/review_labels.dart';
import 'results_repository.dart';
import 'retake_notice.dart';
import 'student_result.dart' show totalOverriddenNote;

/// "ผลการบ้าน" tab: published results only (DESIGN §13), plus a notice for
/// each Classroom submission the teacher sent back for a new photo (§18.2).
/// Tapping a result opens the per-question detail (crop, explanation,
/// appeal).
class ResultsPage extends ConsumerWidget {
  const ResultsPage({super.key});

  Future<void> _refresh(WidgetRef ref) async {
    ref.invalidate(studentRetakeRequestsProvider);
    await ref.read(studentResultsProvider.notifier).refresh();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
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
      onRetry: () => _refresh(ref),
      data: (list) {
        if (list.isEmpty && notices.isEmpty) {
          return const EmptyView(
            icon: Icons.inbox_outlined,
            title: 'ยังไม่มีผลการบ้าน',
            message:
                'เมื่อครูตรวจและเผยแพร่ผลแล้ว จะเห็นคะแนนและจุดที่ผิดที่นี่',
          );
        }
        return RefreshIndicator(
          onRefresh: () => _refresh(ref),
          child: ContentColumn(
            child: ListView.builder(
              itemCount: notices.length + list.length,
              itemBuilder: (context, index) {
                if (index < notices.length) return notices[index];
                final r = list[index - notices.length];
                final score = r.totalScore == null
                    ? null
                    : r.maxScore == null
                    ? formatScore(r.totalScore)
                    : '${formatScore(r.totalScore)}/${formatScore(r.maxScore)}';
                return Card(
                  child: ListTile(
                    leading: const Icon(Icons.assignment_turned_in_outlined),
                    title: Text(r.title),
                    subtitle: Text(
                      [
                        if (r.subjectName != null) r.subjectName!,
                        if (r.publishedAt != null)
                          'เผยแพร่ ${formatThaiDate(r.publishedAt!)}',
                        if (r.totalOverridden) totalOverriddenNote,
                      ].join(' · '),
                    ),
                    trailing: score == null
                        ? null
                        : Text(
                            score,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                    onTap: () =>
                        context.push(AppRoutes.studentResult(r.submissionId)),
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
