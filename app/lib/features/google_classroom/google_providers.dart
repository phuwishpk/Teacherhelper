import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import '../home/teacher_attention.dart';
import 'google_auth.dart';
import 'google_config.dart';
import 'google_models.dart';
import 'google_repository.dart';

/// The teacher's Google connection (settings card, DESIGN §18.7). Always
/// asked of the server: its `configured` decides whether the app shows
/// anything about Google Classroom (see [googleClassroomEnabledProvider]).
class GoogleStatusNotifier extends AsyncNotifier<GoogleStatus> {
  @override
  Future<GoogleStatus> build() async {
    watchSignedInUser(ref);
    return ref.watch(googleClassroomRepositoryProvider).status();
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  /// Account picker + consent on the phone, then `POST /google/connect`
  /// (the native flow: [GoogleAuthGateway.supportsServerAuthCode]).
  /// Throws [GoogleAuthException] or the DioException of the server.
  Future<GoogleStatus> connect() async {
    final auth = await ref.read(googleAuthProvider).requestServerAuthCode();
    final status = await ref
        .read(googleClassroomRepositoryProvider)
        .connect(auth.serverAuthCode);
    state = AsyncData(status);
    return status;
  }

  /// The browser flow: Google's consent page to open outside the app
  /// (`POST /google/oauth/url`). The server finishes the connection; watch
  /// for it with [checkConnection].
  Future<Uri> browserConnectUrl() =>
      ref.read(googleClassroomRepositoryProvider).oauthUrl();

  /// One `GET /google/status` without showing a reload. When the teacher
  /// is connected and ready, the new status replaces the current one and
  /// the course list is fetched again.
  Future<GoogleStatus> checkConnection() async {
    final status = await ref.read(googleClassroomRepositoryProvider).status();
    if (status.ready && ref.mounted) {
      state = AsyncData(status);
      ref.invalidate(googleCoursesProvider);
    }
    return status;
  }

  /// `DELETE /google/disconnect` (server revokes), then forgets the account
  /// on the phone too.
  Future<void> disconnect() async {
    await ref.read(googleClassroomRepositoryProvider).disconnect();
    await ref.read(googleAuthProvider).signOut();
    state = const AsyncData(GoogleStatus.disconnected);
  }

  /// A request failed with an expired-connection code: show "เชื่อมใหม่".
  void markNeedsReconnect() {
    final current = state.value;
    if (current == null || !current.connected) {
      ref.invalidateSelf();
      return;
    }
    state = AsyncData(
      GoogleStatus(
        connected: true,
        email: current.email,
        scopes: current.scopes,
        needsReconnect: true,
        configured: current.configured,
      ),
    );
  }
}

/// Per teacher: dropped on sign-out (see [watchSignedInUser]). A 4xx is
/// not retried (the card offers "ลองใหม่").
final googleStatusProvider =
    AsyncNotifierProvider.autoDispose<GoogleStatusNotifier, GoogleStatus>(
      GoogleStatusNotifier.new,
      retry: apiRetry,
    );

/// Whether the teacher sees the Google Classroom UI (settings card,
/// classroom and assignment sections): the server has its OAuth client
/// (`GET /google/status` -> `configured`), with or without
/// GOOGLE_SERVER_CLIENT_ID in this build. Until the status arrives (or
/// when it cannot be read) a build with the client id shows the UI, so its
/// loading and retry states stay visible, and any other build hides it.
final googleClassroomEnabledProvider = Provider.autoDispose<bool>((ref) {
  final status = ref.watch(googleStatusProvider);
  return status.value?.configured ?? googleNativeSignInBuild;
});

/// Active courses the teacher teaches (course picker).
final googleCoursesProvider = FutureProvider.autoDispose<List<GoogleCourse>>((
  ref,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(googleClassroomRepositoryProvider).courses();
});

/// What importing a course would create (DESIGN §19.2 preview screen).
final googleImportPreviewProvider = FutureProvider.autoDispose
    .family<ClassroomImportPreview, String>((ref, courseId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(googleClassroomRepositoryProvider)
          .importPreview(courseId);
    });

/// Students of the linked course with suggested pairs (matching screen).
final googleRosterProvider = FutureProvider.autoDispose
    .family<List<GoogleRosterEntry>, int>((ref, classroomId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(googleClassroomRepositoryProvider).roster(classroomId);
    });

/// Classroom submissions of one assignment. Loading syncs from Classroom on
/// the server, so it only reloads when asked.
class GoogleSubmissionsNotifier extends AsyncNotifier<List<GoogleSubmission>> {
  GoogleSubmissionsNotifier(this.assignmentId);

  final int assignmentId;

  @override
  Future<List<GoogleSubmission>> build() {
    watchSignedInUser(ref, keepAlive: false);
    return ref
        .watch(googleClassroomRepositoryProvider)
        .submissions(assignmentId);
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<void> returnForRetake(GoogleSubmission row, String reason) async {
    final updated = await ref
        .read(googleClassroomRepositoryProvider)
        .returnForRetake(row.id, reason);
    final rows = state.value;
    if (updated == null || rows == null) {
      await refresh();
      return;
    }
    state = AsyncData([for (final r in rows) r.id == row.id ? updated : r]);
  }

  Future<int?> retryGrades() async {
    final queued = await ref
        .read(googleClassroomRepositoryProvider)
        .retryGrades(assignmentId);
    await refresh();
    ref.invalidate(teacherAttentionProvider);
    return queued;
  }

  /// "รับงานส่งช้า": the row goes back to `new` (late) and the next sync
  /// round downloads and grades it (DESIGN §19.3).
  Future<void> acceptLate(GoogleSubmission row) async {
    final updated = await ref
        .read(googleClassroomRepositoryProvider)
        .acceptLate(row.id);
    final rows = state.value;
    if (updated == null || rows == null) {
      await refresh();
      return;
    }
    state = AsyncData([for (final r in rows) r.id == row.id ? updated : r]);
  }
}

final googleSubmissionsProvider = AsyncNotifierProvider.autoDispose
    .family<GoogleSubmissionsNotifier, List<GoogleSubmission>, int>(
      GoogleSubmissionsNotifier.new,
    );

/// "คะแนนไม่ตรงกัน" of one assignment (DESIGN §19.3): grades the teacher
/// changed on the Classroom website, and how each was settled.
class GradeConflictsNotifier extends AsyncNotifier<List<GradeConflict>> {
  GradeConflictsNotifier(this.assignmentId);

  final int assignmentId;

  @override
  Future<List<GradeConflict>> build() {
    watchSignedInUser(ref, keepAlive: false);
    return ref
        .watch(googleClassroomRepositoryProvider)
        .gradeConflicts(assignmentId);
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  /// Settles [conflict]; the row is replaced with the server's answer, and
  /// the home count and the submissions list follow.
  Future<GradeConflict> resolve(
    GradeConflict conflict,
    GradeConflictAction action,
  ) async {
    final updated = await ref
        .read(googleClassroomRepositoryProvider)
        .resolveConflict(conflict.id, action);
    final rows = state.value;
    if (rows != null) {
      final next = [for (final r in rows) r.id == conflict.id ? updated : r]
        ..sort(_openFirst);
      state = AsyncData(next);
    }
    ref.invalidate(teacherAttentionProvider);
    ref.invalidate(googleSubmissionsProvider(assignmentId));
    return updated;
  }

  /// The server's order: open rows first, then the newest.
  static int _openFirst(GradeConflict a, GradeConflict b) {
    if (a.isOpen != b.isOpen) return a.isOpen ? -1 : 1;
    return b.id.compareTo(a.id);
  }
}

final gradeConflictsProvider = AsyncNotifierProvider.autoDispose
    .family<GradeConflictsNotifier, List<GradeConflict>, int>(
      GradeConflictsNotifier.new,
    );
