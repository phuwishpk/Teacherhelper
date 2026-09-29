import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/content_column.dart';
import 'google_auth.dart';
import 'google_browser_connect.dart';
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// "Google Classroom" card of the teacher settings page, next to the Gemini
/// key (DESIGN §18.7): connected / not connected / needs reconnect, with
/// connect and disconnect. Renders nothing unless the server has Google
/// Classroom set up ([googleClassroomEnabledProvider]).
///
/// "เชื่อม" uses Google Sign-In on the phone when this build can
/// (Android with GOOGLE_SERVER_CLIENT_ID), else the browser flow of the
/// server ([connectGoogleInBrowser]).
class GoogleClassroomCard extends ConsumerStatefulWidget {
  const GoogleClassroomCard({super.key});

  @override
  ConsumerState<GoogleClassroomCard> createState() =>
      _GoogleClassroomCardState();
}

class _GoogleClassroomCardState extends ConsumerState<GoogleClassroomCard> {
  bool _busy = false;

  Future<void> _connect() async {
    setState(() => _busy = true);
    try {
      final native = ref.read(googleAuthProvider).supportsServerAuthCode;
      final status = native
          ? await ref.read(googleStatusProvider.notifier).connect()
          : await connectGoogleInBrowser(context, ref);
      if (status != null && mounted) {
        showMessage(
          context,
          'เชื่อม Google Classroom กับ ${status.email ?? 'บัญชีนี้'} แล้ว',
        );
      }
    } on GoogleAuthCanceled {
      // The teacher closed the picker: nothing to say.
    } on GoogleAuthException catch (e) {
      if (mounted) showMessage(context, e.message);
    } catch (e) {
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _disconnect(GoogleStatus status) async {
    final ok = await confirm(
      context,
      title: 'ยกเลิกการเชื่อม Google Classroom?',
      message:
          'แอปจะโพสต์งาน ดึงงานที่ส่ง และส่งคะแนนกลับไปที่ Classroom ไม่ได้ '
          'จนกว่าจะเชื่อมใหม่ ห้องเรียนที่ผูกไว้และการจับคู่นักเรียนยังอยู่',
      confirmLabel: 'ยกเลิกการเชื่อม',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(googleStatusProvider.notifier).disconnect();
      if (mounted) showMessage(context, 'ยกเลิกการเชื่อม Google แล้ว');
    } catch (e) {
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(googleClassroomEnabledProvider)) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    final status = ref.watch(googleStatusProvider);
    final s = status.value;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final (chip, chipColor) = switch (s) {
      null => (null, null),
      GoogleStatus(connected: false) => (
        'ยังไม่เชื่อม',
        theme.colorScheme.onSurfaceVariant,
      ),
      GoogleStatus(needsReconnect: true) => (
        'ต้องเชื่อมใหม่',
        theme.colorScheme.error,
      ),
      _ => ('เชื่อมแล้ว', Colors.green.shade700),
    };

    return Card(
      key: const ValueKey('google_classroom_card'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.school_outlined, color: theme.colorScheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Google Classroom',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (chip != null) StatusChip(label: chip, color: chipColor),
              ],
            ),
            const SizedBox(height: 12),
            if (status.isLoading && s == null) const LinearProgressIndicator(),
            if (status.hasError && s == null)
              Row(
                children: [
                  const Expanded(child: Text('ตรวจสอบสถานะการเชื่อมไม่ได้')),
                  TextButton(
                    onPressed: () => ref.invalidate(googleStatusProvider),
                    child: const Text('ลองใหม่'),
                  ),
                ],
              ),
            if (s != null && s.connected) ...[
              Text('บัญชี: ${s.email ?? '-'}'),
              if (s.needsReconnect) ...[
                const SizedBox(height: 4),
                Text(
                  'สิทธิ์ที่ให้ไว้หมดอายุหรือถูกยกเลิก กด "เชื่อมใหม่" '
                  '(ช่วงทดสอบ Google ให้สิทธิ์ได้ครั้งละ 7 วัน)',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ],
              const SizedBox(height: 8),
            ],
            const Text(
              'โพสต์การบ้านลง Classroom ตรวจรูปหรือ PDF ที่นักเรียนส่งจากทั้งหน้า '
              'และส่งคะแนนกลับเมื่อเผยแพร่ผล นักเรียนยังเข้าแอปด้วยบัตร QR/PIN เหมือนเดิม',
            ),
            const SizedBox(height: 4),
            Text(
              'เซิร์ฟเวอร์ดาวน์โหลดไฟล์ที่นักเรียนส่งจาก Google Drive ด้วยสิทธิ์ของบัญชีนี้ (เก็บสิทธิ์แบบเข้ารหัส) '
              'ไฟล์งานเก็บตามนโยบายเดียวกับภาพใบงาน และส่งให้ AI ตรวจโดยไม่ส่งชื่อนักเรียนไปด้วย',
              style: muted,
            ),
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                if (s?.connected == true)
                  OutlinedButton.icon(
                    onPressed: _busy ? null : () => _disconnect(s!),
                    icon: const Icon(Icons.link_off),
                    label: const Text('ยกเลิกการเชื่อม'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: theme.colorScheme.error,
                    ),
                  ),
                if (s != null && !s.ready)
                  FilledButton.icon(
                    key: const ValueKey('google_connect'),
                    onPressed: _busy ? null : _connect,
                    icon: _busy
                        ? const SizedBox.square(
                            dimension: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.link),
                    label: Text(
                      s.connected ? 'เชื่อมใหม่' : 'เชื่อม Google Classroom',
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
