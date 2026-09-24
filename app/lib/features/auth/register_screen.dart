import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/router/app_router.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _schoolCode = TextEditingController();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _schoolCode.dispose();
    _name.dispose();
    _email.dispose();
    _password.dispose();
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
          .register(
            schoolCode: _schoolCode.text.trim(),
            name: _name.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
          );
      if (!mounted) return;
      // New accounts are `pending` until a school admin approves them
      // (DESIGN §7.4), so login only works after that.
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('สมัครสำเร็จ'),
          content: const Text(
            'บัญชีของคุณอยู่ระหว่างรอผู้ดูแลโรงเรียนอนุมัติ '
            'เมื่ออนุมัติแล้วจึงจะเข้าสู่ระบบได้',
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('รับทราบ'),
            ),
          ],
        ),
      );
      if (!mounted) return;
      context.go(AppRoutes.login);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('สมัครใช้งาน (ครู)')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextFormField(
                    controller: _schoolCode,
                    textCapitalization: TextCapitalization.characters,
                    decoration: const InputDecoration(
                      labelText: 'รหัสโรงเรียน (school_code)',
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'กรอกรหัสโรงเรียน'
                        : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _name,
                    decoration: const InputDecoration(
                      labelText: 'ชื่อ-นามสกุล',
                    ),
                    validator: (v) =>
                        (v == null || v.trim().isEmpty) ? 'กรอกชื่อ' : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'อีเมล'),
                    validator: (v) => (v == null || !v.contains('@'))
                        ? 'กรอกอีเมลให้ถูกต้อง'
                        : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _password,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'รหัสผ่าน (อย่างน้อย 8 ตัว)',
                    ),
                    validator: (v) => (v == null || v.length < 8)
                        ? 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัว'
                        : null,
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Text(
                      _error!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : _submit,
                    child: const Text('สมัครใช้งาน'),
                  ),
                  TextButton(
                    onPressed: _busy ? null : () => context.go(AppRoutes.login),
                    child: const Text('มีบัญชีแล้ว? เข้าสู่ระบบ'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
