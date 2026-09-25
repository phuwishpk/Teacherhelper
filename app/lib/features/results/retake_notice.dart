import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';

/// "ครูขอให้ถ่ายรูปใหม่" with the teacher's reason (DESIGN §18.2): the
/// Classroom API has no private comments, so the student learns why here.
class RetakeNotice extends StatelessWidget {
  const RetakeNotice({
    super.key,
    required this.reason,
    this.title,
    this.requestedAt,
    this.alternateLink,
  });

  final String reason;

  /// The assignment, on the results list.
  final String? title;
  final DateTime? requestedAt;
  final String? alternateLink;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final onColor = theme.colorScheme.onErrorContainer;
    return Card(
      color: theme.colorScheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.add_a_photo_outlined, color: onColor),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title == null
                        ? 'ครูขอให้ถ่ายรูปใหม่'
                        : 'ครูขอให้ถ่ายรูปใหม่: $title',
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: onColor,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(reason, style: TextStyle(color: onColor)),
                  const SizedBox(height: 4),
                  Text(
                    'ถ่ายรูปใบงานใหม่ให้เห็นมุมทั้ง 4 ชัดเจน '
                    'แล้วส่งอีกครั้งในงานเดิมบน Google Classroom',
                    style: theme.textTheme.bodySmall?.copyWith(color: onColor),
                  ),
                  if (requestedAt != null)
                    Text(
                      'แจ้งเมื่อ ${formatThaiDateTime(requestedAt!)}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: onColor,
                      ),
                    ),
                  if (alternateLink case final link?)
                    Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton.icon(
                        onPressed: () async {
                          await Clipboard.setData(ClipboardData(text: link));
                          if (context.mounted) {
                            showMessage(
                              context,
                              'คัดลอกลิงก์ของงานแล้ว เปิดในเบราว์เซอร์หรือแอป Classroom',
                            );
                          }
                        },
                        icon: const Icon(Icons.link),
                        label: const Text('คัดลอกลิงก์งานใน Classroom'),
                      ),
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
