import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/auth_layout.dart';
import '../../core/widgets/message_dialog.dart';
import '../../core/widgets/password_field.dart';
import '../google_signin/google_signin_errors.dart';
import '../google_signin/google_signin_flow.dart';
import '../google_signin/google_signin_models.dart';

/// "สมัครใช้งาน (ครู)" (DESIGN §9.1, §29.13.2). There is no school to pick:
/// every teacher gets a workspace of their own, types their school name
/// (optional, DESIGN §29.11) and waits for an admin to approve the account.
/// Opened from a Google sign-in whose e-mail another account holds
/// ([google], DESIGN §24.9.5), or after "สมัครด้วย Google" on this page, the
/// name and e-mail start from the Google account and the account is linked
/// as it is created; any other Google account signs the new teacher in at
/// once (§29.1).
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key, this.google});

  final GoogleRegistration? google;

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _schoolName = TextEditingController();
  late final _name = TextEditingController(text: widget.google?.name);
  late final _email = TextEditingController(text: widget.google?.email);
  final _password = TextEditingController();
  final _passwordConfirm = TextEditingController();
  final _passwordFocus = FocusNode();

  /// The Google account this registration links, from [RegisterScreen.google]
  /// or "สมัครด้วย Google".
  late GoogleRegistration? _google = widget.google;
  bool _busy = false;
  String? _error;

  /// Dropped when the server says the ticket expired, so the teacher can
  /// still register (and link Google later in the settings).
  late String? _linkTicket = widget.google?.linkTicket;

  @override
  void dispose() {
    _schoolName.dispose();
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _passwordConfirm.dispose();
    _passwordFocus.dispose();
    super.dispose();
  }

  /// "สมัครด้วย Google" found no account: fill the form like a prefill from
  /// the login page (a typed name is kept), then ask for the password.
  void _useGoogle(GoogleRegistration google) {
    setState(() {
      _google = google;
      _linkTicket = google.linkTicket;
      _error = null;
      if (_name.text.trim().isEmpty && google.name.isNotEmpty) {
        _name.text = google.name;
      }
      if (google.email.isNotEmpty) _email.text = google.email;
    });
    _passwordFocus.requestFocus();
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
            schoolName: _schoolName.text.trim().isEmpty
                ? null
                : _schoolName.text.trim(),
            name: _name.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
            googleLinkTicket: _linkTicket,
          );
      if (!mounted) return;
      // New accounts are `pending` until an admin approves them (DESIGN
      // §7.4), so login only works after that.
      await showMessageDialog<void>(
        context,
        title: 'สมัครสำเร็จ',
        message:
            'บัญชีของคุณอยู่ระหว่างรอผู้ดูแลระบบอนุมัติ '
            'เมื่ออนุมัติแล้วจึงจะเข้าสู่ระบบได้'
            '${_linkTicket == null ? '' : ' ทั้งด้วยรหัสผ่านและปุ่ม "เข้าสู่ระบบด้วย Google"'}',
        actions: const [DialogAction('รับทราบ', primary: true)],
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
      body: SafeArea(
        child: AuthLayout(
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'สมัครใช้งาน (ครู)',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 16),
                // Offered while no Google account is linked: before one
                // is chosen, and again after its ticket expired.
                if (_linkTicket == null)
                  GoogleSignUpSection(onRegistration: _useGoogle),
                if (_linkTicket != null) ...[
                  Card(
                    key: const ValueKey('register_google_banner'),
                    margin: EdgeInsets.zero,
                    child: ListTile(
                      leading: const Icon(Icons.account_circle_outlined),
                      title: const Text('สมัครพร้อมเชื่อมบัญชี Google'),
                      subtitle: Text(
                        'บัญชี Google ${_google?.email ?? ''} จะเชื่อมกับบัญชีครูทันทีที่สมัคร '
                        'ยังต้องตั้งรหัสผ่านไว้เป็นทางสำรอง',
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'เมื่อผู้ดูแลระบบอนุมัติแล้ว เข้าสู่ระบบได้ทั้งด้วยบัญชี Google นี้และรหัสผ่าน',
                    key: const ValueKey('register_google_helper'),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                TextFormField(
                  key: const ValueKey('register_school_name'),
                  controller: _schoolName,
                  maxLength: 150,
                  decoration: const InputDecoration(
                    labelText: 'ชื่อโรงเรียน',
                    helperText: 'เว้นว่างได้ แก้ภายหลังได้ในหน้าตั้งค่า',
                  ),
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _name,
                  decoration: const InputDecoration(labelText: 'ชื่อ-นามสกุล'),
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
                PasswordField(
                  controller: _password,
                  focusNode: _passwordFocus,
                  labelText: 'รหัสผ่าน (อย่างน้อย 8 ตัว)',
                  validator: (v) =>
                      noEdgeWhitespace(v) ??
                      ((v == null || v.length < 8)
                          ? 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัว'
                          : null),
                ),
                const SizedBox(height: 16),
                // Checked here only: the API takes one password (DESIGN §9.1).
                PasswordField(
                  fieldKey: const ValueKey('register_password_confirm'),
                  controller: _passwordConfirm,
                  labelText: 'ยืนยันรหัสผ่าน',
                  validator: (v) =>
                      v != _password.text ? 'รหัสผ่านไม่ตรงกัน' : null,
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
    );
  }
}
