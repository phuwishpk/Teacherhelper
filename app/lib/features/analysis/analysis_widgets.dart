import 'package:flutter/material.dart';

import '../../core/widgets/content_column.dart';
import '../mastery/mastery_models.dart';
import '../mastery/mastery_widgets.dart';
import 'analysis_models.dart';

/// The one status label of an analysis in the teacher's lists: what the
/// teacher should do next comes first ("รออนุมัติ"), then what the student
/// sees, then where the text is.
({String label, IconData icon, Color color}) analysisBadge(
  BuildContext context,
  AnalysisSummary? a,
) {
  final scheme = Theme.of(context).colorScheme;
  if (a == null) {
    return (
      label: 'ยังไม่มีคะแนน',
      icon: Icons.hourglass_empty,
      color: scheme.outline,
    );
  }
  if (a.awaitingApproval) {
    return (
      label: 'รออนุมัติ',
      icon: Icons.rate_review_outlined,
      color: scheme.tertiary,
    );
  }
  if (a.status == AnalysisStatus.failed) {
    return (
      label: a.status.label,
      icon: Icons.error_outline,
      color: scheme.error,
    );
  }
  if (a.status == AnalysisStatus.queued) {
    return (
      label: a.status.label,
      icon: Icons.nightlight_outlined,
      color: scheme.secondary,
    );
  }
  if (a.shared) {
    return (
      label: 'แชร์แล้ว',
      icon: Icons.check_circle_outline,
      color: scheme.primary,
    );
  }
  return (
    label: a.hasText ? a.status.label : 'ยังไม่มีข้อความ',
    icon: Icons.notes_outlined,
    color: scheme.outline,
  );
}

class AnalysisBadge extends StatelessWidget {
  const AnalysisBadge({super.key, required this.analysis});

  final AnalysisSummary? analysis;

  @override
  Widget build(BuildContext context) {
    final b = analysisBadge(context, analysis);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(b.icon, size: 16, color: b.color),
        const SizedBox(width: 4),
        StatusChip(label: b.label, color: b.color),
      ],
    );
  }
}

/// "จุดเด่น" or "จุดที่ควรพัฒนา": computed by code from mastery.
class AnalysisItemsCard extends StatelessWidget {
  const AnalysisItemsCard({
    super.key,
    required this.title,
    required this.icon,
    required this.items,
    required this.emptyText,
  });

  final String title;
  final IconData icon;
  final List<AnalysisItem> items;
  final String emptyText;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, size: 20, color: theme.colorScheme.primary),
                const SizedBox(width: 8),
                Text(title, style: theme.textTheme.titleMedium),
              ],
            ),
            const SizedBox(height: 4),
            if (items.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Text(
                  emptyText,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              )
            else
              for (final item in items)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  title: Text(item.skill.code),
                  subtitle: Text(
                    [
                      if (item.skill.name.isNotEmpty) item.skill.name,
                      '${percent(item.value)} จาก ${item.nObs} ครั้ง',
                    ].join('\n'),
                  ),
                  isThreeLine: item.skill.name.isNotEmpty,
                  trailing: MasteryLevelChip(
                    level: MasteryLevel.of(item.value, item.nObs),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}
