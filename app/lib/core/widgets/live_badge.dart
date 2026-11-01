import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../live/live_scope.dart';

/// The "อัปเดตสด" badge shown when the live layer is active (DESIGN §38.11).
class LiveBadge extends ConsumerWidget {
  const LiveBadge({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(liveAvailableProvider)) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Row(
      key: const ValueKey('live_badge'),
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.bolt_outlined, size: 16, color: scheme.primary),
        const SizedBox(width: 4),
        Text(
          'อัปเดตสด',
          style: theme.textTheme.labelSmall?.copyWith(
            color: scheme.primary,
          ),
        ),
      ],
    );
  }
}
