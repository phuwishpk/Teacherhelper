import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_providers.dart';
import 'exam_import_models.dart';
import 'exam_import_repository.dart';
import 'exam_models.dart';
import 'exams_repository.dart';

/// One exam with its sections and questions (`GET /exams/{id}`). Every
/// change goes through here so the screens share the same state; answers
/// that carry the whole exam replace it, the others reload it.
class ExamDetailNotifier extends AsyncNotifier<ExamDetail> {
  ExamDetailNotifier(this.examId);

  final int examId;

  ExamsRepository get _repo => ref.read(examsRepositoryProvider);

  @override
  Future<ExamDetail> build() {
    watchSignedInUser(ref);
    return ref.watch(examsRepositoryProvider).get(examId);
  }

  Future<ExamDetail> refresh() {
    ref.invalidateSelf();
    return future;
  }

  /// Reloads the exam and the lists/versions that show parts of it.
  Future<void> _reload({bool versions = true, bool list = false}) async {
    await refresh();
    if (versions) ref.invalidate(examVersionsProvider(examId));
    if (list) ref.invalidate(assignmentsProvider);
  }

  void _set(ExamDetail detail) {
    state = AsyncData(detail);
    ref.invalidate(examVersionsProvider(examId));
  }

  Future<Assignment> updateSettings(Map<String, Object?> changes) async {
    final updated = await _repo.updateSettings(examId, changes);
    await _reload(list: true);
    return updated;
  }

  Future<ExamSection> addSection(ExamSectionDraft draft) async {
    final section = await _repo.addSection(examId, draft);
    await _reload();
    return section;
  }

  Future<void> updateSection(int sectionId, Map<String, Object?> body) async {
    if (body.isEmpty) return;
    await _repo.updateSection(sectionId, body);
    await _reload();
  }

  Future<void> deleteSection(int sectionId) async {
    await _repo.deleteSection(sectionId);
    await _reload();
  }

  Future<ExamQuestion> addQuestion(
    int sectionId,
    ExamQuestionDraft draft,
  ) async {
    final question = await _repo.addQuestion(sectionId, draft);
    await _reload();
    return question;
  }

  Future<ExamQuestion> updateQuestion(
    int questionId,
    ExamQuestionDraft draft,
  ) async {
    final question = await _repo.updateQuestion(questionId, draft);
    await _reload();
    return question;
  }

  Future<void> deleteQuestion(int questionId) async {
    await _repo.deleteQuestion(questionId);
    await _reload();
  }

  Future<void> setQuestionImage(int questionId, PickedDocument? file) async {
    if (file == null) {
      await _repo.deleteQuestionImage(questionId);
    } else {
      await _repo.uploadQuestionImage(questionId, file);
    }
    ref.invalidate(examImageProvider((option: false, id: questionId)));
    await _reload(versions: false);
  }

  Future<void> setOptionImage(int optionId, PickedDocument? file) async {
    if (file == null) {
      await _repo.deleteOptionImage(optionId);
    } else {
      await _repo.uploadOptionImage(optionId, file);
    }
    ref.invalidate(examImageProvider((option: true, id: optionId)));
    await _reload(versions: false);
  }

  Future<void> approveQuestions(List<int> questionIds) async {
    _set(await _repo.approveQuestions(examId, questionIds));
  }

  Future<void> saveAnswerKey(
    List<({int questionId, ExamSectionType type, ExamKey? key})> answers,
  ) async {
    if (answers.isEmpty) return;
    _set(await _repo.saveAnswerKey(examId, answers));
  }

  Future<void> approveKey() async {
    _set(await _repo.approveKey(examId));
    ref.invalidate(assignmentsProvider);
  }

  Future<void> unlockStructure() async {
    _set(await _repo.unlockStructure(examId));
  }

  /// Boxes the figure of a question or option again on [pageImageId]
  /// (`box_2d` in 0–1000); the server crops it anew (§22.4).
  Future<void> setFigure(
    ExamImageKey target, {
    required int pageImageId,
    required List<int> box,
  }) async {
    await ref
        .read(examImportRepositoryProvider)
        .setFigure(target, pageImageId: pageImageId, box: box);
    ref.invalidate(examImageProvider(target));
    await _reload(versions: false);
  }

  /// Copies questions of the teacher's earlier exams into this one.
  Future<ExamCopyResult> copyQuestions(
    List<int> questionIds, {
    int? sectionId,
  }) async {
    final result = await ref
        .read(examImportRepositoryProvider)
        .copyQuestions(examId, questionIds: questionIds, sectionId: sectionId);
    _set(result.exam);
    return result;
  }
}

final examDetailProvider = AsyncNotifierProvider.autoDispose
    .family<ExamDetailNotifier, ExamDetail, int>(ExamDetailNotifier.new);

/// The shuffled versions of an exam with their keys (§22.5).
class ExamVersionsNotifier extends AsyncNotifier<ExamVersions> {
  ExamVersionsNotifier(this.examId);

  final int examId;

  @override
  Future<ExamVersions> build() {
    watchSignedInUser(ref);
    return ref.watch(examsRepositoryProvider).versions(examId);
  }

  /// "สุ่มใหม่": 409 `exam_structure_locked` once printed.
  Future<void> reshuffle() async {
    state = AsyncData(
      await ref.read(examsRepositoryProvider).reshuffle(examId),
    );
  }
}

final examVersionsProvider = AsyncNotifierProvider.autoDispose
    .family<ExamVersionsNotifier, ExamVersions, int>(ExamVersionsNotifier.new);

/// Bytes of a question or option image, kept while a widget shows it.
final examImageProvider = FutureProvider.autoDispose
    .family<Uint8List, ExamImageKey>((ref, key) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(examsRepositoryProvider).image(key);
    });

/// JPEG of a page of the exam file, for drawing a figure box.
final examPageImageProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) {
    watchSignedInUser(ref, keepAlive: false);
    return ref.watch(examImportRepositoryProvider).pageImage(id);
  },
);

/// How often a queued exam read is polled (shorter in tests).
final examReadPollIntervalProvider = Provider<Duration>(
  (ref) => const Duration(seconds: 3),
);

/// How often the read review reloads the exam while the server crops
/// figures (the queue worker runs once a minute; shorter in tests).
final examCropPollIntervalProvider = Provider<Duration>(
  (ref) => const Duration(seconds: 20),
);
