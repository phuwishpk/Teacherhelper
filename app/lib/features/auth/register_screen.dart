import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/router/app_router.dart';
import '../google_signin/google_signin_errors.dart';
import '../google_signin/google_signin_models.dart';

/// "สมัครใช้งาน (ครู)" (DESIGN §9.1). Opened from a Google sign-in that
/// found no account ([google], DESIGN §24.9.5), the name and e-mail start
/// from the Google account and the account is linked as it is created.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key, this.google});

  final GoogleRegistration? google;

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _schoolCode = TextEditingController();
  late final _name = TextEditingController(text: widget.google?.name);
  late final _email = TextEditingController(text: widget.google?.email);
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  /// Dropped when the server says the ticket expired, so the teacher can
  /// still register (and link Google later in the settings).
  late String? _linkTicket = widget.google?.linkTicket;

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
            googleLinkTicket: _linkTicket,
          );
      if (!mounted) return;
      // New accounts are `pending` until a school admin approves them
      // (DESIGN §7.4), so login only works after that.
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('สมัครสำเร็จ'),
          content: Text(
            'บัญชีของคุณอยู่ระหว่างรอผู้ดูแลโรงเรียนอนุมัติ '
            'เมื่ออนุมัติแล้วจึงจะเข้าสู่ระบบได้'
            '${_linkTicket == null ? '' : ' ทั้งด้วยรหัสผ่านและปุ่ม "เข้าสู่ระบบด้วย Google"'}',
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
      if (!mounted) return;
      final code = apiErrorCode(e);
      setState(() {
        if (code == 'link_ticket_invalid') {
          _linkTicket = null;
          _error =
              '${googleSignInErrorMessage(e)} '
              'หรือกดสมัครอีกครั้งเพื่อสมัครโดยไม่เชื่อม Google (เชื่อมภายหลังในหน้าตั้งค่าได้)';
        } else if (isGoogleSignInCode(code)) {
          _error = googleSignInErrorMessage(e);
        } else {
          _error = apiErrorMessage(e);
        }
      });
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
                  if (_linkTicket != null) ...[
                    Card(
                      key: const ValueKey('register_google_banner'),
                      margin: EdgeInsets.zero,
                      child: ListTile(
                        leading: const Icon(Icons.account_circle_outlined),
                        title: const Text('สมัครพร้อมเชื่อมบัญชี Google'),
                        subtitle: Text(
                          'บัญชี Google ${widget.google?.email ?? ''} จะเชื่อมกับบัญชีครูทันทีที่สมัคร '
                          'ยังต้องตั้งรหัสผ่านไว้เป็นทางสำรอง',
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],
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
