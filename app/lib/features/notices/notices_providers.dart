import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../../core/live/live_session.dart';
import 'notice.dart';

/// How many notices the inbox lists (the TTL drops the rest server-side).
const noticesLimit = 100;

/// The signed-in user's inbox, newest first, as Firestore changes it
/// (DESIGN §38 step 2). Null when the live layer is unavailable: Firebase
/// off in this build, the server without Firestore, or the Firebase session
/// not (yet) the signed-in user's.
final noticesProvider = StreamProvider<List<Notice>?>((ref) {
  final live = ref.watch(liveSessionProvider);
  final user = ref.watch(currentUserProvider);
  final uid = ref.watch(liveUidProvider).value;
  final db = live.firestore;
  if (db == null || user == null || uid == null || uid != liveUidOf(user.id)) {
    return Stream.value(null);
  }
  return db
      .collection('users/$uid/notices')
      .orderBy('created_at', descending: true)
      .limit(noticesLimit)
      .snapshots()
      .map((snapshot) => snapshot.docs.map(Notice.fromDoc).toList());
});

/// The badge on the bell: unread notices, 0 while the inbox is unavailable.
final unreadNoticesProvider = Provider<int>((ref) {
  final notices = ref.watch(noticesProvider).value;
  if (notices == null) return 0;
  return notices.where((n) => !n.read).length;
});
