import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../charts/course_charts_screen.dart';
import 'mastery_models.dart';
import 'mastery_repository.dart';
import 'mastery_widgets.dart';

/// Student "ทักษะ" tab: mastery per skill (DESIGN §14.2), weakest first.
class MasteryPage extends ConsumerWidget {
  const MasteryPage({super.key, this.onPractice});

  /// Switches the shell to the practice tab.
  final VoidCallback? onPractice;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mastery = ref.watch(myMasteryProvider);
    return AsyncView(
      value: mastery,
      onRetry: () => ref.invalidate(myMasteryProvider),
      data: (list) {
        if (!list.available || list.rows.isEmpty) {
          return RefreshIndicator(
            onRefresh: () => ref.refresh(myMasteryProvider.future),
            child: ListView(
              children: const [
                MyCoursesSection(),
                SizedBox(height: 48),
                EmptyView(
                  icon: Icons.insights_outlined,
                  title: 'ยังไม่มีข้อมูลทักษะ',
                  message:
                      'ความก้าวหน้าของแต่ละทักษะจะแสดงหลังครูเผยแพร่ผลการบ้าน '
                      'หรือหลังทำแบบฝึก',
                ),
              ],
            ),
          );
        }
        final rows = SkillMastery.weakestFirst(list.rows);
        final counts = <MasteryLevel, int>{
          for (final level in MasteryLevel.values)
            level: rows.where((r) => r.level == level).length,
        };
        final needsWork =
            counts[MasteryLevel.notYet]! + counts[MasteryLevel.partial]!;
        final theme = Theme.of(context);
        return RefreshIndicator(
          onRefresh: () => ref.refresh(myMasteryProvider.future),
          child: ContentColumn(
            child: ListView(
              children: [
                const MyCoursesSection(),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('ภาพรวมทักษะ', style: theme.textTheme.titleMedium),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final level in MasteryLevel.values)
                              if (counts[level]! > 0)
                                Chip(
                                  avatar: Icon(
                                    level.icon,
                                    size: 18,
                                    color: level.color(context),
                                  ),
                                  label: Text(
                                    '${level.label} ${counts[level]} ทักษะ',
                                  ),
                                ),
                          ],
                        ),
                        if (needsWork > 0 && onPractice != null) ...[
                          const SizedBox(height: 8),
                          FilledButton.tonalIcon(
                            onPressed: onPractice,
                            icon: const Icon(Icons.fitness_center_outlined),
                            label: const Text('ฝึกทักษะที่ควรทบทวน'),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                const MasteryLegend(),
                const SizedBox(height: 12),
                for (final m in rows) SkillMasteryCard(mastery: m),
              ],
            ),
          ),
        );
      },
    );
  }
}

/// One skill: code, name, level, bar and how much data it rests on.
class SkillMasteryCard extends StatelessWidget {
  const SkillMasteryCard({super.key, required this.mastery});

  final SkillMastery mastery;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final m = mastery;
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
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
                      Text(m.skill.code, style: theme.textTheme.labelLarge),
                      if (m.skill.name.isNotEmpty)
                        Text(m.skill.name, style: theme.textTheme.bodyMedium),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                MasteryLevelChip(level: m.level),
              ],
            ),
            const SizedBox(height: 10),
            MasteryBar(mastery: m),
            const SizedBox(height: 6),
            Text(
              [
                percent(m.value),
                m.level == MasteryLevel.tooLittle
                    ? 'ข้อมูลยังน้อย (${m.nObs} ครั้ง)'
                    : 'จาก ${m.nObs} ครั้ง',
                if (m.updatedAt != null)
                  'อัปเดต ${formatThaiDate(m.updatedAt!)}',
              ].join(' · '),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
