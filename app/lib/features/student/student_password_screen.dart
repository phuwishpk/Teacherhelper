import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/content_column.dart';
import '../auth/sign_out_action.dart';

/// `/student/password` (DESIGN §29.10): the student sets their own
/// password. While the password is the initial one the router keeps the
/// student here ([forced]) and the current password is not asked.
class StudentPasswordScreen extends ConsumerStatefulWidget {
  const StudentPasswordScreen({super.key});

  @override
  ConsumerState<StudentPasswordScreen> createState() =>
      _StudentPasswordScreenState();
}

class _StudentPasswordScreenState extends ConsumerState<StudentPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _again = TextEditingController();
  bool _busy = false;
  String? _error;

  /// Read once: the screen must not change shape when the session updates.
  late final bool _forced =
      ref.read(currentUserProvider)?.mustChangePassword ?? false;

  @override
  void dispose() {
    _current.dispose();
    _password.dispose();
    _again.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(authRepositoryProvider)
          .changeStudentPassword(
            password: _password.text,
            currentPassword: _forced ? null : _current.text,
          );
      // The router lets the student in once must_change_password is false.
      await ref.read(sessionProvider.notifier).reloadUser();
      if (!mounted) return;
      showMessage(context, 'ตั้งรหัสผ่านใหม่แล้ว');
      if (!_forced && context.canPop()) context.pop();
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
      appBar: AppBar(
        automaticallyImplyLeading: !_forced,
        title: Text(_forced ? 'ตั้งรหัสผ่านของคุณ' : 'เปลี่ยนรหัสผ่าน'),
      ),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            if (_forced)
              Text(
                'คุณเข้าด้วยรหัสผ่านเริ่มต้น ตั้งรหัสผ่านของตัวเองก่อนใช้งาน '
                'จำไว้ให้ดี ครั้งต่อไปใช้รหัสผ่านนี้',
                key: const ValueKey('password_forced_note'),
              ),
            if (user?.username case final username?) ...[
              const SizedBox(height: 8),
              Text(
                'ชื่อผู้ใช้ของคุณ: $username',
                style: theme.textTheme.titleMedium,
              ),
            ],
            const SizedBox(height: 16),
            if (!_forced) ...[
              TextFormField(
                key: const ValueKey('password_current'),
                controller: _current,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'รหัสผ่านปัจจุบัน',
                ),
                validator: (v) =>
                    (v == null || v.isEmpty) ? 'กรอกรหัสผ่านปัจจุบัน' : null,
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 16),
            ],
            TextFormField(
              key: const ValueKey('password_new'),
              controller: _password,
              obscureText: true,
              decoration: const InputDecoration(
                labelText: 'รหัสผ่านใหม่',
                helperText: 'อย่างน้อย 6 ตัว และห้ามใช้ 123456',
              ),
              validator: (v) {
                if (v == null || v.length < 6) return 'อย่างน้อย 6 ตัว';
                if (v == '123456') return 'ห้ามใช้ 123456';
                return null;
              },
              textInputAction: TextInputAction.next,
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('password_again'),
              controller: _again,
              obscureText: true,
              decoration: const InputDecoration(
                labelText: 'พิมพ์รหัสผ่านใหม่อีกครั้ง',
              ),
              validator: (v) =>
                  v != _password.text ? 'รหัสผ่านสองช่องไม่ตรงกัน' : null,
              onFieldSubmitted: (_) => _submit(),
            ),
            if (_error != null) ...[
              const SizedBox(height: 16),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('password_save'),
              onPressed: _busy ? null : _submit,
              child: Text(_busy ? 'กำลังบันทึก…' : 'บันทึกรหัสผ่าน'),
            ),
            if (_forced) ...[
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => confirmSignOut(context, ref),
                child: const Text('ออกจากระบบ'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
