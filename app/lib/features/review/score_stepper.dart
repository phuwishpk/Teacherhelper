import 'package:flutter/material.dart';

import 'review_labels.dart';

/// Score input in steps of 0.5 between 0 and [max] (DESIGN §11.7 rounding).
class ScoreStepper extends StatelessWidget {
  const ScoreStepper({
    super.key,
    required this.value,
    required this.max,
    required this.onChanged,
    this.step = 0.5,
    this.enabled = true,
  });

  final double? value;
  final double max;
  final double step;
  final bool enabled;
  final ValueChanged<double> onChanged;

  double _clamp(double v) => v.clamp(0, max).toDouble();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final v = value;
    return Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: 4,
      runSpacing: 4,
      children: [
        IconButton.filledTonal(
          tooltip: 'ลดคะแนน',
          onPressed: !enabled || v == null || v <= 0
              ? null
              : () => onChanged(_clamp(v - step)),
          icon: const Icon(Icons.remove),
        ),
        ConstrainedBox(
          constraints: const BoxConstraints(minWidth: 96),
          child: Text(
            '${v == null ? '-' : formatScore(v)} / ${formatScore(max)}',
            key: const ValueKey('score_value'),
            textAlign: TextAlign.center,
            style: theme.textTheme.headlineSmall,
          ),
        ),
        IconButton.filledTonal(
          tooltip: 'เพิ่มคะแนน',
          onPressed: !enabled || (v != null && v >= max)
              ? null
              : () => onChanged(_clamp((v ?? -step) + step)),
          icon: const Icon(Icons.add),
        ),
        const SizedBox(width: 8),
        TextButton(
          onPressed: enabled ? () => onChanged(0) : null,
          child: const Text('0'),
        ),
        TextButton(
          onPressed: enabled ? () => onChanged(max) : null,
          child: const Text('เต็ม'),
        ),
      ],
    );
  }
}
