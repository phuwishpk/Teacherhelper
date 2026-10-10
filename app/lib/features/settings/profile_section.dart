import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/content_column.dart';

/// The teacher's own profile on the settings page (DESIGN §29.1): name and
/// school name ("แก้ไข"), and the password.
class ProfileSection extends ConsumerWidget {
  const ProfileSection({super.key});

  Future<void> _edit(BuildContext context, WidgetRef ref) async {
    final user = ref.read(currentUserProvider);
    if (user == null) return;
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) =>
          _ProfileDialog(name: user.name, schoolName: user.schoolName ?? ''),
    );
    if (saved == true && context.mounted) {
      showMessage(context, 'บันทึกข้อมูลแล้ว');
    }
  }

  Future<void> _password(BuildContext context) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) => const _PasswordDialog(),
    );
    if (saved == true && context.mounted) {
      showMessage(context, 'เปลี่ยนรหัสผ่านแล้ว เครื่องอื่นถูกออกจากระบบ');
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    if (user == null) return const SizedBox.shrink();
    return Card(
      child: Column(
        children: [
          ListTile(
            key: const ValueKey('profile_edit'),
            leading: const Icon(Icons.person_outline),
            title: Text(user.name),
            subtitle: Text(
              [
                ?user.email,
                user.schoolName ?? 'ยังไม่ระบุโรงเรียน',
              ].join(' · '),
            ),
            trailing: const Icon(Icons.edit_outlined),
            onTap: () => _edit(context, ref),
          ),
          const Divider(height: 1),
          ListTile(
            key: const ValueKey('profile_password'),
            leading: const Icon(Icons.lock_outline),
            title: const Text('เปลี่ยนรหัสผ่าน'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _password(context),
          ),
        ],
      ),
    );
  }
}

class _ProfileDialog extends ConsumerStatefulWidget {
  const _ProfileDialog({required this.name, required this.schoolName});

  final String name;
  final String schoolName;

  @override
  ConsumerState<_ProfileDialog> createState() => _ProfileDialogState();
}

class _ProfileDialogState extends ConsumerState<_ProfileDialog> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.name);
  late final _school = TextEditingController(text: widget.schoolName);
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _school.dispose();
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
          .updateProfile(
            name: _name.text.trim(),
            schoolName: _school.text.trim(),
          );
      await ref.read(sessionProvider.notifier).reloadUser();
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('แก้ไขข้อมูลของฉัน'),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TextFormField(
                key: const ValueKey('profile_name'),
                controller: _name,
                decoration: const InputDecoration(labelText: 'ชื่อ-นามสกุล'),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? 'กรอกชื่อ' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                key: const ValueKey('profile_school'),
                controller: _school,
                maxLength: 150,
                decoration: const InputDecoration(
                  labelText: 'ชื่อโรงเรียน',
                  helperText: 'แสดงในแอปและบนบัตร QR ของนักเรียน เว้นว่างได้',
                  helperMaxLines: 2,
                ),
              ),
              if (_error != null)
                Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('profile_save'),
          onPressed: _busy ? null : _submit,
          child: const Text('บันทึก'),
        ),
      ],
    );
  }
}

class _PasswordDialog extends ConsumerStatefulWidget {
  const _PasswordDialog();

  @override
  ConsumerState<_PasswordDialog> createState() => _PasswordDialogState();
}

class _PasswordDialogState extends ConsumerState<_PasswordDialog> {
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _again = TextEditingController();
  bool _busy = false;
  String? _error;

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
          .changePassword(
            password: _password.text,
            currentPassword: _current.text.isEmpty ? null : _current.text,
          );
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เปลี่ยนรหัสผ่าน'),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TextFormField(
                key: const ValueKey('teacher_password_current'),
                controller: _current,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'รหัสผ่านปัจจุบัน',
                  helperText:
                      'เว้นว่างได้ถ้าสมัครด้วย Google และยังไม่เคยตั้งรหัสผ่าน',
                  helperMaxLines: 2,
                ),
              ),
              const SizedBox(height: 12),
              TextFormField(
                key: const ValueKey('teacher_password_new'),
                controller: _password,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'รหัสผ่านใหม่',
                  helperText: 'อย่างน้อย 8 ตัว',
                ),
                validator: (v) =>
                    (v == null || v.length < 8) ? 'อย่างน้อย 8 ตัว' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                key: const ValueKey('teacher_password_again'),
                controller: _again,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'พิมพ์รหัสผ่านใหม่อีกครั้ง',
                ),
                validator: (v) =>
                    v != _password.text ? 'รหัสผ่านสองช่องไม่ตรงกัน' : null,
              ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('teacher_password_save'),
          onPressed: _busy ? null : _submit,
          child: const Text('บันทึก'),
        ),
      ],
    );
  }
}
