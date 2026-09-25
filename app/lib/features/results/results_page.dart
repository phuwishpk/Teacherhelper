import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'results_repository.dart';

/// "ผลการบ้าน" tab: published results only (DESIGN §13). The per-question
/// detail (crop, explanation, appeal) is added in Phase 4.
class ResultsPage extends ConsumerWidget {
  const ResultsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final results = ref.watch(studentResultsProvider);
    return AsyncView(
      value: results,
      onRetry: () => ref.read(studentResultsProvider.notifier).refresh(),
      data: (list) {
        if (list.isEmpty) {
          return const EmptyView(
            icon: Icons.inbox_outlined,
            title: 'ยังไม่มีผลการบ้าน',
            message:
                'เมื่อครูตรวจและเผยแพร่ผลแล้ว จะเห็นคะแนนและจุดที่ผิดที่นี่',
          );
        }
        return RefreshIndicator(
          onRefresh: () => ref.read(studentResultsProvider.notifier).refresh(),
          child: ContentColumn(
            child: ListView.builder(
              itemCount: list.length,
              itemBuilder: (context, i) {
                final r = list[i];
                final score = r.totalScore == null
                    ? null
                    : r.maxScore == null
                    ? '${r.totalScore}'
                    : '${r.totalScore}/${r.maxScore}';
                return Card(
                  child: ListTile(
                    leading: const Icon(Icons.assignment_turned_in_outlined),
                    title: Text(r.title),
                    subtitle: Text(
                      [
                        if (r.subjectName != null) r.subjectName!,
                        if (r.publishedAt != null)
                          'เผยแพร่ ${formatThaiDate(r.publishedAt!)}',
                      ].join(' · '),
                    ),
                    trailing: score == null
                        ? null
                        : Text(
                            score,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                    onTap: () => showMessage(
                      context,
                      'รายละเอียดรายข้อจะเปิดใช้ในขั้นถัดไป',
                    ),
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
