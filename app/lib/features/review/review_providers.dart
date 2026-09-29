import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'review_models.dart';
import 'review_repository.dart';

/// Review queue of one assignment (all tabs; split client-side).
class ReviewQueueNotifier extends AsyncNotifier<ReviewQueue> {
  ReviewQueueNotifier(this.assignmentId);

  final int assignmentId;

  ReviewRepository get _repo => ref.read(reviewRepositoryProvider);

  @override
  Future<ReviewQueue> build() {
    watchSignedInUser(ref);
    return ref.watch(reviewRepositoryProvider).queue(assignmentId);
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<int> approveConfident() async {
    final n = await _repo.approveConfident(assignmentId);
    await refresh();
    return n;
  }

  Future<PublishResult> publishAll() async {
    final result = await _repo.publishAssignment(assignmentId);
    await refresh();
    return result;
  }

  Future<void> publishSubmission(int submissionId) async {
    await _repo.publishSubmission(submissionId);
    await refresh();
  }

  Future<void> gradeSubmission(int submissionId) async {
    await _repo.gradeSubmission(submissionId);
    await refresh();
  }

  Future<int> requeueMissingKey() async {
    final n = await _repo.requeueMissingKey(assignmentId);
    await refresh();
    return n;
  }

  Future<void> confirmReplace(int scanId) async {
    await _repo.confirmReplace(scanId);
    await refresh();
  }
}

final reviewQueueProvider = AsyncNotifierProvider.autoDispose
    .family<ReviewQueueNotifier, ReviewQueue, int>(ReviewQueueNotifier.new);

/// Full detail of one response while its pane is on screen.
final responseDetailProvider = FutureProvider.autoDispose
    .family<ResponseDetail, int>((ref, id) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(reviewRepositoryProvider).response(id);
    });

/// Open appeals of the teacher's classrooms (`GET /appeals?status=open`).
class OpenAppealsNotifier extends AsyncNotifier<List<Appeal>> {
  @override
  Future<List<Appeal>> build() {
    watchSignedInUser(ref);
    return ref.watch(reviewRepositoryProvider).appeals();
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<void> resolve(
    Appeal appeal, {
    required bool accept,
    String? teacherNote,
    double? finalScore,
  }) async {
    await ref
        .read(reviewRepositoryProvider)
        .resolveAppeal(
          appeal.id,
          status: accept ? 'accepted' : 'rejected',
          teacherNote: teacherNote,
          finalScore: accept ? finalScore : null,
        );
    state = AsyncData([
      for (final a in state.value ?? const <Appeal>[])
        if (a.id != appeal.id) a,
    ]);
    if (appeal.assignmentId case final id?) {
      ref.invalidate(reviewQueueProvider(id));
    }
    if (appeal.responseId case final id?) {
      ref.invalidate(responseDetailProvider(id));
    }
  }
}

final openAppealsProvider =
    AsyncNotifierProvider.autoDispose<OpenAppealsNotifier, List<Appeal>>(
      OpenAppealsNotifier.new,
    );
