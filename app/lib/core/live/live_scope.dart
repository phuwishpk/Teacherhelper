import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/session.dart';
import 'live_session.dart';

/// Firestore and the signed-in user's Firebase uid, or null while the live
/// layer cannot be read: Firebase off in this build, the server without
/// Firestore, or Firebase still holding another account (DESIGN §38).
/// Every live provider starts from here, so they all go quiet together.
({FirebaseFirestore db, String uid})? liveUserScope(Ref ref) {
  final db = ref.watch(liveSessionProvider).firestore;
  final user = ref.watch(currentUserProvider);
  final uid = ref.watch(liveUidProvider).value;
  if (db == null || user == null || uid == null || uid != liveUidOf(user.id)) {
    return null;
  }
  return (db: db, uid: uid);
}

/// Counts the changes of a collection under `users/{uid}` after its first
/// snapshot; a screen listens and refetches from the API when it ticks.
/// MariaDB stays the source of truth, Firestore only says "look again".
Stream<int> liveChangeTicks(Ref ref, String collection) {
  final scope = liveUserScope(ref);
  if (scope == null) return const Stream.empty();
  var ticks = 0;
  return scope.db
      .collection('users/${scope.uid}/$collection')
      .snapshots()
      .skip(1)
      .where((snapshot) => snapshot.docChanges.isNotEmpty)
      .map((_) => ++ticks);
}
