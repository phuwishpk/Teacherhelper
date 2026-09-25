import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A push notification as the app needs it: text for the in-app banner and
/// the `data` map that says where a tap should lead (DESIGN §9.9).
class PushMessage {
  const PushMessage({this.title, this.body, this.data = const {}});

  final String? title;
  final String? body;
  final Map<String, String> data;
}

/// Seam over FCM so the app (and every test) runs without Firebase.
abstract class PushMessaging {
  /// False when this build has no Firebase config (no google-services.json)
  /// or the platform is not Android.
  bool get enabled;

  /// Android 13+ asks the user; earlier versions allow by default.
  Future<void> requestPermission();
  Future<String?> token();

  /// Invalidates this install's token (sign-out), so the server's next send
  /// to it fails and the row can be pruned.
  Future<void> deleteToken();
  Stream<String> get tokenRefreshes;

  /// Messages received while the app is in the foreground (the system does
  /// not show those).
  Stream<PushMessage> get foregroundMessages;

  /// Notification taps while the app was in the background.
  Stream<PushMessage> get openedMessages;

  /// The notification tap that launched the app from terminated, if any.
  Future<PushMessage?> initialMessage();
}

class DisabledPushMessaging implements PushMessaging {
  const DisabledPushMessaging();

  @override
  bool get enabled => false;
  @override
  Future<void> requestPermission() async {}
  @override
  Future<String?> token() async => null;
  @override
  Future<void> deleteToken() async {}
  @override
  Stream<String> get tokenRefreshes => const Stream.empty();
  @override
  Stream<PushMessage> get foregroundMessages => const Stream.empty();
  @override
  Stream<PushMessage> get openedMessages => const Stream.empty();
  @override
  Future<PushMessage?> initialMessage() async => null;
}

class FirebasePushMessaging implements PushMessaging {
  FirebaseMessaging get _fcm => FirebaseMessaging.instance;

  static PushMessage _convert(RemoteMessage m) => PushMessage(
    title: m.notification?.title,
    body: m.notification?.body,
    data: {
      for (final MapEntry(:key, :value) in m.data.entries)
        key: value?.toString() ?? '',
    },
  );

  @override
  bool get enabled => true;

  @override
  Future<void> requestPermission() async {
    await _fcm.requestPermission();
  }

  @override
  Future<String?> token() => _fcm.getToken();

  @override
  Future<void> deleteToken() => _fcm.deleteToken();

  @override
  Stream<String> get tokenRefreshes => _fcm.onTokenRefresh;

  @override
  Stream<PushMessage> get foregroundMessages =>
      FirebaseMessaging.onMessage.map(_convert);

  @override
  Stream<PushMessage> get openedMessages =>
      FirebaseMessaging.onMessageOpenedApp.map(_convert);

  @override
  Future<PushMessage?> initialMessage() async {
    final m = await _fcm.getInitialMessage();
    return m == null ? null : _convert(m);
  }
}

/// Starts Firebase when this build carries a config. The Gradle build only
/// applies the google-services plugin when `android/app/google-services.json`
/// exists; without it `Firebase.initializeApp()` throws and push stays off,
/// so the app runs normally without Firebase.
Future<PushMessaging> initPushMessaging() async {
  if (kIsWeb || defaultTargetPlatform != TargetPlatform.android) {
    return const DisabledPushMessaging();
  }
  try {
    await Firebase.initializeApp().timeout(const Duration(seconds: 5));
    return FirebasePushMessaging();
  } catch (e) {
    debugPrint('Push notifications off: Firebase is not configured ($e)');
    return const DisabledPushMessaging();
  }
}

/// Overridden in main.dart with the result of [initPushMessaging].
final pushMessagingProvider = Provider<PushMessaging>(
  (ref) => const DisabledPushMessaging(),
);
