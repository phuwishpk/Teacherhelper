import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';

/// `/impersonate?ticket=…` (DESIGN §29.14): where the panel's "เข้าใช้แทน"
/// sends the admin's browser. The one-time ticket is redeemed for the
/// user's token; the router then shows that user's home with the banner.
class ImpersonateScreen extends ConsumerStatefulWidget {
  const ImpersonateScreen({super.key, this.ticket});

  final String? ticket;

  @override
  ConsumerState<ImpersonateScreen> createState() => _ImpersonateScreenState();
}

class _ImpersonateScreenState extends ConsumerState<ImpersonateScreen> {
  late bool _busy = (widget.ticket ?? '').isNotEmpty;
  bool _done = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    final ticket = widget.ticket ?? '';
    if (ticket.isEmpty) {
      _message =
          'ลิงก์เข้าใช้แทนไม่ครบ กลับไปที่หน้าผู้ดูแลระบบแล้วกด "เข้าใช้แทน" อีกครั้ง';
      return;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => _redeem(ticket));
  }

  Future<void> _redeem(String ticket) async {
    try {
      await ref
          .read(sessionProvider.notifier)
          .signInWithToken(
            ref.read(authRepositoryProvider).redeemImpersonation(ticket),
          );
      // The router takes the admin to the user's home; no spinner meanwhile.
      if (mounted) {
        setState(() {
          _busy = false;
          _done = true;
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _message = apiErrorMessage(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เข้าใช้แทน')),
      body: FormColumn(
        children: [
          if (_busy) ...[
            const Center(child: CircularProgressIndicator.adaptive()),
            const SizedBox(height: 16),
            const Text(
              'กำลังเข้าใช้ในฐานะผู้ใช้…',
              textAlign: TextAlign.center,
            ),
          ] else if (_done) ...[
            const Text(
              'เข้าใช้ในฐานะผู้ใช้แล้ว กำลังเปิดหน้าของผู้ใช้…',
              key: ValueKey('impersonate_done'),
              textAlign: TextAlign.center,
            ),
          ] else ...[
            Text(
              _message ?? '',
              key: const ValueKey('impersonate_message'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => context.go(AppRoutes.login),
              child: const Text('กลับไปหน้าเข้าสู่ระบบ'),
            ),
          ],
        ],
      ),
    );
  }
}
