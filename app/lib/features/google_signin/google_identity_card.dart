import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/content_column.dart';
import '../google_classroom/google_auth.dart' show GoogleAuthCanceled;
import 'google_signin_config.dart';
import 'google_signin_errors.dart';
import 'google_signin_models.dart';
import 'google_signin_providers.dart';
import 'google_signin_repository.dart';

/// "บัญชี Google สำหรับเข้าสู่ระบบ" (DESIGN §24.9.5, §24.13): link or
/// unlink the Google account the signed-in user signs in with. Shown in
/// the teacher settings (apart from the Google Classroom card), the
/// student's "บัญชีของฉัน" and the admin page; nothing when Google sign-in
/// is off for this build or server.
///
/// Before linking, a student must tick that they accept the PDPA notice
/// (§24.14); teachers and admins read the same notice on the card.
class GoogleIdentityCard extends ConsumerStatefulWidget {
  const GoogleIdentityCard({super.key});

  @override
  ConsumerState<GoogleIdentityCard> createState() => _GoogleIdentityCardState();
}

class _GoogleIdentityCardState extends ConsumerState<GoogleIdentityCard> {
  bool _busy = false;

  bool get _isStudent => ref.read(currentUserProvider)?.isStudent ?? false;

  Future<void> _link(GoogleSignInMode mode) async {
    if (_isStudent && !await _acceptNotice()) return;
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      if (mode == GoogleSignInMode.web) {
        final url = await ref
            .read(googleSignInRepositoryProvider)
            .webUrl(link: true, acceptNotice: true);
        final opened = await ref.read(sameTabUrlOpenerProvider)(url);
        if (!opened && mounted) {
          showMessage(context, 'เปิดหน้าเลือกบัญชี Google ไม่ได้ ลองอีกครั้ง');
        }
        return;
      }
      final identity = await ref
          .read(googleIdentityProvider.notifier)
          .linkOnDevice(acceptNotice: true);
      if (mounted) {
        showMessage(
          context,
          'เชื่อมบัญชี Google ${identity.email ?? ''} แล้ว'.trim(),
        );
      }
    } on GoogleAuthCanceled {
      // Closed the picker: nothing to say.
    } catch (e) {
      if (mounted) showMessage(context, googleSignInErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _acceptNotice() async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (_) => const _NoticeDialog(),
    );
    return accepted ?? false;
  }

  Future<void> _unlink() async {
    final ok = await confirm(
      context,
      title: 'ยกเลิกการเชื่อมบัญชี Google?',
      message:
          'จะเข้าสู่ระบบด้วยปุ่ม Google ไม่ได้จนกว่าจะเชื่อมใหม่ '
          '${_isStudent ? 'ยังเข้าสู่ระบบด้วยบัตร QR หรือรหัสผ่านได้ตามเดิม' : 'ยังเข้าสู่ระบบด้วยอีเมลและรหัสผ่านได้ตามเดิม'}',
      confirmLabel: 'ยกเลิกการเชื่อม',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(googleIdentityProvider.notifier).unlink();
      if (mounted) showMessage(context, 'ยกเลิกการเชื่อมบัญชี Google แล้ว');
    } catch (e) {
      if (mounted) showMessage(context, googleSignInErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final mode =
        ref.watch(googleSignInAvailabilityProvider).value ??
        GoogleSignInMode.none;
    if (mode == GoogleSignInMode.none) return const SizedBox.shrink();
    final identity = ref.watch(googleIdentityProvider);
    if (identity.hasError &&
        apiErrorCode(identity.error!) == 'google_signin_not_configured') {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final List<Widget> body = switch (identity) {
      AsyncData(value: final id) when id.linked => [
        Text(
          'เชื่อมกับ ${id.email ?? 'บัญชี Google'} แล้ว',
          key: const ValueKey('google_identity_linked'),
        ),
        if (id.name case final name? when name.isNotEmpty)
          Text(name, style: muted),
        const SizedBox(height: 4),
        Text('กด "เข้าสู่ระบบด้วย Google" ในหน้าเข้าสู่ระบบได้', style: muted),
        const SizedBox(height: 12),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: OutlinedButton.icon(
            key: const ValueKey('google_identity_unlink'),
            onPressed: _busy ? null : _unlink,
            icon: const Icon(Icons.link_off),
            label: const Text('ยกเลิกการเชื่อม'),
          ),
        ),
      ],
      AsyncData(value: final id) when !id.canLink => [
        Text(
          googleReturnCodeMessage('student_google_disabled'),
          key: const ValueKey('google_identity_disabled'),
        ),
      ],
      AsyncData() => [
        Text(
          _isStudent
              ? 'เชื่อมบัญชี Google แล้วครั้งต่อไปเข้าสู่ระบบด้วยปุ่ม Google ได้โดยไม่ต้องใช้บัตรหรือรหัสผ่าน'
              : 'เชื่อมบัญชี Google แล้วเข้าสู่ระบบด้วยปุ่ม Google ได้โดยไม่ต้องพิมพ์รหัสผ่าน',
        ),
        const SizedBox(height: 8),
        Text(googleSignInNotice, style: muted),
        const SizedBox(height: 12),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: FilledButton.icon(
            key: const ValueKey('google_identity_link'),
            onPressed: _busy ? null : () => _link(mode),
            icon: _busy
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.link),
            label: const Text('เชื่อมบัญชี Google'),
          ),
        ),
      ],
      AsyncError(:final error) => [
        Text(
          googleSignInErrorMessage(error),
          style: TextStyle(color: theme.colorScheme.error),
        ),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton(
            onPressed: () => ref.invalidate(googleIdentityProvider),
            child: const Text('ลองใหม่'),
          ),
        ),
      ],
      _ => const [LinearProgressIndicator()],
    };

    return Card(
      key: const ValueKey('google_identity_card'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  Icons.account_circle_outlined,
                  color: theme.colorScheme.primary,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'บัญชี Google สำหรับเข้าสู่ระบบ',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            ...body,
          ],
        ),
      ),
    );
  }
}

/// The PDPA notice a student must accept before linking (DESIGN §24.14).
class _NoticeDialog extends StatefulWidget {
  const _NoticeDialog();

  @override
  State<_NoticeDialog> createState() => _NoticeDialogState();
}

class _NoticeDialogState extends State<_NoticeDialog> {
  bool _accepted = false;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      key: const ValueKey('google_notice_dialog'),
      title: const Text('ก่อนเชื่อมบัญชี Google'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(googleSignInNotice),
            CheckboxListTile(
              key: const ValueKey('google_notice_accept'),
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _accepted,
              onChanged: (v) => setState(() => _accepted = v ?? false),
              title: const Text('ฉันอ่านและยอมรับข้อความนี้'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('google_notice_continue'),
          onPressed: _accepted ? () => Navigator.of(context).pop(true) : null,
          child: const Text('เชื่อมบัญชี Google'),
        ),
      ],
    );
  }
}
