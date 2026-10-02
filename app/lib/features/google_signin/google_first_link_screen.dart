import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';
import '../auth/student_login_form.dart' show studentPinErrorMessage;
import '../auth/student_qr_scan_screen.dart' show cardErrorMessage;
import 'google_signin_config.dart';
import 'google_signin_errors.dart';
import 'google_signin_providers.dart';
import 'google_signin_repository.dart';

/// Thrown when the link ticket is gone (expired here or already spent).
class GoogleLinkTicketMissing implements Exception {
  const GoogleLinkTicketMissing();
}

/// Thai message for a failed first confirmation: a Google code (expired
/// ticket, school switch, domain, already linked) is about Google; anything
/// else is the PIN login's message, or with [qr] the card's.
String googleFirstLinkErrorMessage(Object error, {bool qr = false}) {
  if (error is GoogleLinkTicketMissing) {
    return googleReturnCodeMessage('link_ticket_invalid');
  }
  if (isGoogleSignInCode(apiErrorCode(error))) {
    return googleSignInErrorMessage(error);
  }
  return qr ? cardErrorMessage(error) : studentPinErrorMessage(error);
}

/// Spends the held link ticket on success; drops it when the server says
/// it is no longer valid, so the screen shows "start again".
Future<void> _confirm(
  WidgetRef ref,
  Future<String> Function(GoogleSignInRepository repo, String ticket) call,
) async {
  final tickets = ref.read(googleLinkTicketProvider.notifier);
  final ticket = ref.read(googleLinkTicketProvider);
  if (ticket == null) throw const GoogleLinkTicketMissing();
  final repo = ref.read(googleSignInRepositoryProvider);
  try {
    await ref
        .read(sessionProvider.notifier)
        .signInWithToken(call(repo, ticket));
  } catch (e) {
    if (apiErrorCode(e) == 'link_ticket_invalid') tickets.set(null);
    rethrow;
  }
  tickets.set(null);
}

/// The QR scanner's sign-in on "ยืนยันตัวตนครั้งแรก":
/// `POST /auth/google/link-with-qr`.
Future<void> linkGoogleWithQr(WidgetRef ref, String payload) => _confirm(
  ref,
  (repo, ticket) =>
      repo.linkWithQr(linkTicket: ticket, qrToken: studentCardToken(payload)),
);

/// "ยืนยันตัวตนครั้งแรก" (DESIGN §24.9.5, §24.13): the student's Google
/// account is not linked yet, so they prove who they are once with the
/// class code + number + PIN or the login card, after accepting the PDPA
/// notice (§24.14). Success links the account and signs the student in.
class GoogleFirstLinkScreen extends ConsumerStatefulWidget {
  const GoogleFirstLinkScreen({super.key, this.qrScanSupported = !kIsWeb});

  /// The card scanner needs the Android app's camera.
  final bool qrScanSupported;

  @override
  ConsumerState<GoogleFirstLinkScreen> createState() =>
      _GoogleFirstLinkScreenState();
}

class _GoogleFirstLinkScreenState extends ConsumerState<GoogleFirstLinkScreen> {
  final _formKey = GlobalKey<FormState>();
  final _classCode = TextEditingController();
  final _number = TextEditingController();
  final _pin = TextEditingController();
  bool _accepted = false;
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
      await _confirm(
        ref,
        (repo, ticket) => repo.linkWithPin(
          linkTicket: ticket,
          classCode: _classCode.text.trim().toUpperCase(),
          studentNumber: int.parse(_number.text.trim()),
          pin: _pin.text,
        ),
      );
      // The router takes the signed-in student to /student.
    } catch (e) {
      if (mounted) setState(() => _error = googleFirstLinkErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _scanCard() {
    if (widget.qrScanSupported) {
      context.push(AppRoutes.googleFirstLinkQr);
      return;
    }
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('สแกนบัตร QR ได้ในแอป Android'),
        content: const Text('บนเว็บให้กรอกรหัสห้อง เลขที่ และ PIN แทน'),
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
    final ticket = ref.watch(googleLinkTicketProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('ยืนยันตัวตนครั้งแรก')),
      body: ticket == null ? _expired(context) : _form(context),
    );
  }

  Widget _expired(BuildContext context) => FormColumn(
    children: [
      Text(
        googleReturnCodeMessage('link_ticket_invalid'),
        key: const ValueKey('google_link_expired'),
      ),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: () => context.go(AppRoutes.loginStudent),
        child: const Text('กลับไปหน้าเข้าสู่ระบบ'),
      ),
    ],
  );

  Widget _form(BuildContext context) {
    final theme = Theme.of(context);
    final ready = _accepted && !_busy;
    return FormColumn(
      children: [
        Text(
          'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชีนักเรียน '
          'ถ้าห้องของคุณใช้ Google Classroom และเพิ่งเข้าคอร์ส ไม่ต้องกรอกอะไร '
          'รอประมาณ 20 นาทีแล้วกด "เข้าสู่ระบบด้วย Google" อีกครั้ง '
          'หรือยืนยันว่าเป็นคุณครั้งเดียวด้วยบัตร QR หรือรหัสห้อง เลขที่ และ PIN '
          'ครั้งต่อไปกด "เข้าสู่ระบบด้วย Google" ได้เลย',
          style: theme.textTheme.bodyLarge,
        ),
        const SizedBox(height: 16),
        Card(
          margin: EdgeInsets.zero,
          color: theme.colorScheme.surfaceContainerHighest,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'ข้อความแจ้งเรื่องข้อมูลส่วนบุคคล',
                  style: theme.textTheme.titleSmall,
                ),
                const SizedBox(height: 8),
                const Text(
                  googleSignInNotice,
                  key: ValueKey('google_first_link_notice'),
                ),
                CheckboxListTile(
                  key: const ValueKey('google_notice_accept'),
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: _accepted,
                  onChanged: _busy
                      ? null
                      : (v) => setState(() => _accepted = v ?? false),
                  title: const Text('ฉันอ่านและยอมรับข้อความนี้'),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        OutlinedButton.icon(
          key: const ValueKey('google_first_link_qr'),
          onPressed: ready ? _scanCard : null,
          icon: const Icon(Icons.qr_code_scanner),
          label: const Text('สแกนบัตร QR'),
        ),
        const SizedBox(height: 16),
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
        const SizedBox(height: 8),
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                controller: _classCode,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(labelText: 'รหัสห้อง'),
                validator: (v) => (v == null || v.trim().length != 6)
                    ? 'รหัสห้องมี 6 ตัวอักษร'
                    : null,
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
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
              const SizedBox(height: 12),
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
                onFieldSubmitted: (_) {
                  if (ready) _submit();
                },
              ),
            ],
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 16),
          Text(
            _error!,
            key: const ValueKey('google_first_link_error'),
            style: TextStyle(color: theme.colorScheme.error),
          ),
        ],
        const SizedBox(height: 24),
        FilledButton(
          key: const ValueKey('google_first_link_submit'),
          onPressed: ready ? _submit : null,
          child: _busy
              ? const SizedBox(
                  height: 20,
                  width: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('ยืนยันและเชื่อมบัญชี Google'),
        ),
        if (!_accepted) ...[
          const SizedBox(height: 8),
          Text(
            'กดยอมรับข้อความแจ้งก่อน จึงจะยืนยันตัวตนได้',
            style: theme.textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ],
      ],
    );
  }
}
