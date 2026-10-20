import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';

/// Firebase web config from `--dart-define` (DESIGN §38.6, HOSTING §6.5).
/// Empty = this web build does not talk to Firebase.
const _webApiKey = String.fromEnvironment('FIREBASE_WEB_API_KEY');
const _webAppId = String.fromEnvironment('FIREBASE_WEB_APP_ID');
const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
const _senderId = String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID');

/// The options a web build was given, or null when any is missing.
FirebaseOptions? webFirebaseOptions({
  String apiKey = _webApiKey,
  String appId = _webAppId,
  String projectId = _projectId,
  String senderId = _senderId,
}) {
  if (apiKey.isEmpty || appId.isEmpty || projectId.isEmpty) return null;
  return FirebaseOptions(
    apiKey: apiKey,
    appId: appId,
    messagingSenderId: senderId,
    projectId: projectId,
    authDomain: '$projectId.firebaseapp.com',
  );
}

/// Starts Firebase once, before push and the live layer (DESIGN §38.6).
/// Android and iOS read the platform config file (google-services.json /
/// GoogleService-Info.plist); without one `initializeApp` throws and the app
/// runs normally with Firebase off. The web takes [webFirebaseOptions].
/// Returns whether Firebase is ready.
Future<bool> initFirebase() async {
  try {
    if (kIsWeb) {
      final options = webFirebaseOptions();
      if (options == null) {
        debugPrint('Firebase off: no FIREBASE_WEB_* dart-defines');
        return false;
      }
      await Firebase.initializeApp(
        options: options,
      ).timeout(const Duration(seconds: 5));
      return true;
    }
    if (defaultTargetPlatform != TargetPlatform.android &&
        defaultTargetPlatform != TargetPlatform.iOS) {
      return false;
    }
    await Firebase.initializeApp().timeout(const Duration(seconds: 5));
    return true;
  } catch (e) {
    debugPrint('Firebase off: not configured ($e)');
    return false;
  }
}
