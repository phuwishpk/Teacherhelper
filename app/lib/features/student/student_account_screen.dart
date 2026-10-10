import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';
import '../auth/sign_out_action.dart';
import '../google_signin/google_identity_card.dart';

/// "บัญชีของฉัน" of a student (DESIGN §24.9.5, §24.13): who is signed in
/// and the Google account they may sign in with.
class StudentAccountScreen extends ConsumerWidget {
  const StudentAccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('บัญชีของฉัน')),
      body: FormColumn(
        maxWidth: 640,
        children: [
          if (user != null)
            Card(
              child: ListTile(
                leading: const Icon(Icons.person_outline),
                title: Text(user.name),
                subtitle: Text(
                  [
                    if (user.username case final u?) 'ชื่อผู้ใช้: $u',
                    ?user.schoolName,
                  ].join('\n'),
                ),
              ),
            ),
          if (user?.username != null)
            Card(
              child: ListTile(
                key: const ValueKey('account_change_password'),
                leading: const Icon(Icons.lock_outline),
                title: const Text('เปลี่ยนรหัสผ่าน'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push(AppRoutes.studentPassword),
              ),
            ),
          const SizedBox(height: 8),
          const GoogleIdentityCard(),
          const SizedBox(height: 24),
          OutlinedButton.icon(
            onPressed: () => confirmSignOut(context, ref),
            icon: const Icon(Icons.logout),
            label: const Text('ออกจากระบบ'),
          ),
        ],
      ),
    );
  }
}
