import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'assignment.dart';
import 'assignments_repository.dart';
import 'question.dart';

class AssignmentsNotifier extends AsyncNotifier<List<Assignment>> {
  @override
  Future<List<Assignment>> build() {
    watchSignedInUser(ref);
    return ref.watch(assignmentsRepositoryProvider).list();
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<Assignment> create({
    required int classroomId,
    required int subjectId,
    required String title,
    Strictness strictness = Strictness.normal,
    DateTime? dueAt,
  }) async {
    final created = await ref
        .read(assignmentsRepositoryProvider)
        .create(
          classroomId: classroomId,
          subjectId: subjectId,
          title: title,
          strictness: strictness,
          dueAt: dueAt,
        );
    state = AsyncData([created, ...state.value ?? const []]);
    return created;
  }

  Future<void> delete(int id) async {
    await ref.read(assignmentsRepositoryProvider).delete(id);
    state = AsyncData([
      for (final a in state.value ?? const <Assignment>[])
        if (a.id != id) a,
    ]);
  }
}

final assignmentsProvider =
    AsyncNotifierProvider.autoDispose<AssignmentsNotifier, List<Assignment>>(
      AssignmentsNotifier.new,
    );

/// Full assignment (with questions) by id.
class AssignmentDetailNotifier extends AsyncNotifier<Assignment> {
  AssignmentDetailNotifier(this.assignmentId);

  final int assignmentId;

  @override
  Future<Assignment> build() {
    watchSignedInUser(ref);
    return ref.watch(assignmentsRepositoryProvider).get(assignmentId);
  }

  Future<Assignment> refresh() {
    ref.invalidateSelf();
    return future;
  }

  Future<void> edit({
    String? title,
    Strictness? strictness,
    DateTime? dueAt,
    bool clearDueAt = false,
    String? status,
  }) async {
    await ref
        .read(assignmentsRepositoryProvider)
        .update(
          assignmentId,
          title: title,
          strictness: strictness,
          dueAt: dueAt,
          clearDueAt: clearDueAt,
          status: status,
        );
    await refresh();
    ref.invalidate(assignmentsProvider);
  }

  Future<void> addQuestion(QuestionDraft draft) async {
    await ref
        .read(assignmentsRepositoryProvider)
        .addQuestion(assignmentId, draft);
    await refresh();
  }

  Future<void> updateQuestion(int questionId, QuestionDraft draft) async {
    await ref
        .read(assignmentsRepositoryProvider)
        .updateQuestion(questionId, draft);
    await refresh();
  }

  Future<void> deleteQuestion(int questionId) async {
    await ref.read(assignmentsRepositoryProvider).deleteQuestion(questionId);
    await refresh();
  }

  /// After "โพสต์ลง Classroom" (DESIGN §18.6), so the card shows the link
  /// even when the server's assignment JSON has no `google_link`.
  void setGoogleLink(AssignmentGoogleLink link) {
    final current = state.value;
    if (current != null) state = AsyncData(current.withGoogleLink(link));
  }

  Future<LayoutVersion> createLayout() async {
    final layout = await ref
        .read(assignmentsRepositoryProvider)
        .createLayout(assignmentId);
    await refresh();
    ref.invalidate(assignmentsProvider);
    return layout;
  }
}

final assignmentDetailProvider = AsyncNotifierProvider.autoDispose
    .family<AssignmentDetailNotifier, Assignment, int>(
      AssignmentDetailNotifier.new,
    );

final subjectsProvider = FutureProvider.autoDispose<List<Subject>>((ref) {
  watchSignedInUser(ref);
  return ref.watch(assignmentsRepositoryProvider).subjects();
});
