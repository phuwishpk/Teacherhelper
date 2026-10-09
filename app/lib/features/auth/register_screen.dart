import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/auth_layout.dart';
import '../google_signin/google_signin_errors.dart';
import '../google_signin/google_signin_flow.dart';
import '../google_signin/google_signin_models.dart';

/// The schools of the sign-up form (GET /auth/schools). Not retried by
/// Riverpod: the form shows its own "ลองอีกครั้ง".
final registrationSchoolsProvider =
    FutureProvider.autoDispose<List<SchoolOption>>(
      (ref) => ref.watch(authRepositoryProvider).schools(),
      retry: (_, _) => null,
    );

/// "สมัครใช้งาน (ครู)" (DESIGN §9.1). No school code since 2 Oct 2569: with
/// one school its name is shown, with several the teacher picks one, and an
/// admin approves the account before it can log in. Opened from a Google
/// sign-in that found no account ([google], DESIGN §24.9.5), or after
/// "สมัครด้วย Google" on this page, the name and e-mail start from the
/// Google account and the account is linked as it is created. That form
/// is only for a school that approves its teachers itself: elsewhere
/// "สมัครด้วย Google" signs the new teacher in at once (#71).
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key, this.google});

  final GoogleRegistration? google;

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  int? _schoolId;
  late final _name = TextEditingController(text: widget.google?.name);
  late final _email = TextEditingController(text: widget.google?.email);
  final _password = TextEditingController();
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
    _name.dispose();
    _email.dispose();
    _password.dispose();
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
    // One school: that one. Several: the dropdown (validated above). The list
    // failed to load: none, and the server uses its only school or answers
    // 422 school_required.
    final schools = ref.read(registrationSchoolsProvider).value;
    final schoolId = schools != null && schools.length == 1
        ? schools.single.id
        : _schoolId;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(authRepositoryProvider)
          .register(
            schoolId: schoolId,
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
        } else if (code == 'school_required') {
          // A school was added since the form opened: show the dropdown.
          ref.invalidate(registrationSchoolsProvider);
          _error = apiErrorMessage(e);
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

  Widget _school(AsyncValue<List<SchoolOption>> schools) {
    final errorColor = Theme.of(context).colorScheme.error;
    return switch (schools) {
      AsyncData(:final value) when value.length > 1 =>
        DropdownButtonFormField<int>(
          key: const ValueKey('register_school'),
          initialValue: _schoolId,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'โรงเรียน'),
          items: [
            for (final school in value)
              DropdownMenuItem(
                value: school.id,
                child: Text(school.name, overflow: TextOverflow.ellipsis),
              ),
          ],
          onChanged: (v) => setState(() => _schoolId = v),
          validator: (v) => v == null ? 'กรุณาเลือกโรงเรียน' : null,
        ),
      AsyncData(:final value) when value.length == 1 => InputDecorator(
        key: const ValueKey('register_school_single'),
        decoration: const InputDecoration(
          labelText: 'โรงเรียน',
          border: InputBorder.none,
        ),
        child: Text(value.single.name),
      ),
      AsyncData() => Text(
        'ยังไม่มีโรงเรียนในระบบ กรุณาติดต่อผู้ดูแลระบบ',
        style: TextStyle(color: errorColor),
      ),
      AsyncError(:final error) => Row(
        children: [
          Expanded(
            child: Text(
              'โหลดรายชื่อโรงเรียนไม่สำเร็จ: ${apiErrorMessage(error)}',
              style: TextStyle(color: errorColor),
            ),
          ),
          TextButton(
            onPressed: () => ref.invalidate(registrationSchoolsProvider),
            child: const Text('ลองอีกครั้ง'),
          ),
        ],
      ),
      _ => const Row(
        children: [
          SizedBox.square(
            dimension: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          SizedBox(width: 12),
          Flexible(child: Text('กำลังโหลดรายชื่อโรงเรียน…')),
        ],
      ),
    };
  }

  @override
  Widget build(BuildContext context) {
    final schools = ref.watch(registrationSchoolsProvider);
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
                    'เมื่อผู้ดูแลโรงเรียนอนุมัติแล้ว เข้าสู่ระบบได้ทั้งด้วยบัญชี Google นี้และรหัสผ่าน',
                    key: const ValueKey('register_google_helper'),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                _school(schools),
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
                TextFormField(
                  controller: _password,
                  focusNode: _passwordFocus,
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
                  onPressed: _busy || schools.isLoading ? null : _submit,
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
