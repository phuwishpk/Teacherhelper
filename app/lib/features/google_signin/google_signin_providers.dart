import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import '../google_classroom/google_browser_connect.dart' show ExternalUrlOpener;
import 'google_signin_config.dart';
import 'google_signin_gateway.dart';
import 'google_signin_models.dart';
import 'google_signin_repository.dart';

/// How this build could get a Google account, before asking the server.
final googleSignInModeProvider = Provider<GoogleSignInMode>(
  (ref) => kIsWeb
      ? GoogleSignInMode.web
      : googleSignInNativeBuild
      ? GoogleSignInMode.native
      : GoogleSignInMode.none,
);

/// How the Google buttons work right now (DESIGN §24.9.4): the build's
/// [googleSignInModeProvider] when the server has sign-in `enabled` (and,
/// on the web, its `web_flow`), else [GoogleSignInMode.none]. A build that
/// cannot use Google never asks the server; a failed config read hides the
/// buttons (the provider is auto-disposed, so the next screen asks again).
final googleSignInAvailabilityProvider =
    FutureProvider.autoDispose<GoogleSignInMode>((ref) async {
      final mode = ref.watch(googleSignInModeProvider);
      if (mode == GoogleSignInMode.none) return GoogleSignInMode.none;
      try {
        final config = await ref.watch(googleSignInRepositoryProvider).config();
        if (!config.enabled) return GoogleSignInMode.none;
        if (mode == GoogleSignInMode.web && !config.webFlow) {
          return GoogleSignInMode.none;
        }
        return mode;
      } catch (e) {
        debugPrint('Google sign-in config unavailable: $e');
        return GoogleSignInMode.none;
      }
    });

/// The signed-in user's Google account for sign-in (`/me/google-identity`),
/// for the cards of the teacher settings, the student's "บัญชีของฉัน" and
/// the admin page. Per user (see [watchSignedInUser]).
class GoogleIdentityNotifier extends AsyncNotifier<GoogleIdentity> {
  @override
  Future<GoogleIdentity> build() {
    watchSignedInUser(ref, keepAlive: false);
    return ref.watch(googleSignInRepositoryProvider).identity();
  }

  /// The picker on the phone, then `POST /me/google-identity`. Throws a
  /// GoogleAuthException or the server's DioException.
  Future<GoogleIdentity> linkOnDevice({required bool acceptNotice}) async {
    final idToken = await ref.read(googleSignInGatewayProvider).idToken();
    final identity = await ref
        .read(googleSignInRepositoryProvider)
        .link(idToken: idToken, acceptNotice: acceptNotice);
    if (ref.mounted) state = AsyncData(identity);
    return identity;
  }

  Future<void> unlink() async {
    await ref.read(googleSignInRepositoryProvider).unlink();
    if (ref.mounted) ref.invalidateSelf();
  }
}

final googleIdentityProvider =
    AsyncNotifierProvider.autoDispose<GoogleIdentityNotifier, GoogleIdentity>(
      GoogleIdentityNotifier.new,
      retry: apiRetry,
    );

/// The `link_ticket` of a student's 404 `google_not_linked`, held for the
/// "ยืนยันตัวตนครั้งแรก" screen and its QR scanner (it lives 10 minutes on
/// the server and is spent by a successful confirmation). Never stored.
class GoogleLinkTicketNotifier extends Notifier<String?> {
  /// The starting value (tests).
  @visibleForTesting
  String? initial;

  @override
  String? build() => initial;

  void set(String? ticket) => state = ticket;
}

final googleLinkTicketProvider =
    NotifierProvider<GoogleLinkTicketNotifier, String?>(
      GoogleLinkTicketNotifier.new,
    );

/// The web flow leaves the app for Google's page in the same tab
/// (`webOnlyWindowName: '_self'`, DESIGN §24.9.4) and comes back through
/// the server's redirect.
Future<bool> openInSameTab(Uri url) async {
  try {
    return await launchUrl(url, webOnlyWindowName: '_self');
  } catch (e) {
    debugPrint('launchUrl failed: $e');
    return false;
  }
}

/// A seam so widget tests do not leave the page.
final sameTabUrlOpenerProvider = Provider<ExternalUrlOpener>(
  (ref) => openInSameTab,
);
