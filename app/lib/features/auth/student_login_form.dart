import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';

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

/// The "นักเรียน" tab of the login page: scan the login card, or sign in
/// with a username and password (DESIGN §9.1 /auth/student/qr, §29.10
/// /auth/student/login).
class StudentLoginForm extends ConsumerStatefulWidget {
  const StudentLoginForm({super.key, this.qrScanSupported = true});

  /// False on the web preview: the card scanner needs the Android app's
  /// camera, so the button explains that instead.
  final bool qrScanSupported;

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
            password: _password.text,
          );
      // The router redirects to /student once the session is SignedIn.
    } catch (e) {
      if (mounted) setState(() => _error = studentLoginErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _scanCard() {
    if (widget.qrScanSupported) {
      context.push(AppRoutes.studentQr);
      return;
    }
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('สแกนบัตร QR ได้ในแอป Android'),
        content: const Text(
          'การสแกนบัตรต้องใช้กล้องของแอป Krucheck บน Android '
          'บนเว็บให้กรอกชื่อผู้ใช้และรหัสผ่านด้านล่างแทน',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('ตกลง'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          clipBehavior: Clip.antiAlias,
          margin: EdgeInsets.zero,
          child: InkWell(
            onTap: _busy ? null : _scanCard,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Icon(
                    Icons.qr_code_scanner,
                    size: 40,
                    color: theme.colorScheme.primary,
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('สแกนบัตร QR', style: theme.textTheme.titleMedium),
                        Text(
                          widget.qrScanSupported
                              ? 'วิธีหลัก: ถือบัตรที่ครูแจกให้หน้ากล้อง'
                              : 'ใช้ได้ในแอป Android',
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(height: 24),
        Row(
          children: [
            const Expanded(child: Divider()),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Text('หรือใช้รหัสผ่าน', style: theme.textTheme.labelLarge),
            ),
            const Expanded(child: Divider()),
          ],
        ),
        const SizedBox(height: 16),
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
              TextFormField(
                key: const ValueKey('student_password'),
                controller: _password,
                obscureText: true,
                autofillHints: const [AutofillHints.password],
                decoration: const InputDecoration(
                  labelText: 'รหัสผ่าน',
                  helperText:
                      'เข้าครั้งแรกใช้ 123456 แล้วตั้งรหัสผ่านของตัวเอง',
                ),
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
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('เข้าสู่ระบบ'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
