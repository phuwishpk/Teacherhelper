import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'classrooms_repository.dart';

/// "แสดง QR เข้าห้อง" (DESIGN §29.13.3): the QR of the join link, the class
/// code and the link itself, for the owner to show the class.
Future<void> showJoinQrDialog(
  BuildContext context,
  WidgetRef ref,
  Classroom classroom,
) {
  final future = ref.read(classroomsRepositoryProvider).joinQr(classroom.id);
  return showDialog<void>(
    context: context,
    builder: (context) => AlertDialog(
      key: const ValueKey('join_qr_dialog'),
      title: Text('เข้าห้อง ${classroom.name}'),
      content: FutureBuilder<JoinQr>(
        future: future,
        builder: (context, snapshot) {
          final theme = Theme.of(context);
          if (snapshot.hasError) {
            return Text(
              apiErrorMessage(snapshot.error!),
              style: TextStyle(color: theme.colorScheme.error),
            );
          }
          final qr = snapshot.data;
          if (qr == null) {
            return const SizedBox(
              height: 120,
              child: Center(child: CircularProgressIndicator.adaptive()),
            );
          }
          return SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox.square(
                  dimension: 220,
                  child: CustomPaint(painter: QrMatrixPainter(qr.matrix)),
                ),
                const SizedBox(height: 12),
                Text(
                  qr.code,
                  key: const ValueKey('join_qr_code'),
                  style: theme.textTheme.headlineMedium?.copyWith(
                    fontFamily: 'monospace',
                    letterSpacing: 4,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'นักเรียนสแกน QR หรือกรอกรหัสนี้ในแอป (เข้าห้องด้วยรหัส)',
                  style: theme.textTheme.bodySmall,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                SelectableText(
                  qr.url,
                  key: const ValueKey('join_qr_url'),
                  style: theme.textTheme.bodySmall,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  key: const ValueKey('join_qr_copy'),
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: qr.url));
                    if (context.mounted) {
                      showMessage(context, 'คัดลอกลิงก์แล้ว');
                    }
                  },
                  icon: const Icon(Icons.copy_outlined),
                  label: const Text('คัดลอกลิงก์'),
                ),
              ],
            ),
          );
        },
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ปิด'),
        ),
      ],
    ),
  );
}

/// Paints the server's QR matrix (rows of '1' = dark, '0' = light) with a
/// one-module quiet zone. A QR must be dark on white to scan, whatever the
/// theme, so these two colours are the one place a screen fixes a colour.
class QrMatrixPainter extends CustomPainter {
  const QrMatrixPainter(this.rows);

  final List<String> rows;

  @override
  void paint(Canvas canvas, Size size) {
    const dark = Color(0xFF000000);
    const light = Color(0xFFFFFFFF);
    canvas.drawRect(Offset.zero & size, Paint()..color = light);
    final n = rows.length;
    if (n == 0) return;
    final module = size.shortestSide / (n + 2);
    final paint = Paint()..color = dark;
    for (var r = 0; r < n; r++) {
      final row = rows[r];
      for (var c = 0; c < row.length; c++) {
        if (row[c] != '1') continue;
        canvas.drawRect(
          Rect.fromLTWH((c + 1) * module, (r + 1) * module, module, module),
          paint,
        );
      }
    }
  }

  @override
  bool shouldRepaint(QrMatrixPainter oldDelegate) => oldDelegate.rows != rows;
}
