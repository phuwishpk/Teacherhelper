import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme/app_theme.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/small_spinner.dart';
import 'server_health.dart';

class ServerStatusCard extends ConsumerWidget {
  const ServerStatusCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final healthAsync = ref.watch(serverHealthProvider);
    final theme = Theme.of(context);
    final colorScheme = theme.colorScheme;
    final textTheme = theme.textTheme;

    final Widget icon;
    final String statusText;
    final Color? textColor;
    final DateTime? queueLastRunAt;

    if (healthAsync.isLoading) {
      icon = const SmallSpinner();
      statusText = 'กำลังตรวจสอบ…';
      textColor = null;
      queueLastRunAt = null;
    } else if (healthAsync.hasError) {
      icon = Icon(Icons.cloud_off_outlined, color: colorScheme.error);
      statusText = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้';
      textColor = colorScheme.error;
      queueLastRunAt = null;
    } else {
      final health = healthAsync.value!;
      queueLastRunAt = health.queueLastRunAt;
      switch (health.state) {
        case ServerState.ok:
          icon = Icon(Icons.check_circle_outline, color: colorScheme.success);
          statusText = 'เซิร์ฟเวอร์ทำงานปกติ';
          textColor = colorScheme.success;
        case ServerState.degraded:
          icon = Icon(Icons.warning_amber_outlined, color: colorScheme.warning);
          statusText = 'เซิร์ฟเวอร์มีปัญหาบางส่วน (คิวงานไม่ได้รันตามปกติ)';
          textColor = colorScheme.warning;
        case ServerState.unreachable:
          icon = Icon(Icons.cloud_off_outlined, color: colorScheme.error);
          statusText = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้';
          textColor = colorScheme.error;
      }
    }

    final queueText = queueLastRunAt != null
        ? 'คิวงานรันล่าสุด ${formatThaiDateTime(queueLastRunAt.toLocal())}'
        : 'คิวงานรันล่าสุด —';

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                icon,
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    statusText,
                    style: textTheme.titleMedium?.copyWith(
                      color: textColor,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              queueText,
              style: textTheme.bodyMedium?.copyWith(
                color: theme.textTheme.bodySmall?.color,
              ),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                key: const ValueKey('about_health_retry'),
                onPressed: () => ref.invalidate(serverHealthProvider),
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('ตรวจอีกครั้ง'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

