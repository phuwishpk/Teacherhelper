import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import 'my_indicators.dart';

/// The teacher closed the home card on this device (per teacher, DESIGN §34).
final myIndicatorsPromptClosedProvider = FutureProvider.autoDispose<bool>((
  ref,
) async {
  // Read and written per teacher; a new sign-in recomputes it.
  final user = ref.watch(currentUserProvider);
  if (user == null) return false;
  try {
    return await ref
        .read(tokenStorageProvider)
        .readMyIndicatorsPromptClosed(user.id);
  } catch (_) {
    return false;
  }
});

/// Home card "เลือกตัวชี้วัดที่คุณสอน" (DESIGN §34): shown while the
/// teacher keeps no set and has not closed it here; never required.
class MyIndicatorsCard extends ConsumerWidget {
  const MyIndicatorsCard({super.key, required this.onOpen});

  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final set = ref.watch(myIndicatorsProvider).value;
    final closed = ref.watch(myIndicatorsPromptClosedProvider).value;
    if (set == null || set.isNotEmpty || closed != false) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        key: const ValueKey('my_indicators_card'),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 12, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(Icons.checklist, color: theme.colorScheme.primary),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      'เลือกตัวชี้วัดที่คุณสอน',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                'เลือกวิชาและชั้นที่สอนไว้ครั้งเดียว หน้าเลือกตัวชี้วัดจะแสดงเฉพาะตัวที่คุณใช้ หาง่ายขึ้น',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              Align(
                alignment: Alignment.centerRight,
                child: Wrap(
                  spacing: 8,
                  children: [
                    TextButton(
                      key: const ValueKey('my_indicators_card_later'),
                      onPressed: () async {
                        final user = ref.read(currentUserProvider);
                        if (user == null) return;
                        try {
                          await ref
                              .read(tokenStorageProvider)
                              .writeMyIndicatorsPromptClosed(user.id);
                        } catch (_) {
                          // Not remembered: the card comes back next time.
                        }
                        ref.invalidate(myIndicatorsPromptClosedProvider);
                      },
                      child: const Text('ไม่ใช่ตอนนี้'),
                    ),
                    FilledButton.tonal(
                      onPressed: onOpen,
                      child: const Text('เลือกตัวชี้วัด'),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
