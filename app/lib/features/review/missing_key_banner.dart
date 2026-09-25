import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../settings/ai_key.dart';

/// Shown on the review queue while responses wait as `manual` with reason
/// `ai_key_missing` (DESIGN §13, §10.1): no teacher key and no server key.
class MissingKeyBanner extends ConsumerWidget {
  const MissingKeyBanner({
    super.key,
    required this.count,
    required this.onOpenSettings,
    required this.onRequeue,
    this.busy = false,
  });

  final int count;
  final VoidCallback onOpenSettings;
  final VoidCallback onRequeue;
  final bool busy;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final keyReady = ref.watch(aiKeyProvider).value?.canGrade ?? false;
    final fg = keyReady ? scheme.onSecondaryContainer : scheme.onErrorContainer;
    return Card(
      key: const ValueKey('missing_key_banner'),
      margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      color: keyReady ? scheme.secondaryContainer : scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.key_off_outlined, color: fg),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    keyReady
                        ? 'มี $count ข้อค้างจากตอนที่ยังไม่มี Gemini API key'
                        : 'ยังไม่ได้ใส่ Gemini API key',
                    style: Theme.of(
                      context,
                    ).textTheme.titleSmall?.copyWith(color: fg),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              keyReady
                  ? 'ใส่ key แล้ว กด "ตรวจข้อที่ค้างใหม่" เพื่อให้ AI ตรวจข้อเหล่านี้'
                  : 'มี $count ข้อที่ AI ยังไม่ได้ตรวจเพราะไม่มี key '
                        'ใส่ key ที่หน้าตั้งค่าแล้วกด "ตรวจข้อที่ค้างใหม่" หรือให้คะแนนเองก็ได้',
              style: TextStyle(color: fg),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(
                spacing: 8,
                children: [
                  TextButton(
                    onPressed: onOpenSettings,
                    child: const Text('ไปใส่ key'),
                  ),
                  FilledButton.tonal(
                    onPressed: busy ? null : onRequeue,
                    child: const Text('ตรวจข้อที่ค้างใหม่'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
