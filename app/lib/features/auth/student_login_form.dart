import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/password_field.dart';
import '../../core/widgets/small_spinner.dart';

/// Thai message for a failed username + password login.
///
/// - 423 `pin_locked`: this student is locked after 5 wrong tries
///   (DESIGN §7.4). The server says how long in `errors.password[0]`.
/// - 429: the per-IP `throttle:student-auth` limiter. A whole class shares
///   one school NAT address, so this is NOT about this student's password.
String studentLoginErrorMessage(Object error) {
  final status = apiStatusCode(error);
  final code = apiErrorCode(error);
  if (status == 423 || code == 'pin_locked') {
    // e.g. "ล็อกชั่วคราว ลองใหม่ในอีก 12 นาที" (StudentAuthenticator).
    final remaining = _firstFieldError(error, 'password');
    if (remaining != null) {
      return 'ใส่รหัสผ่านผิดหลายครั้ง $remaining หรือให้ครูรีเซ็ตรหัสผ่าน';
    }
    return 'ใส่รหัสผ่านผิดหลายครั้ง ระบบล็อกชั่วคราว 15 นาที แล้วค่อยลองใหม่ '
        'หรือให้ครูรีเซ็ตรหัสผ่าน';
  }
  if (status == 429) {
    return 'มีการเข้าสู่ระบบถี่เกินไป รอสักครู่แล้วลองใหม่';
  }
  if (status == 401 || status == 422) {
    return 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
  }
  return apiErrorMessage(error);
}

String? _firstFieldError(Object error, String field) {
  if (error is! DioException) return null;
  final data = error.response?.data;
  if (data is! Map) return null;
  final errors = data['errors'];
  if (errors is! Map) return null;
  final list = errors[field];
  if (list is List && list.isNotEmpty && list.first is String) {
    final text = (list.first as String).trim();
    return text.isEmpty ? null : text;
  }
  return null;
}

/// The "นักเรียน" tab of the login page: username and password (DESIGN
/// §29.10 /auth/student/login). The login-card scanner is gone (§29.2):
/// pupils join with a class code instead.
class StudentLoginForm extends ConsumerStatefulWidget {
  const StudentLoginForm({super.key});

  @override
  ConsumerState<StudentLoginForm> createState() => _StudentLoginFormState();
}

class _StudentLoginFormState extends ConsumerState<StudentLoginForm> {
  final _formKey = GlobalKey<FormState>();
  final _username = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _username.dispose();
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
          .signInStudent(
            username: _username.text.trim(),
            password: _password.text.trim(),
          );
      // The router redirects to /student once the session is SignedIn.
    } catch (e) {
      if (mounted) setState(() => _error = studentLoginErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                key: const ValueKey('student_username'),
                controller: _username,
                autocorrect: false,
                enableSuggestions: false,
                autofillHints: const [AutofillHints.username],
                decoration: const InputDecoration(
                  labelText: 'ชื่อผู้ใช้',
                  hintText: 'ถามครูประจำวิชา',
                ),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? 'กรอกชื่อผู้ใช้' : null,
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 16),
              PasswordField(
                fieldKey: const ValueKey('student_password'),
                controller: _password,
                labelText: 'รหัสผ่าน',
                helperText: 'เข้าครั้งแรกใช้ 123456 แล้วตั้งรหัสผ่านของตัวเอง',
                autofillHints: const [AutofillHints.password],
                validator: (v) =>
                    (v == null || v.isEmpty) ? 'กรอกรหัสผ่าน' : null,
                onFieldSubmitted: (_) => _submit(),
              ),
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
              ],
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SmallSpinner(size: 20)
                    : const Text('เข้าสู่ระบบ'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
