import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import 'analysis_models.dart';
import 'analysis_repository.dart';

/// The student's "ข้อความจากครู" at the top of the "ทักษะ" tab: only the
/// texts the teacher approved (or auto-shared), one per classroom, with
/// links to practise the next steps (DESIGN §20.5, §20.9). No teacher
/// version, no class average, no ranking. Nothing when there is none.
class MyAnalysisSection extends ConsumerWidget {
  const MyAnalysisSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(myAnalysesProvider).value ?? const <MyAnalysis>[];
    final shown = rows.where((r) => r.text.trim().isNotEmpty).toList();
    if (shown.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [for (final r in shown) _MyAnalysisCard(analysis: r)],
    );
  }
}

class _MyAnalysisCard extends StatelessWidget {
  const _MyAnalysisCard({required this.analysis});

  final MyAnalysis analysis;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final a = analysis;
    return Card(
      key: ValueKey('my_analysis_${a.classroomId}'),
      color: scheme.primaryContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.emoji_objects_outlined, color: scheme.primary),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    a.classroomName.isEmpty
                        ? 'ข้อความจากครู'
                        : 'ข้อความจากครู · ${a.classroomName}',
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: scheme.onPrimaryContainer,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              a.text,
              style: theme.textTheme.bodyLarge?.copyWith(
                color: scheme.onPrimaryContainer,
              ),
            ),
            if (a.sharedAt != null) ...[
              const SizedBox(height: 4),
              Text(
                formatThaiDate(a.sharedAt!),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onPrimaryContainer,
                ),
              ),
            ],
            if (a.nextSteps.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(
                'ลองฝึกต่อ',
                style: theme.textTheme.labelLarge?.copyWith(
                  color: scheme.onPrimaryContainer,
                ),
              ),
              const SizedBox(height: 4),
              for (final s in a.nextSteps)
                Card(
                  margin: const EdgeInsets.only(top: 4),
                  child: ListTile(
                    key: ValueKey('next_step_${a.classroomId}_${s.id}'),
                    leading: const Icon(Icons.fitness_center),
                    title: Text(s.code),
                    subtitle: s.name.isEmpty
                        ? null
                        : Text(
                            s.name,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push(
                      AppRoutes.studentSkillPractice(s.id),
                      extra: s,
                    ),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
