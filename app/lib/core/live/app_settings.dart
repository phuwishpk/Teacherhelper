import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../auth/session.dart';
import '../auth/token_storage.dart';
import 'live_scope.dart';

/// Storage for app settings persisted on device and in Firestore (DESIGN §43).
class AppSettingsStore {
  AppSettingsStore({required this.storage, this.userId, this.db, this.uid});

  final TokenStorage storage;
  final int? userId;
  final FirebaseFirestore? db;
  final String? uid;

  DocumentReference<Map<String, dynamic>>? get _doc {
    final firestore = db;
    final userUid = uid;
    if (firestore == null || userUid == null || userUid.isEmpty) {
      return null;
    }
    return firestore
        .collection('users')
        .doc(userUid)
        .collection('settings')
        .doc('app');
  }

  /// Reads whether the mascot is enabled.
  /// Checks local storage first, then Firestore.
  /// If Firestore has a value, syncs it to local storage.
  /// Defaults to `true` on error or when unset.
  Future<bool> readMascotEnabled() async {
    bool? local;
    final id = userId;
    if (id != null) {
      try {
        local = await storage.readMascotEnabled(id);
      } catch (e) {
        debugPrint('Failed to read mascotEnabled locally: $e');
      }
    }

    try {
      final doc = _doc;
      if (doc != null) {
        final snap = await doc.get();
        if (snap.exists) {
          final data = snap.data();
          if (data != null && data['mascot_enabled'] is bool) {
            final remote = data['mascot_enabled'] as bool;
            if (id != null && remote != local) {
              try {
                await storage.writeMascotEnabled(id, remote);
              } catch (e) {
                debugPrint('Failed to sync mascotEnabled locally: $e');
              }
            }
            return remote;
          }
        }
      }
    } catch (e) {
      debugPrint('Failed to read mascotEnabled from Firestore: $e');
    }

    return local ?? true;
  }

  /// Writes whether the mascot is enabled to local storage and Firestore.
  Future<void> writeMascotEnabled(bool on) async {
    final id = userId;
    if (id != null) {
      try {
        await storage.writeMascotEnabled(id, on);
      } catch (e) {
        debugPrint('Failed to write mascotEnabled locally: $e');
      }
    }

    try {
      final doc = _doc;
      if (doc != null) {
        await doc.set({'mascot_enabled': on}, SetOptions(merge: true));
      }
    } catch (e) {
      debugPrint('Failed to write mascotEnabled to Firestore: $e');
    }
  }

  /// Reads onboarding done info (at, device) from Firestore.
  Future<({DateTime at, String device})?> readOnboardingInfo() async {
    try {
      final doc = _doc;
      if (doc != null) {
        final snap = await doc.get();
        if (snap.exists) {
          final data = snap.data();
          if (data != null && data['onboarding_done_at'] is Timestamp) {
            final at = (data['onboarding_done_at'] as Timestamp)
                .toDate()
                .toUtc();
            final device = (data['device'] as String?) ?? 'unknown';
            return (at: at, device: device);
          }
        }
      }
    } catch (e) {
      debugPrint('Failed to read onboarding info from Firestore: $e');
    }
    return null;
  }

  /// Writes onboarding done to Firestore with merge if not set already.
  Future<void> writeOnboardingDone({
    required DateTime at,
    required String device,
  }) async {
    try {
      final doc = _doc;
      if (doc != null) {
        final snap = await doc.get();
        if (snap.exists && snap.data()?['onboarding_done_at'] != null) {
          // Do not overwrite first time
          return;
        }
        await doc.set({
          'onboarding_done_at': Timestamp.fromDate(at),
          'device': device,
        }, SetOptions(merge: true));
      }
    } catch (e) {
      debugPrint('Failed to write onboarding done to Firestore: $e');
    }
  }
}

final appSettingsStoreProvider = Provider<AppSettingsStore>((ref) {
  TokenStorage storage;
  try {
    storage = ref.watch(tokenStorageProvider);
  } catch (_) {
    storage = InMemoryTokenStorage();
  }
  final user = ref.watch(currentUserProvider);
  final scope = liveUserScope(ref);
  return AppSettingsStore(
    storage: storage,
    userId: user?.id,
    db: scope?.db,
    uid: scope?.uid,
  );
});

class MascotEnabledNotifier extends Notifier<bool> {
  @override
  bool build() {
    final store = ref.watch(appSettingsStoreProvider);
    _load(store);
    return true;
  }

  Future<void> _load(AppSettingsStore store) async {
    try {
      final value = await store.readMascotEnabled();
      state = value;
    } catch (e) {
      debugPrint('Error loading mascotEnabled: $e');
    }
  }

  Future<void> setEnabled(bool enabled) async {
    state = enabled;
    try {
      final store = ref.read(appSettingsStoreProvider);
      await store.writeMascotEnabled(enabled);
    } catch (e) {
      debugPrint('Error saving mascotEnabled: $e');
    }
  }

  Future<void> set(bool enabled) => setEnabled(enabled);
}

final mascotEnabledProvider = NotifierProvider<MascotEnabledNotifier, bool>(
  MascotEnabledNotifier.new,
);
