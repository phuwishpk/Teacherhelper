import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/widgets/auth_layout.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../google_signin/google_signin_flow.dart';
import '../google_signin/google_signin_models.dart';
import 'student_login_form.dart';

/// The two tabs of the login page (DESIGN §7.4). The name is what the
/// `?tab=` query and the remembered tab store.
enum LoginTab {
  /// Email + password: teachers and admins share the form; the router
  /// sends each to their own home by `user.role`.
  teacher,

  /// Login card QR or class code + number + PIN.
  student;

  static LoginTab? parse(String? name) => switch (name) {
    'teacher' => teacher,
    'student' => student,
    _ => null,
  };
}

/// One login page for every role: "ครู / ผู้ดูแลระบบ" and "นักเรียน".
/// Opens on [initialTab] (from `/login?tab=student`), else on the tab used
/// last on this device, else on the teacher tab.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({
    super.key,
    this.initialTab,
    this.qrScanSupported = !kIsWeb,
  });

  final LoginTab? initialTab;

  /// The card scanner needs the Android app's camera (not the web preview).
  final bool qrScanSupported;

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  late LoginTab _tab = widget.initialTab ?? LoginTab.teacher;

  /// Set once the user picks a tab, so a late read of the remembered tab
  /// never switches it back.
  bool _picked = false;

  @override
  void initState() {
    super.initState();
    if (widget.initialTab == null) _restoreTab();
  }

  @override
  void didUpdateWidget(LoginScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    final tab = widget.initialTab;
    if (tab != null && tab != oldWidget.initialTab) setState(() => _tab = tab);
  }

  Future<void> _restoreTab() async {
    String? saved;
    try {
      saved = await ref.read(tokenStorageProvider).readLoginTab();
    } catch (_) {
      return; // Storage unavailable: stay on the default tab.
    }
    final tab = LoginTab.parse(saved);
    if (mounted && !_picked && tab != null) setState(() => _tab = tab);
  }

  Future<void> _select(LoginTab tab) async {
    setState(() {
      _picked = true;
      _tab = tab;
    });
    try {
      await ref.read(tokenStorageProvider).writeLoginTab(tab.name);
    } catch (_) {
      // Remembering the tab is a convenience; never fail the page over it.
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: AuthLayout(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'เข้าสู่ระบบ',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 16),
              SegmentedButton<LoginTab>(
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(
                    value: LoginTab.teacher,
                    label: Text('ครู / ผู้ดูแลระบบ'),
                  ),
                  ButtonSegment(
                    value: LoginTab.student,
                    label: Text('นักเรียน'),
                  ),
                ],
                selected: {_tab},
                onSelectionChanged: (s) => _select(s.single),
              ),
              const SizedBox(height: 24),
              switch (_tab) {
                LoginTab.teacher => const _TeacherLoginForm(),
                LoginTab.student => StudentLoginForm(
                  qrScanSupported: widget.qrScanSupported,
                ),
              },
              // "เข้าสู่ระบบด้วย Google" on both tabs (DESIGN §24.13).
              GoogleSignInSection(
                intent: _tab == LoginTab.student
                    ? GoogleIntent.student
                    : GoogleIntent.staff,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Email + password for teachers and admins (`POST /auth/teacher/login`).
class _TeacherLoginForm extends ConsumerStatefulWidget {
  const _TeacherLoginForm();

  @override
  ConsumerState<_TeacherLoginForm> createState() => _TeacherLoginFormState();
}

class _TeacherLoginFormState extends ConsumerState<_TeacherLoginForm> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
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
          .read(sessionProvider.notifier)
          .signIn(email: _email.text.trim(), password: _password.text);
      // The router sends a teacher home and an admin to /admin-home once
      // the session becomes SignedIn.
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'ใช้อีเมลและรหัสผ่านของบัญชีครูหรือผู้ดูแลระบบ',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.email],
            decoration: const InputDecoration(labelText: 'อีเมล'),
            validator: (v) =>
                (v == null || !v.contains('@')) ? 'กรอกอีเมลให้ถูกต้อง' : null,
            textInputAction: TextInputAction.next,
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _password,
            obscureText: true,
            autofillHints: const [AutofillHints.password],
            decoration: const InputDecoration(labelText: 'รหัสผ่าน'),
            validator: (v) => (v == null || v.isEmpty) ? 'กรอกรหัสผ่าน' : null,
            onFieldSubmitted: (_) => _submit(),
          ),
          if (_error != null) ...[
            const SizedBox(height: 16),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          const SizedBox(height: 24),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('เข้าสู่ระบบ'),
          ),
          TextButton(
            onPressed: _busy ? null : () => context.go(AppRoutes.register),
            child: const Text('ยังไม่มีบัญชี? สมัครใช้งาน'),
          ),
        ],
      ),
    );
  }
}
