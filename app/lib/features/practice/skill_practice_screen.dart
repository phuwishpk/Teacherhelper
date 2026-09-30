import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/question.dart';
import 'practice_page.dart';
import 'practice_repository.dart';

/// Student: the practice of one indicator, opened from a next step of the
/// teacher's shared analysis (DESIGN §20.5). The items come from the
/// student's own recommendations (`GET /student/practice`), so the page
/// shows what the "แบบฝึก" tab would show for this indicator.
class SkillPracticeScreen extends ConsumerWidget {
  const SkillPracticeScreen({super.key, required this.skillId, this.skill});

  final int skillId;

  /// The indicator as the analysis showed it, for the title.
  final Skill? skill;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final recs = ref.watch(practiceRecommendationsProvider);
    final found = recs.value?.where((r) => r.skill.id == skillId).firstOrNull;
    final title = (found?.skill ?? skill)?.code ?? 'แบบฝึก';
    return Scaffold(
      appBar: AppBar(title: Text('ฝึก $title')),
      body: AsyncView(
        value: recs,
        onRetry: () => ref.invalidate(practiceRecommendationsProvider),
        data: (list) {
          final r = list.where((r) => r.skill.id == skillId).firstOrNull;
          return RefreshIndicator(
            onRefresh: () =>
                ref.refresh(practiceRecommendationsProvider.future),
            child: r == null || (r.items.isEmpty && r.resources.isEmpty)
                ? ListView(
                    children: const [
                      SizedBox(height: 48),
                      EmptyView(
                        icon: Icons.fitness_center_outlined,
                        title: 'ตอนนี้ยังไม่มีข้อฝึกของตัวชี้วัดนี้',
                        message:
                            'ถ้าเพิ่งทำครบแล้ว ข้อฝึกจะกลับมาอีกครั้งหลัง 7 วัน '
                            'หรือทักษะนี้อยู่ในระดับเข้าใจดีแล้ว เก่งมาก!',
                      ),
                    ],
                  )
                : ContentColumn(
                    child: ListView(
                      children: [PracticeRecommendationCard(recommendation: r)],
                    ),
                  ),
          );
        },
      ),
    );
  }
}
