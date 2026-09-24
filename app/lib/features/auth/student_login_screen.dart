import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';

/// Thai message for a failed PIN login. The server locks the account for
/// 15 minutes after 5 wrong PINs (DESIGN §7.4); it signals that with
/// 423/429 or `code: pin_locked`.
String studentPinErrorMessage(Object error) {
  final status = apiStatusCode(error);
  final code = apiErrorCode(error);
  if (status == 423 ||
      status == 429 ||
      code == 'pin_locked' ||
      code == 'locked') {
    return 'ใส่ PIN ผิดหลายครั้ง ระบบล็อกชั่วคราว 15 นาที แล้วค่อยลองใหม่ '
        'หรือให้ครูรีเซ็ต PIN';
  }
  if (status == 401 || status == 422) {
    return 'รหัสห้อง เลขที่ หรือ PIN ไม่ถูกต้อง';
  }
  return apiErrorMessage(error);
}

/// Student entry: scan the login card, or fall back to class code + number
/// + PIN (DESIGN §9.1 /auth/student/qr and /auth/student/pin).
class StudentLoginScreen extends ConsumerStatefulWidget {
  const StudentLoginScreen({super.key});

  @override
  ConsumerState<StudentLoginScreen> createState() => _StudentLoginScreenState();
}

class _StudentLoginScreenState extends ConsumerState<StudentLoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _classCode = TextEditingController();
  final _number = TextEditingController();
  final _pin = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _classCode.dispose();
    _number.dispose();
    _pin.dispose();
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
          .signInStudentPin(
            classCode: _classCode.text.trim().toUpperCase(),
            studentNumber: int.parse(_number.text.trim()),
            pin: _pin.text,
          );
    } catch (e) {
      if (mounted) setState(() => _error = studentPinErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เข้าสู่ระบบสำหรับนักเรียน')),
      body: FormColumn(
        maxWidth: 480,
        children: [
          Card(
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: _busy ? null : () => context.push(AppRoutes.studentQr),
              child: Padding(
                padding: const EdgeInsets.all(20),
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
                          Text(
                            'สแกนบัตร QR',
                            style: theme.textTheme.titleMedium,
                          ),
                          const Text('วิธีหลัก: ถือบัตรที่ครูแจกให้หน้ากล้อง'),
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
                child: Text('หรือใช้ PIN', style: theme.textTheme.labelLarge),
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
                  controller: _classCode,
                  textCapitalization: TextCapitalization.characters,
                  decoration: const InputDecoration(
                    labelText: 'รหัสห้อง',
                    hintText: '6 ตัวอักษร ถามครูประจำวิชา',
                  ),
                  validator: (v) => (v == null || v.trim().length != 6)
                      ? 'รหัสห้องมี 6 ตัวอักษร'
                      : null,
                  textInputAction: TextInputAction.next,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _number,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(labelText: 'เลขที่'),
                  validator: (v) {
                    final n = int.tryParse(v?.trim() ?? '');
                    return (n == null || n < 1) ? 'กรอกเลขที่' : null;
                  },
                  textInputAction: TextInputAction.next,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _pin,
                  obscureText: true,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(
                    labelText: 'PIN 6 หลัก',
                    counterText: '',
                  ),
                  validator: (v) =>
                      (v == null || v.length != 6) ? 'PIN ต้องมี 6 หลัก' : null,
                  onFieldSubmitted: (_) => _submit(),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 16),
                  Text(
                    _error!,
                    style: TextStyle(color: theme.colorScheme.error),
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
                  onPressed: _busy ? null : () => context.go(AppRoutes.login),
                  child: const Text('เป็นครู? เข้าสู่ระบบสำหรับครู'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
