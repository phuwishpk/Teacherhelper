import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/widgets/content_column.dart';
import 'classroom.dart';

/// Tab-separated "เลขที่ ชื่อ PIN" rows for pasting into a spreadsheet.
String pinsAsText(List<EnrolledStudent> enrolled) => [
  'เลขที่\tชื่อ\tPIN',
  for (final s in enrolled) '${s.studentNumber}\t${s.name}\t${s.pin}',
].join('\n');

/// Copies [enrolled]'s PINs and says so.
Future<void> copyPins(
  BuildContext context,
  List<EnrolledStudent> enrolled,
) async {
  await Clipboard.setData(ClipboardData(text: pinsAsText(enrolled)));
  if (context.mounted) {
    showMessage(context, 'คัดลอก PIN ${enrolled.length} คนแล้ว');
  }
}

/// Asks before leaving a screen that shows PINs once (DESIGN §9.2: the
/// server keeps only the hash).
Future<bool> confirmLeavePins(BuildContext context) => confirm(
  context,
  title: 'ออกจากหน้านี้?',
  message:
      'PIN จะไม่แสดงอีก ถ้ายังไม่ได้จด ต้องรีเซ็ต PIN ทีละคนจากหน้าห้องเรียน',
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
                      'PIN เริ่มต้นของนักเรียนแสดงครั้งเดียว จดหรือคัดลอกไว้ก่อนออกจากหน้านี้ '
                      '(นักเรียนใช้ PIN คู่กับรหัสห้องและเลขที่ เมื่อไม่มีบัตร QR)',
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
            label: const Text('คัดลอก PIN ทั้งหมด'),
          ),
          const SizedBox(height: 8),
          FilledButton.icon(
            onPressed: onDone,
            icon: const Icon(Icons.check),
            label: const Text('จด PIN แล้ว เสร็จสิ้น'),
          ),
        ],
      ),
    );
  }
}
