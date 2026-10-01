import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/content_column.dart';
import '../google_classroom/google_browser_connect.dart';
import '../google_signin/google_identity_card.dart';
import 'admin_repository.dart';

/// Where an admin lands after the unified login (DESIGN §7.4). Admin work
/// (approving teachers, schools, indicators, models) happens in the web
/// panel, so this screen only opens it with a one-time link and signs out.
class AdminHomeScreen extends ConsumerStatefulWidget {
  const AdminHomeScreen({super.key});

  @override
  ConsumerState<AdminHomeScreen> createState() => _AdminHomeScreenState();
}

class _AdminHomeScreenState extends ConsumerState<AdminHomeScreen> {
  bool _busy = false;
  String? _error;

  Future<void> _openPanel() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final handoff = await ref.read(adminRepositoryProvider).handoff();
      final opened = await ref.read(externalUrlOpenerProvider)(handoff.url);
      if (!opened && mounted) {
        setState(
          () => _error =
              'เปิดเบราว์เซอร์ไม่ได้ ลองอีกครั้ง หรือเข้า /admin ของเซิร์ฟเวอร์ด้วยเบราว์เซอร์',
        );
      }
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final user = ref.watch(currentUserProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('ผู้ดูแลระบบ')),
      body: FormColumn(
        children: [
          Text(
            'สวัสดี คุณ${user?.name ?? ''}',
            style: theme.textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          Text(
            'งานของผู้ดูแลระบบ เช่น อนุมัติบัญชีครู จัดการโรงเรียน ตัวชี้วัด '
            'และเวอร์ชันโมเดล ทำในหน้าเว็บผู้ดูแลระบบ',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: _busy ? null : _openPanel,
            icon: _busy
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.open_in_new),
            label: const Text('เปิดหน้าผู้ดูแลระบบ'),
          ),
          const SizedBox(height: 8),
          Text(
            'ระบบสร้างลิงก์เข้าสู่ระบบที่ใช้ได้ครั้งเดียวภายใน 1 นาที '
            'แล้วเปิดในเบราว์เซอร์',
            style: theme.textTheme.bodySmall,
          ),
          if (_error != null) ...[
            const SizedBox(height: 16),
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          ],
          const SizedBox(height: 24),
          // Linked here after a password login; an admin is never linked
          // from their e-mail (DESIGN §24.9.3, #61).
          const GoogleIdentityCard(),
          const SizedBox(height: 24),
          OutlinedButton.icon(
            onPressed: () => ref.read(sessionProvider.notifier).signOut(),
            icon: const Icon(Icons.logout),
            label: const Text('ออกจากระบบ'),
          ),
        ],
      ),
    );
  }
}
