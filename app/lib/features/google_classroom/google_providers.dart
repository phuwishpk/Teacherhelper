import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'classroom_importer.dart';
import 'google_auth.dart';
import 'google_config.dart';
import 'google_models.dart';
import 'google_repository.dart';

/// The teacher's Google connection (settings card, DESIGN §18.7).
class GoogleStatusNotifier extends AsyncNotifier<GoogleStatus> {
  @override
  Future<GoogleStatus> build() async {
    watchSignedInUser(ref);
    if (!ref.watch(googleClassroomEnabledProvider)) {
      return GoogleStatus.disconnected;
    }
    return ref.watch(googleClassroomRepositoryProvider).status();
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  /// Account picker + consent on the phone, then `POST /google/connect`.
  /// Throws [GoogleAuthException] or the DioException of the server.
  Future<GoogleStatus> connect() async {
    final auth = await ref.read(googleAuthProvider).requestServerAuthCode();
    final status = await ref
        .read(googleClassroomRepositoryProvider)
        .connect(auth.serverAuthCode);
    state = AsyncData(status);
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
      ),
    );
  }
}

/// Per teacher: dropped on sign-out (see [watchSignedInUser]).
final googleStatusProvider =
    AsyncNotifierProvider.autoDispose<GoogleStatusNotifier, GoogleStatus>(
      GoogleStatusNotifier.new,
    );

/// Active courses the teacher teaches (course picker).
final googleCoursesProvider = FutureProvider.autoDispose<List<GoogleCourse>>((
  ref,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(googleClassroomRepositoryProvider).courses();
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
  /// [GoogleAuthException] when no Drive token could be had (nothing ran).
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
        final result = await _importer.importSubmission(
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
        if (!ref.mounted) {
          await _importer.discardPending(result.outcomes);
          return;
        }
        _emit(state.withRow(row.id, ImportRow(result: result)));
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
