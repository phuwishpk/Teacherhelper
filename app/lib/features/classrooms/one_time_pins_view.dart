import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/widgets/content_column.dart';
import 'classroom.dart';

/// Tab-separated "เลขที่ ชื่อ PIN" rows for pasting into a spreadsheet.
String pinsAsText(List<EnrolledStudent> enrolled) => [
  'เลขที่\tชื่อ\tชื่อผู้ใช้\tรหัสผ่านเริ่มต้น',
  for (final s in enrolled)
    '${s.studentNumber}\t${s.name}\t${s.username ?? ''}\t${s.pin}',
].join('\n');

/// Copies [enrolled]'s PINs and says so.
Future<void> copyPins(
  BuildContext context,
  List<EnrolledStudent> enrolled,
) async {
  await Clipboard.setData(ClipboardData(text: pinsAsText(enrolled)));
  if (context.mounted) {
    showMessage(context, 'คัดลอกรหัสผ่าน ${enrolled.length} คนแล้ว');
  }
}

/// Asks before leaving a screen that shows PINs once (DESIGN §9.2: the
/// server keeps only the hash).
Future<bool> confirmLeavePins(BuildContext context) => confirm(
  context,
  title: 'ออกจากหน้านี้?',
  message:
      'ชื่อผู้ใช้ของนักเรียนดูได้อีกในหน้ารายชื่อของห้อง รหัสผ่านเริ่มต้นคือค่าที่แสดงในหน้านี้',
  confirmLabel: 'ออก',
);

/// The one-time PIN list shown right after students were created (bulk
/// add, import from Google Classroom).
class OneTimePinsView extends StatelessWidget {
  const OneTimePinsView({
    super.key,
    required this.title,
    required this.enrolled,
    required this.onCopy,
    required this.onDone,
  });

  final String title;
  final List<EnrolledStudent> enrolled;
  final VoidCallback onCopy;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: FormColumn(
        maxWidth: 640,
        children: [
          Card(
            color: theme.colorScheme.tertiaryContainer,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Icon(
                    Icons.password_outlined,
                    color: theme.colorScheme.onTertiaryContainer,
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      'นักเรียนเข้าสู่ระบบด้วยชื่อผู้ใช้ด้านล่างและรหัสผ่านเริ่มต้น '
                      'แล้วระบบให้ตั้งรหัสผ่านของตัวเองทันที '
                      'ชื่อผู้ใช้ดูได้อีกในหน้ารายชื่อของห้อง',
                      style: TextStyle(
                        color: theme.colorScheme.onTertiaryContainer,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: Column(
              children: [
                for (final s in enrolled)
                  ListTile(
                    dense: true,
                    leading: Text('${s.studentNumber}'),
                    title: Text(s.name),
                    subtitle: s.username == null
                        ? null
                        : SelectableText('ชื่อผู้ใช้: ${s.username}'),
                    trailing: SelectableText(
                      s.pin,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontFamily: 'monospace',
                        letterSpacing: 2,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          OutlinedButton.icon(
            onPressed: onCopy,
            icon: const Icon(Icons.copy_all_outlined),
            label: const Text('คัดลอกรหัสผ่านทั้งหมด'),
          ),
          const SizedBox(height: 8),
          FilledButton.icon(
            onPressed: onDone,
            icon: const Icon(Icons.check),
            label: const Text('จดรหัสผ่านแล้ว เสร็จสิ้น'),
          ),
        ],
      ),
    );
  }
}
