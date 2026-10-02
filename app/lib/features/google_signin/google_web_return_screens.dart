import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/content_column.dart';
import 'google_signin_errors.dart';
import 'google_signin_flow.dart';
import 'google_signin_models.dart';
import 'google_signin_providers.dart';
import 'google_signin_repository.dart';

/// `/login/google?ticket=…` or `?error=…` (DESIGN §24.9.4, §24.22): where
/// the server's callback sends the web preview back after Google's account
/// chooser. The one-time ticket (60 seconds) is redeemed with
/// `POST /auth/google/ticket`; the answer is that of `POST /auth/google`,
/// so a new teacher may be signed in at once (#71), a 404 with
/// `needs_school` asks for the school and goes back to Google with it, and
/// another 404 `google_not_linked` leads to the registration or the first
/// confirmation like on Android. A flow started by "สมัครด้วย Google" of
/// the register page ([takeGoogleSignUpMark]) goes straight back to that
/// page, filled in, instead of asking.
class GoogleLoginReturnScreen extends ConsumerStatefulWidget {
  const GoogleLoginReturnScreen({super.key, this.ticket, this.error});

  final String? ticket;
  final String? error;

  @override
  ConsumerState<GoogleLoginReturnScreen> createState() =>
      _GoogleLoginReturnScreenState();
}

class _GoogleLoginReturnScreenState
    extends ConsumerState<GoogleLoginReturnScreen> {
  late bool _busy = _ticket != null;
  late String? _message = switch ((_ticket, widget.error)) {
    (_, final String error) when error.isNotEmpty => googleReturnCodeMessage(
      error,
    ),
    (null, _) => googleReturnCodeMessage('google_ticket_invalid'),
    _ => null,
  };

  String? get _ticket => switch (widget.ticket) {
    final String t when t.isNotEmpty && (widget.error ?? '').isEmpty => t,
    _ => null,
  };

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      // Read (and cleared) on every return, so a mark never outlives it.
      final signUp = await takeGoogleSignUpMark(ref);
      if (!mounted) return;
      if (_ticket case final ticket?) await _redeem(ticket, signUp: signUp);
    });
  }

  Future<void> _redeem(String ticket, {required bool signUp}) async {
    String? message;
    try {
      await ref
          .read(sessionProvider.notifier)
          .signInWithToken(
            ref.read(googleSignInRepositoryProvider).redeemTicket(ticket),
          );
      return; // The router takes the user home.
    } catch (e) {
      if (!mounted) return;
      final notLinked = GoogleNotLinked.of(e);
      if (notLinked != null && notLinked.needsSchool) {
        // A new teacher and several schools (#71): pick one, then Google's
        // page again with the school carried to the next ticket.
        message = await _restartWithSchool(notLinked, signUp: signUp);
        if (message == null) return;
        if (mounted) {
          setState(() {
            _busy = false;
            _message = message;
          });
        }
        return;
      }
      final registration = signUp ? notLinked?.registration : null;
      if (registration != null) {
        context.go(AppRoutes.register, extra: registration);
        return;
      }
      message =
          await handleGoogleSignInError(context, ref, e) ??
          'ทำต่อในหน้าถัดไป หรือกลับไปหน้าเข้าสู่ระบบ';
    }
    if (mounted) {
      setState(() {
        _busy = false;
        _message = message;
      });
    }
  }

  /// Null when Google's page opened, else the Thai message to show.
  Future<String?> _restartWithSchool(
    GoogleNotLinked notLinked, {
    required bool signUp,
  }) async {
    try {
      final schoolId = await pickGoogleSignUpSchool(context, ref);
      if (schoolId == null) return notLinked.message;
      return await restartGoogleWebSignIn(
        ref,
        schoolId: schoolId,
        signUp: signUp,
      );
    } catch (e) {
      return googleSignInErrorMessage(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เข้าสู่ระบบด้วย Google')),
      body: FormColumn(
        children: [
          if (_busy) ...[
            const Center(child: CircularProgressIndicator()),
            const SizedBox(height: 16),
            const Text('กำลังเข้าสู่ระบบ…', textAlign: TextAlign.center),
          ] else ...[
            if (_message case final message?)
              Text(
                message,
                key: const ValueKey('google_return_message'),
                style: TextStyle(color: theme.colorScheme.error),
              ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => context.go(AppRoutes.login),
              child: const Text('กลับไปหน้าเข้าสู่ระบบ'),
            ),
          ],
        ],
      ),
    );
  }
}

/// `/google-link?ticket=…` or `?status=<code>` (DESIGN §24.9.4): where the
/// web app comes back after Google's account chooser of a link. The server's
/// callback links nothing; the one-time ticket (60 seconds) is redeemed here
/// with the signed-in user's token (`POST /me/google-identity/ticket`), so a
/// Google URL opened by somebody else cannot link their account to this
/// user. Signed-in users of every role may open it.
class GoogleLinkResultScreen extends ConsumerStatefulWidget {
  const GoogleLinkResultScreen({super.key, this.status = '', this.ticket});

  /// An error code from the callback (`linked` in tests of the result view).
  final String status;
  final String? ticket;

  @override
  ConsumerState<GoogleLinkResultScreen> createState() =>
      _GoogleLinkResultScreenState();
}

class _GoogleLinkResultScreenState
    extends ConsumerState<GoogleLinkResultScreen> {
  static const _ticketInvalid =
      'ลิงก์เชื่อมบัญชี Google หมดอายุ ถูกใช้ไปแล้ว หรือเปิดจากบัญชีอื่น '
      'กดเชื่อมบัญชี Google ใหม่อีกครั้ง';

  late final String? _ticket = switch (widget.ticket) {
    final String t when t.isNotEmpty && widget.status.isEmpty => t,
    _ => null,
  };
  late bool _busy = _ticket != null;
  late String _status = widget.status.isEmpty && _ticket == null
      ? 'google_error'
      : widget.status;

  bool get _linked => _status == 'linked';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (_ticket case final ticket?) {
        _redeem(ticket);
      } else {
        // The card shows the new state when the user goes back to it.
        ref.invalidate(googleIdentityProvider);
      }
    });
  }

  Future<void> _redeem(String ticket) async {
    String status;
    try {
      await ref.read(googleSignInRepositoryProvider).linkWithWebTicket(ticket);
      status = 'linked';
    } catch (e) {
      status = apiErrorCode(e) ?? 'google_error';
    }
    if (!mounted) return;
    ref.invalidate(googleIdentityProvider);
    setState(() {
      _busy = false;
      _status = status;
    });
  }

  void _back() {
    final router = GoRouter.of(context);
    final user = ref.read(currentUserProvider);
    if (user == null || user.isAdmin) {
      router.go(user == null ? AppRoutes.login : AppRoutes.adminHome);
    } else if (user.isStudent) {
      router.go(AppRoutes.student);
      router.push(AppRoutes.studentAccount);
    } else {
      router.go(AppRoutes.home);
      router.push(AppRoutes.settings);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เชื่อมบัญชี Google')),
      body: FormColumn(
        children: _busy
            ? const [
                Center(child: CircularProgressIndicator()),
                SizedBox(height: 16),
                Text('กำลังเชื่อมบัญชี Google…', textAlign: TextAlign.center),
              ]
            : _result(theme),
      ),
    );
  }

  List<Widget> _result(ThemeData theme) => [
    Icon(
      _linked ? Icons.check_circle_outline : Icons.error_outline,
      size: 48,
      color: _linked ? theme.colorScheme.primary : theme.colorScheme.error,
    ),
    const SizedBox(height: 16),
    Text(
      _linked ? 'เชื่อมบัญชี Google แล้ว' : 'เชื่อมบัญชี Google ไม่สำเร็จ',
      style: theme.textTheme.titleLarge,
      textAlign: TextAlign.center,
    ),
    const SizedBox(height: 8),
    Text(
      _linked
          ? 'ครั้งต่อไปกด "เข้าสู่ระบบด้วย Google" ในหน้าเข้าสู่ระบบได้'
          : _status == 'google_ticket_invalid'
          ? _ticketInvalid
          : googleReturnCodeMessage(_status),
      key: const ValueKey('google_link_result_message'),
      textAlign: TextAlign.center,
    ),
    const SizedBox(height: 24),
    FilledButton(onPressed: _back, child: const Text('กลับ')),
  ];
}
