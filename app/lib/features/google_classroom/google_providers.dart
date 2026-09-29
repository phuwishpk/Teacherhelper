import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import 'classroom_importer.dart';
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

/// Thai note wherever the web cannot do what the phone does (§18.2 "รับงาน").
const phoneOnlyScanNote = 'ดาวน์โหลดและสแกนงานที่ส่งต้องทำบนแอป Android';

/// Downloading and scanning Classroom submissions needs the phone (Drive
/// token from Google Sign-In, the native scan pipeline). False on the web,
/// where the importer is not even built.
final classroomScanSupportedProvider = Provider<bool>(
  (ref) => !kIsWeb && ref.watch(classroomImporterProvider).isSupported,
);

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
    return queued;
  }
}

final googleSubmissionsProvider = AsyncNotifierProvider.autoDispose
    .family<GoogleSubmissionsNotifier, List<GoogleSubmission>, int>(
      GoogleSubmissionsNotifier.new,
    );

/// Download-and-scan state of one submission row.
class ImportRow {
  const ImportRow({this.running = false, this.status, this.result});

  final bool running;

  /// Progress line while [running].
  final String? status;
  final SubmissionImport? result;
}

class ClassroomImportState {
  const ClassroomImportState({this.rows = const {}, this.batch});

  /// By import row id (`GoogleSubmission.id`).
  final Map<int, ImportRow> rows;

  /// "2/7" while "ดาวน์โหลดและสแกนทั้งหมด" runs.
  final String? batch;

  bool get running => batch != null || rows.values.any((r) => r.running);

  ClassroomImportState withRow(
    int id,
    ImportRow row, {
    Object? batch = _keep,
  }) => ClassroomImportState(
    rows: {...rows, id: row},
    batch: identical(batch, _keep) ? this.batch : batch as String?,
  );

  ClassroomImportState withBatch(String? batch) =>
      ClassroomImportState(rows: rows, batch: batch);
}

const _keep = Object();

/// "ดาวน์โหลดและสแกน" stopped part way because Google Sign-In refused a new
/// Drive token (the old one expired after about an hour, the teacher closed
/// the consent screen, or picked another account). Rows done so far keep
/// their results; running again picks up the rest.
class ClassroomImportStopped implements Exception {
  const ClassroomImportStopped(
    this.cause, {
    required this.done,
    required this.total,
  });

  final GoogleAuthException cause;

  /// Submissions finished before the stop.
  final int done;
  final int total;

  /// Thai, for a snackbar.
  String get message {
    final head = cause is GoogleAuthCanceled
        ? 'หยุดดาวน์โหลดและสแกนแล้ว เพราะปิดหน้าลงชื่อเข้าใช้ Google'
        : 'หยุดดาวน์โหลดและสแกน: ${cause.message}';
    return total > 1
        ? '$head (เสร็จ $done จาก $total งาน กดดาวน์โหลดอีกครั้งเพื่อทำต่อ)'
        : head;
  }

  @override
  String toString() => 'ClassroomImportStopped($cause, $done/$total)';
}

/// Runs [ClassroomImporter] for the submissions screen of one assignment.
/// Kept alive while a download runs, so leaving the screen does not stop it;
/// pictures still waiting for a blur decision are deleted on dispose.
class ClassroomImportController extends Notifier<ClassroomImportState> {
  ClassroomImportController(this.assignmentId);

  final int assignmentId;

  /// Mirror of [state]: neither `state` nor `ref` may be used in onDispose.
  ClassroomImportState _latest = const ClassroomImportState();

  void _emit(ClassroomImportState next) => state = _latest = next;

  @override
  ClassroomImportState build() {
    watchSignedInUser(ref, keepAlive: false);
    final importer = ref.read(classroomImporterProvider);
    ref.onDispose(() {
      final pending = [
        for (final row in _latest.rows.values) ...?row.result?.outcomes,
      ];
      if (pending.isNotEmpty) unawaited(importer.discardPending(pending));
    });
    return _latest = const ClassroomImportState();
  }

  ClassroomImporter get _importer => ref.read(classroomImporterProvider);

  /// Downloads and scans [rows] one after the other. Throws
  /// [GoogleAuthException] when no Drive token could be had (nothing ran),
  /// [ClassroomImportStopped] when a new token was refused part way, and
  /// rethrows anything else; a row never stays "running" after an error.
  Future<void> run(List<GoogleSubmission> rows, {String? expectedEmail}) async {
    if (rows.isEmpty || state.running) return;
    final link = ref.keepAlive();
    try {
      _emit(state.withBatch(rows.length > 1 ? '0/${rows.length}' : null));
      final session = await _importer.openSession(expectedEmail: expectedEmail);
      for (var i = 0; i < rows.length; i++) {
        if (!ref.mounted) return;
        final row = rows[i];
        final previous = state.rows[row.id]?.result;
        if (previous != null) {
          await _importer.discardPending(previous.outcomes);
        }
        _emit(
          state.withRow(
            row.id,
            const ImportRow(running: true, status: 'กำลังเริ่ม…'),
            batch: rows.length > 1 ? '${i + 1}/${rows.length}' : null,
          ),
        );
        final SubmissionImport result;
        try {
          result = await _importer.importSubmission(
            row,
            assignmentId: assignmentId,
            session: session,
            onProgress: (status) {
              if (!ref.mounted) return;
              _emit(
                state.withRow(row.id, ImportRow(running: true, status: status)),
              );
            },
          );
        } catch (_) {
          if (ref.mounted) {
            _emit(
              state.withRow(
                row.id,
                ImportRow(
                  result: SubmissionImport(
                    submissionId: row.id,
                    outcomes: const [],
                    interruption: 'ดาวน์โหลดหรือสแกนไม่สำเร็จ ลองอีกครั้ง',
                  ),
                ),
              ),
            );
          }
          rethrow;
        }
        if (!ref.mounted) {
          await _importer.discardPending(result.outcomes);
          return;
        }
        _emit(state.withRow(row.id, ImportRow(result: result)));
        if (result.authFailure case final failure?) {
          throw ClassroomImportStopped(failure, done: i, total: rows.length);
        }
      }
    } finally {
      if (ref.mounted) _emit(state.withBatch(null));
      link.close();
    }
  }

  /// "ใช้ภาพนี้ต่อ" on a picture that only failed the blur check.
  Future<void> acceptDespiteBlur(
    GoogleSubmission submission,
    ImageRejected rejected,
  ) async {
    final result = state.rows[submission.id]?.result;
    if (result == null) return;
    final next = await _importer.acceptDespiteBlur(
      rejected,
      submission: submission,
      assignmentId: assignmentId,
    );
    if (!ref.mounted) return;
    _emit(
      state.withRow(
        submission.id,
        ImportRow(result: result.replace(rejected, next)),
      ),
    );
  }
}

final classroomImportProvider = NotifierProvider.autoDispose
    .family<ClassroomImportController, ClassroomImportState, int>(
      ClassroomImportController.new,
    );
