import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../google_classroom/google_auth.dart' show GoogleAuthCanceled;
import 'google_signin_config.dart';
import 'google_signin_errors.dart';
import 'google_signin_gateway.dart';
import 'google_signin_models.dart';
import 'google_signin_providers.dart';
import 'google_signin_repository.dart';

/// "เข้าสู่ระบบด้วย Google" of the login page (DESIGN §24.9.3, §24.9.4).
///
/// On Android: the account picker, `POST /auth/google`, then the session
/// reads `/me` and the router takes the user home. On the web: Google's
/// account chooser in the same tab; the app comes back on
/// `/login/google?ticket=` ([GoogleLoginReturnScreen]).
///
/// Returns a Thai message to show under the button, or null (signed in,
/// left for Google's page, cancelled, or handed over to the registration
/// or the first confirmation).
Future<String?> runGoogleSignIn(
  BuildContext context,
  WidgetRef ref, {
  required GoogleIntent intent,
  required GoogleSignInMode mode,
}) async {
  final repo = ref.read(googleSignInRepositoryProvider);
  try {
    if (mode == GoogleSignInMode.web) {
      // A sign-up mark left by an abandoned "สมัครด้วย Google" must not turn
      // this login's return into a registration.
      await _markGoogleSignUp(ref, null);
      final url = await repo.webUrl(link: false, intent: intent);
      final opened = await ref.read(sameTabUrlOpenerProvider)(url);
      return opened ? null : _chooserNotOpened;
    }
    final idToken = await ref.read(googleSignInGatewayProvider).idToken();
    await ref
        .read(sessionProvider.notifier)
        .signInWithToken(repo.signIn(idToken: idToken, intent: intent));
    return null;
  } on GoogleAuthCanceled {
    return null;
  } catch (e) {
    if (!context.mounted) return null;
    return handleGoogleSignInError(context, ref, e);
  }
}

const _chooserNotOpened = 'เปิดหน้าเลือกบัญชี Google ไม่ได้ ลองอีกครั้ง';

/// How long the web preview's "สมัครด้วย Google" waits for Google's
/// return: the link ticket of a 404 `google_not_linked` lives 10 minutes.
const googleSignUpWindow = Duration(minutes: 10);

Future<void> _markGoogleSignUp(WidgetRef ref, DateTime? at) async {
  try {
    await ref.read(tokenStorageProvider).writeGoogleSignUpStartedAt(at);
  } catch (e) {
    debugPrint('Google sign-up mark not written: $e');
  }
}

/// Whether the web flow coming back now was started by "สมัครด้วย Google"
/// of the register page within [googleSignUpWindow]. Clears the mark, so
/// it is answered once.
Future<bool> takeGoogleSignUpMark(WidgetRef ref) async {
  try {
    final storage = ref.read(tokenStorageProvider);
    final at = await storage.readGoogleSignUpStartedAt();
    if (at == null) return false;
    await storage.writeGoogleSignUpStartedAt(null);
    final age = DateTime.now().difference(at);
    return !age.isNegative && age < googleSignUpWindow;
  } catch (e) {
    debugPrint('Google sign-up mark not read: $e');
    return false;
  }
}

/// What "สมัครด้วย Google" of the register page ended with: a
/// [registration] to fill the form with, a Thai [message], or neither
/// (signed in, left for Google's page, or cancelled).
typedef GoogleSignUpOutcome = ({
  GoogleRegistration? registration,
  String? message,
});

/// "สมัครด้วย Google" of the register page (DESIGN §24.9.5): the staff
/// flow of [runGoogleSignIn] without the "ยังไม่มีบัญชี" dialog.
///
/// On Android an already linked account signs in (the router takes the
/// teacher home) and an unknown one returns its [GoogleRegistration]. On
/// the web the mark of [takeGoogleSignUpMark] is written before leaving
/// for Google's page, so [GoogleLoginReturnScreen] comes back here.
Future<GoogleSignUpOutcome> runGoogleSignUp(
  WidgetRef ref, {
  required GoogleSignInMode mode,
}) async {
  const nothing = (registration: null, message: null);
  final repo = ref.read(googleSignInRepositoryProvider);
  final web = mode == GoogleSignInMode.web;
  try {
    if (web) {
      await _markGoogleSignUp(ref, DateTime.now());
      final url = await repo.webUrl(link: false, intent: GoogleIntent.staff);
      if (await ref.read(sameTabUrlOpenerProvider)(url)) return nothing;
      await _markGoogleSignUp(ref, null);
      return (registration: null, message: _chooserNotOpened);
    }
    final idToken = await ref.read(googleSignInGatewayProvider).idToken();
    await ref
        .read(sessionProvider.notifier)
        .signInWithToken(
          repo.signIn(idToken: idToken, intent: GoogleIntent.staff),
        );
    return nothing;
  } on GoogleAuthCanceled {
    return nothing;
  } catch (e) {
    if (web) await _markGoogleSignUp(ref, null);
    final notLinked = GoogleNotLinked.of(e);
    if (notLinked?.registration case final registration?) {
      return (registration: registration, message: null);
    }
    return (
      registration: null,
      message: notLinked?.message ?? googleSignInErrorMessage(e),
    );
  }
}

/// What to do after a failed Google sign-in (also of the web flow's
/// ticket). A 404 `google_not_linked`:
///
/// - with a registration (staff tab): asks "สมัครใช้งานครู" (the register
///   page prefilled with the Google name and e-mail) or the password login;
/// - with only a link ticket (student tab): opens "ยืนยันตัวตนครั้งแรก";
/// - otherwise the server's message.
///
/// Returns the Thai message to show, or null when the user was sent on.
Future<String?> handleGoogleSignInError(
  BuildContext context,
  WidgetRef ref,
  Object error,
) async {
  final notLinked = GoogleNotLinked.of(error);
  if (notLinked == null) return googleSignInErrorMessage(error);

  if (notLinked.registration case final registration?) {
    final register = await showDialog<bool>(
      context: context,
      builder: (context) => _NotLinkedStaffDialog(
        message: notLinked.message,
        email: registration.email,
      ),
    );
    if (!context.mounted) return null;
    if (register == true) {
      context.push(AppRoutes.register, extra: registration);
      return null;
    }
    return 'เข้าสู่ระบบด้วยอีเมลและรหัสผ่าน แล้วกด "เชื่อมบัญชี Google" '
        'ในหน้าตั้งค่า ครั้งต่อไปจึงใช้ปุ่ม Google ได้';
  }

  if (notLinked.canConfirmAsStudent) {
    ref.read(googleLinkTicketProvider.notifier).set(notLinked.linkTicket);
    context.push(AppRoutes.googleFirstLink);
    return null;
  }
  return notLinked.message;
}

class _NotLinkedStaffDialog extends StatelessWidget {
  const _NotLinkedStaffDialog({required this.message, required this.email});

  final String message;
  final String email;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      key: const ValueKey('google_not_linked_dialog'),
      title: const Text('ยังไม่มีบัญชีที่เชื่อมกับ Google นี้'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (email.isNotEmpty) ...[
            Text(email, style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
          ],
          Text(message),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('เข้าสู่ระบบด้วยรหัสผ่าน'),
        ),
        FilledButton(
          key: const ValueKey('google_register_teacher'),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('สมัครใช้งานครู'),
        ),
      ],
    );
  }
}

/// The Google part of a login tab: a divider, the button, the PDPA notice
/// (DESIGN §24.14 shows it under the button) and the last error. Nothing
/// when Google sign-in is off for this build or server.
class GoogleSignInSection extends ConsumerStatefulWidget {
  const GoogleSignInSection({super.key, required this.intent});

  final GoogleIntent intent;

  @override
  ConsumerState<GoogleSignInSection> createState() =>
      _GoogleSignInSectionState();
}

class _GoogleSignInSectionState extends ConsumerState<GoogleSignInSection> {
  bool _busy = false;
  String? _error;

  @override
  void didUpdateWidget(GoogleSignInSection oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.intent != widget.intent) _error = null;
  }

  Future<void> _signIn(GoogleSignInMode mode) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final message = await runGoogleSignIn(
      context,
      ref,
      intent: widget.intent,
      mode: mode,
    );
    if (mounted) {
      setState(() {
        _busy = false;
        _error = message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final mode =
        ref.watch(googleSignInAvailabilityProvider).value ??
        GoogleSignInMode.none;
    if (mode == GoogleSignInMode.none) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),
        const _OrDivider(),
        const SizedBox(height: 16),
        OutlinedButton.icon(
          key: const ValueKey('google_signin_button'),
          onPressed: _busy ? null : () => _signIn(mode),
          icon: _busy
              ? const SizedBox(
                  height: 18,
                  width: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.account_circle_outlined),
          label: const Text('เข้าสู่ระบบด้วย Google'),
        ),
        const SizedBox(height: 8),
        Text(
          widget.intent == GoogleIntent.student
              ? 'ใช้ได้เมื่อเชื่อมบัญชี Google แล้ว ครั้งแรกต้องยืนยันด้วย PIN หรือบัตร QR'
              : 'ใช้ได้เมื่ออีเมล Google ตรงกับบัญชีครู หรือเชื่อมไว้ในหน้าตั้งค่าแล้ว',
          style: muted,
        ),
        const SizedBox(height: 4),
        Text(
          googleSignInNotice,
          key: const ValueKey('google_signin_notice'),
          style: muted,
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(
            _error!,
            key: const ValueKey('google_signin_error'),
            style: TextStyle(color: theme.colorScheme.error),
          ),
        ],
      ],
    );
  }
}

class _OrDivider extends StatelessWidget {
  const _OrDivider();

  @override
  Widget build(BuildContext context) => Row(
    children: [
      const Expanded(child: Divider()),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Text('หรือ', style: Theme.of(context).textTheme.labelLarge),
      ),
      const Expanded(child: Divider()),
    ],
  );
}

/// "สมัครด้วย Google" above the register form (DESIGN §24.9.5): the
/// button, the PDPA notice, the last error and a divider to the form.
/// Nothing when Google sign-in is off for this build or server.
/// [onRegistration] gets the Google name, e-mail and link ticket of an
/// account that has no EduVision account yet.
class GoogleSignUpSection extends ConsumerStatefulWidget {
  const GoogleSignUpSection({super.key, required this.onRegistration});

  final ValueChanged<GoogleRegistration> onRegistration;

  @override
  ConsumerState<GoogleSignUpSection> createState() =>
      _GoogleSignUpSectionState();
}

class _GoogleSignUpSectionState extends ConsumerState<GoogleSignUpSection> {
  bool _busy = false;
  String? _error;

  Future<void> _signUp(GoogleSignInMode mode) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final outcome = await runGoogleSignUp(ref, mode: mode);
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = outcome.message;
    });
    if (outcome.registration case final registration?) {
      widget.onRegistration(registration);
    }
  }

  @override
  Widget build(BuildContext context) {
    final mode =
        ref.watch(googleSignInAvailabilityProvider).value ??
        GoogleSignInMode.none;
    if (mode == GoogleSignInMode.none) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OutlinedButton.icon(
          key: const ValueKey('google_signup_button'),
          onPressed: _busy ? null : () => _signUp(mode),
          icon: _busy
              ? const SizedBox(
                  height: 18,
                  width: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.account_circle_outlined),
          label: const Text('สมัครด้วย Google'),
        ),
        const SizedBox(height: 8),
        Text(
          'ใช้ชื่อและอีเมลจากบัญชี Google แล้วเชื่อมบัญชีให้ทันทีที่สมัคร',
          style: muted,
        ),
        const SizedBox(height: 4),
        Text(
          googleSignInNotice,
          key: const ValueKey('google_signup_notice'),
          style: muted,
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(
            _error!,
            key: const ValueKey('google_signup_error'),
            style: TextStyle(color: theme.colorScheme.error),
          ),
        ],
        const SizedBox(height: 16),
        const _OrDivider(),
        const SizedBox(height: 16),
      ],
    );
  }
}
