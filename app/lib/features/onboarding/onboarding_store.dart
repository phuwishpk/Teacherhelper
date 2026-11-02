import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/auth/token_storage.dart';
import '../../core/live/app_settings.dart';
import '../../core/live/live_scope.dart';

String currentDeviceName() {
  if (kIsWeb) return 'web';
  return switch (defaultTargetPlatform) {
    TargetPlatform.android => 'android',
    TargetPlatform.iOS => 'ios',
    _ => 'web',
  };
}

/// Where "onboarding finished" lives: the device first, then Firestore
/// `users/{uid}/settings/app` so another device of the same account skips it
/// too (DESIGN §44). Uses [AppSettingsStore] so Firestore access is not
/// duplicated (DESIGN §43).
class OnboardingStore {
  OnboardingStore({
    required this.userId,
    required this.storage,
    this.db,
    this.uid,
    AppSettingsStore? appSettings,
    required this.device,
    DateTime Function()? now,
  })  : _now = now ?? DateTime.now,
        _appSettings = appSettings ??
            AppSettingsStore(
              storage: storage,
              userId: userId,
              db: db,
              uid: uid,
            );

  final int userId;
  final TokenStorage storage;
  final FirebaseFirestore? db;
  final String? uid;
  final AppSettingsStore _appSettings;
  final String device;
  final DateTime Function() _now;

  /// True when neither this device nor Firestore says it was done.
  Future<bool> needed() async {
    if (await storage.readOnboardingDone(userId)) {
      return false;
    }
    final info = await _appSettings.readOnboardingInfo();
    if (info != null) {
      await storage.writeOnboardingDone(userId);
      return false;
    }
    return true;
  }

  /// Saves on the device, then in Firestore; a Firestore failure is ignored.
  Future<void> markDone() async {
    await storage.writeOnboardingDone(userId);
    await _appSettings.writeOnboardingDone(at: _now(), device: device);
  }

  /// When and where it was first finished, read from Firestore (null = unknown).
  Future<({DateTime at, String device})?> doneInfo() async {
    return _appSettings.readOnboardingInfo();
  }
}

final onboardingStoreProvider = Provider.autoDispose<OnboardingStore?>((ref) {
  final user = ref.watch(currentUserProvider);
  if (user == null) return null;
  final scope = liveUserScope(ref);
  final storage = ref.watch(tokenStorageProvider);
  final appSettings = ref.watch(appSettingsStoreProvider);
  return OnboardingStore(
    userId: user.id,
    storage: storage,
    db: scope?.db,
    uid: scope?.uid,
    appSettings: appSettings,
    device: currentDeviceName(),
  );
});

final onboardingNeededProvider = FutureProvider.autoDispose<bool>((ref) async {
  final store = ref.watch(onboardingStoreProvider);
  if (store == null) return false;
  return store.needed();
});

final onboardingInfoProvider =
    FutureProvider.autoDispose<({DateTime at, String device})?>((ref) async {
  final store = ref.watch(onboardingStoreProvider);
  if (store == null) return null;
  return store.doneInfo();
});
