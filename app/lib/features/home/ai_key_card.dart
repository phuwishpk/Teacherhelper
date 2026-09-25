import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/content_column.dart';
import '../settings/ai_key.dart';

/// "Gemini API key" card on the teacher home (DESIGN §10.1): whether the
/// teacher's own key is set, and a shortcut to the settings form.
class AiKeyCard extends ConsumerWidget {
  const AiKeyCard({super.key, required this.onOpenSettings});

  final VoidCallback onOpenSettings;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final status = ref.watch(aiKeyProvider);
    final s = status.value;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Card(
      key: const ValueKey('ai_key_card'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 12, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.key_outlined, color: theme.colorScheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Gemini API key',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (s != null)
                  StatusChip(
                    label: s.configured
                        ? 'ใส่แล้ว (${s.masked})'
                        : 'ยังไม่ได้ใส่',
                    color: s.configured
                        ? Colors.green.shade700
                        : theme.colorScheme.error,
                  ),
              ],
            ),
            const SizedBox(height: 8),
            if (s == null && status.isLoading)
              const LinearProgressIndicator()
            else if (s == null)
              Text('ตรวจสอบสถานะ key ไม่ได้', style: muted)
            else if (s.configured)
              Text(
                s.serverKeyAvailable
                    ? 'AI ใช้ key ของคุณตรวจการบ้าน (เซิร์ฟเวอร์มี key กลางสำรองไว้ด้วย)'
                    : 'AI ใช้ key ของคุณตรวจการบ้าน',
                style: muted,
              )
            else if (s.serverKeyAvailable)
              Text(
                'มี key กลางของเซิร์ฟเวอร์อยู่แล้ว ถ้ายังไม่ใส่ key ของคุณ '
                'ระบบจะใช้ key กลางตรวจแทน',
                style: muted,
              )
            else
              Text(
                'ใส่ key ก่อนสแกน ไม่อย่างนั้นข้อที่ต้องใช้ AI จะค้างให้ตรวจเอง',
                style: TextStyle(color: theme.colorScheme.error),
              ),
            Align(
              alignment: Alignment.centerRight,
              child: s == null && status.hasError
                  ? TextButton(
                      onPressed: () => ref.invalidate(aiKeyProvider),
                      child: const Text('ลองใหม่'),
                    )
                  : FilledButton.tonal(
                      onPressed: onOpenSettings,
                      child: Text(s?.configured == true ? 'แก้ไข' : 'ใส่ key'),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
