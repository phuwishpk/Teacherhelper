import 'package:flutter/material.dart';

import 'mastery_models.dart';

/// Fill of a mastery heatmap cell or bar: a tint of the level color (the
/// neutral surface when there is too little data).
Color masteryFill(BuildContext context, MasteryLevel level) =>
    level == MasteryLevel.tooLittle
    ? Theme.of(context).colorScheme.surfaceContainerHighest
    : level.color(context).withValues(alpha: 0.22);

/// Level label with its icon and color (never color alone).
class MasteryLevelChip extends StatelessWidget {
  const MasteryLevelChip({super.key, required this.level});

  final MasteryLevel level;

  @override
  Widget build(BuildContext context) {
    final c = level.color(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: c.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(level.icon, size: 14, color: c),
          const SizedBox(width: 4),
          Text(
            level.label,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
              color: c,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

/// A thin 0–100% bar with the 40% and 75% cut points marked.
class MasteryBar extends StatelessWidget {
  const MasteryBar({super.key, required this.mastery});

  final SkillMastery mastery;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final level = mastery.level;
    final color = level == MasteryLevel.tooLittle
        ? scheme.outline
        : level.color(context);
    return Semantics(
      label: 'ความเข้าใจ ${percent(mastery.value)}',
      child: SizedBox(
        height: 10,
        child: LayoutBuilder(
          builder: (context, box) {
            final w = box.maxWidth;
            return Stack(
              children: [
                Container(
                  decoration: BoxDecoration(
                    color: scheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
                Container(
                  width: (w * mastery.value).clamp(4.0, w),
                  decoration: BoxDecoration(
                    color: color,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
                for (final cut in const [
                  MasteryLevel.partialFrom,
                  MasteryLevel.goodFrom,
                ])
                  Positioned(
                    left: w * cut - 1,
                    top: 0,
                    bottom: 0,
                    child: Container(width: 2, color: scheme.surface),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// Legend of the four levels with their ranges.
class MasteryLegend extends StatelessWidget {
  const MasteryLegend({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    const ranges = {
      MasteryLevel.good: '≥ 75%',
      MasteryLevel.partial: '40–74%',
      MasteryLevel.notYet: '< 40%',
      MasteryLevel.tooLittle: 'ทำไม่ถึง 2 ครั้ง',
    };
    return Wrap(
      spacing: 12,
      runSpacing: 6,
      children: [
        for (final MapEntry(key: level, value: range) in ranges.entries)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 14,
                height: 14,
                decoration: BoxDecoration(
                  color: masteryFill(context, level),
                  border: Border.all(color: level.color(context)),
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
              const SizedBox(width: 4),
              Icon(level.icon, size: 14, color: level.color(context)),
              const SizedBox(width: 2),
              Flexible(
                child: Text(
                  '${level.label} ($range)',
                  style: theme.textTheme.labelSmall,
                ),
              ),
            ],
          ),
      ],
    );
  }
}
