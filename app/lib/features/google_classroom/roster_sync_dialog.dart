import 'package:flutter/material.dart';

import '../../core/widgets/content_column.dart';
import '../classrooms/one_time_pins_view.dart';
import 'google_models.dart';

/// Label of a student whose Google account left the linked course
/// (DESIGN §19.2).
const leftCourseLabel = 'ไม่อยู่ใน Classroom แล้ว';

/// Tells the teacher what "ซิงก์รายชื่อ" changed. New students' PINs are
/// shown once, so the dialog then closes only through its buttons, and the
/// Android back button asks first (like the import screen).
Future<void> showRosterSyncResult(
  BuildContext context,
  RosterSyncResult result,
) async {
  if (result.unchanged) {
    showMessage(context, 'รายชื่อตรงกับ Google Classroom แล้ว');
    return;
  }
  await showDialog<void>(
    context: context,
    barrierDismissible: result.added.isEmpty,
    builder: (context) => RosterSyncResultDialog(result: result),
  );
}

class RosterSyncResultDialog extends StatelessWidget {
  const RosterSyncResultDialog({super.key, required this.result});

  final RosterSyncResult result;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final added = result.added;

    Widget heading(String text) => Padding(
      padding: const EdgeInsets.only(top: 12, bottom: 4),
      child: Text(text, style: theme.textTheme.titleSmall),
    );
    Widget row(int number, String name, {Widget? trailing}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          SizedBox(width: 36, child: Text('$number')),
          Expanded(child: Text(name)),
          ?trailing,
        ],
      ),
    );

    final dialog = AlertDialog(
      title: const Text('ซิงก์รายชื่อแล้ว'),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (added.isNotEmpty) ...[
                heading('เพิ่มนักเรียนใหม่ ${added.length} คน'),
                Text(
                  'PIN เริ่มต้นแสดงครั้งเดียว จดหรือคัดลอกไว้ก่อนปิด',
                  style: muted,
                ),
                for (final s in added)
                  row(
                    s.studentNumber,
                    s.name,
                    trailing: SelectableText(
                      s.pin,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontFamily: 'monospace',
                        letterSpacing: 2,
                      ),
                    ),
                  ),
              ],
              if (result.left.isNotEmpty) ...[
                heading('$leftCourseLabel ${result.left.length} คน'),
                Text(
                  'ยังอยู่ในห้องและคะแนนเดิมยังอยู่ ยกเลิกการจับคู่บัญชีแล้ว '
                  'ถ้ากลับเข้าคอร์สด้วยบัญชีเดิม ระบบจะจับคู่คืนให้',
                  style: muted,
                ),
                for (final s in result.left) row(s.studentNumber, s.name),
              ],
              if (result.rematched.isNotEmpty) ...[
                heading(
                  'จับคู่บัญชี Google แล้ว ${result.rematched.length} คน',
                ),
                for (final s in result.rematched) row(s.studentNumber, s.name),
              ],
            ],
          ),
        ),
      ),
      actions: [
        if (added.isNotEmpty) ...[
          TextButton.icon(
            onPressed: () => copyPins(context, added),
            icon: const Icon(Icons.copy_all_outlined),
            label: const Text('คัดลอก PIN'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('จด PIN แล้ว'),
          ),
        ] else
          FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('ปิด'),
          ),
      ],
    );
    if (added.isEmpty) return dialog;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await confirmLeavePins(context) && context.mounted) {
          Navigator.of(context).pop();
        }
      },
      child: dialog,
    );
  }
}
