import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/session.dart';

/// The strip above every screen while an admin is signed in as somebody
/// else (DESIGN §29.14): who the session belongs to, who is behind it, and
/// the way out (logout revokes the session's token).
class ImpersonationBanner extends ConsumerWidget {
  const ImpersonationBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final admin = user?.impersonatedBy;
    if (user == null || admin == null) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Material(
      key: const ValueKey('impersonation_banner'),
      color: theme.colorScheme.tertiaryContainer,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
        child: Row(
          children: [
            Icon(
              Icons.visibility_outlined,
              size: 18,
              color: theme.colorScheme.onTertiaryContainer,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'กำลังเข้าใช้ในฐานะ ${user.name} โดยผู้ดูแลระบบ $admin',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onTertiaryContainer,
                ),
              ),
            ),
            TextButton(
              key: const ValueKey('impersonation_exit'),
              onPressed: () => ref.read(sessionProvider.notifier).signOut(),
              child: const Text('ออก'),
            ),
          ],
        ),
      ),
    );
  }
}

/// [body] under the banner when there is one.
Widget withImpersonationBanner(Widget body) => Column(
  children: [
    const ImpersonationBanner(),
    Expanded(child: body),
  ],
);
