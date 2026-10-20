import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'live_session.dart';
import 'live_token_api.dart';

/// The outcome of the last attempt to sign in to Firebase (DESIGN §38.8),
/// null before any or while one runs. SessionNotifier starts it after login
/// and restore; the settings card shows it and can try again, e.g. after
/// the server switched FIRESTORE_ENABLED on while the app was open.
class LiveConnectionNotifier extends Notifier<LiveSignInOutcome?> {
  @override
  LiveSignInOutcome? build() => null;

  Future<LiveSignInOutcome> connect(int userId) async {
    final live = ref.read(liveSessionProvider);
    if (!live.enabled) return state = LiveSignInOutcome.disabled;
    state = null;
    final outcome = await live.signIn(
      userId,
      ref.read(liveTokenApiProvider).fetch,
    );
    state = outcome;
    return outcome;
  }

  void reset() => state = null;
}

final liveConnectionProvider =
    NotifierProvider<LiveConnectionNotifier, LiveSignInOutcome?>(
      LiveConnectionNotifier.new,
    );

/// What the settings card says (DESIGN §38.8).
enum LiveStatus { off, connecting, connected, serverOff, failed }

final liveStatusProvider = Provider.family<LiveStatus, int>((ref, userId) {
  if (!ref.watch(liveSessionProvider).enabled) return LiveStatus.off;
  if (ref.watch(liveUidProvider).value == liveUidOf(userId)) {
    return LiveStatus.connected;
  }
  return switch (ref.watch(liveConnectionProvider)) {
    LiveSignInOutcome.serverOff => LiveStatus.serverOff,
    LiveSignInOutcome.failed => LiveStatus.failed,
    LiveSignInOutcome.disabled => LiveStatus.off,
    _ => LiveStatus.connecting,
  };
});
