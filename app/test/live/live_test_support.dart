import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_token_api.dart';

/// A live layer whose Firebase session is the given uid (DESIGN §38).
class FakeLiveSession implements LiveSession {
  FakeLiveSession({required this.db, this.uid});

  final FirebaseFirestore? db;
  final String? uid;

  @override
  bool get enabled => true;
  @override
  FirebaseFirestore? get firestore => db;
  @override
  String? get currentUid => uid;
  @override
  Stream<String?> get uidChanges => Stream.value(uid);
  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async => LiveSignInOutcome.alreadySignedIn;
  @override
  Future<void> signOut() async {}
}
