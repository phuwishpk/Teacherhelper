import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../mastery/mastery_widgets.dart';
import 'practice_models.dart';
import 'practice_repository.dart';
import 'resource_links.dart';

/// What the attempt screen gets: the item and the ones after it in the
/// same skill, for "ข้อต่อไป".
class PracticeAttemptArgs {
  const PracticeAttemptArgs({required this.item, this.next = const []});

  final PracticeItem item;
  final List<PracticeItem> next;
}

/// Student "แบบฝึก" tab: practice picked by weak skill (DESIGN §14.1).
class PracticePage extends ConsumerWidget {
  const PracticePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final recs = ref.watch(practiceRecommendationsProvider);
    return AsyncView(
      value: recs,
      onRetry: () => ref.invalidate(practiceRecommendationsProvider),
      data: (list) {
        final withContent = list
            .where((r) => r.items.isNotEmpty || r.resources.isNotEmpty)
            .toList();
        return RefreshIndicator(
          onRefresh: () => ref.refresh(practiceRecommendationsProvider.future),
          child: withContent.isEmpty
              ? ListView(
                  children: const [
                    SizedBox(height: 48),
                    EmptyView(
                      icon: Icons.fitness_center_outlined,
                      title: 'ยังไม่มีแบบฝึกแนะนำ',
                      message:
                          'เมื่อครูเผยแพร่ผลการบ้านและอนุมัติแบบฝึกแล้ว ระบบจะแนะนำข้อฝึก'
                          'ตามทักษะที่ควรทบทวน ข้อที่ทำไปแล้วจะกลับมาอีกครั้งหลัง 7 วัน',
                    ),
                  ],
                )
              : ContentColumn(
                  child: ListView(
                    children: [
                      Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Text(
                          'ฝึกทักษะที่ยังไม่มั่นใจ พิมพ์คำตอบแล้วดูผลได้ทันที',
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                      ),
                      for (final r in withContent)
                        _RecommendationCard(recommendation: r),
                    ],
                  ),
                ),
        );
      },
    );
  }
}

class _RecommendationCard extends StatelessWidget {
  const _RecommendationCard({required this.recommendation});

  final PracticeRecommendation recommendation;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final r = recommendation;
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(r.skill.code, style: theme.textTheme.labelLarge),
                      if (r.skill.name.isNotEmpty)
                        Text(r.skill.name, style: theme.textTheme.titleSmall),
                    ],
                  ),
                ),
                if (r.mastery case final m?) ...[
                  const SizedBox(width: 8),
                  MasteryLevelChip(level: m.level),
                ],
              ],
            ),
            if (r.mastery case final m?) ...[
              const SizedBox(height: 8),
              MasteryBar(mastery: m),
            ],
            const SizedBox(height: 4),
            for (var i = 0; i < r.items.length; i++)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: CircleAvatar(radius: 16, child: Text('${i + 1}')),
                title: Text(
                  r.items[i].promptText,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                subtitle: Text(r.items[i].answerType.label),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(
                  AppRoutes.studentPractice(r.items[i].id),
                  extra: PracticeAttemptArgs(
                    item: r.items[i],
                    next: r.items.sublist(i + 1),
                  ),
                ),
              ),
            if (r.resources.isNotEmpty) ...[
              const Divider(),
              Text('ทบทวนเนื้อหา', style: theme.textTheme.labelLarge),
              for (final res in r.resources) ResourceLinkTile(resource: res),
            ],
          ],
        ),
      ),
    );
  }
}
