import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../home/teacher_attention.dart';
import 'answer_key_models.dart';
import 'answer_key_repository.dart';
import 'assignments_providers.dart';

/// How often the answer-key screen asks whether Gemini finished reading.
/// The queue worker runs once a minute on the host (CLAUDE.md), so a read
/// takes up to a minute or two; tests shorten it.
final answerKeyPollIntervalProvider = Provider<Duration>(
  (ref) => const Duration(seconds: 5),
);

/// `GET /assignments/{id}/answer-key`, polled while a read or draft is
/// queued. Not kept alive: the poll stops when the screen closes.
class AnswerKeyNotifier extends AsyncNotifier<AnswerKeyState> {
  AnswerKeyNotifier(this.assignmentId);

  final int assignmentId;
  Timer? _poll;

  AnswerKeyRepository get _repo => ref.read(answerKeyRepositoryProvider);

  @override
  Future<AnswerKeyState> build() async {
    watchSignedInUser(ref, keepAlive: false);
    ref.onDispose(() => _poll?.cancel());
    final key = await ref
        .watch(answerKeyRepositoryProvider)
        .answerKey(assignmentId);
    _pollIfReading(key);
    return key;
  }

  Future<AnswerKeyState> refresh() {
    ref.invalidateSelf();
    return future;
  }

  /// The answer of extract/draft (sent by the read screen): the questions
  /// as filled now, polled on while Gemini is still reading.
  void accept(KeyRequestResult result) => _set(result.answerKey);

  Future<AnswerKeyState> approve({int? subjectId}) async {
    final key = await _repo.approve(assignmentId, subjectId: subjectId);
    _set(key);
    ref.invalidate(assignmentsProvider);
    ref.invalidate(teacherAttentionProvider);
    return key;
  }

  void _set(AnswerKeyState key) {
    state = AsyncData(key);
    // The questions and the status of the assignment changed with the key.
    ref.invalidate(assignmentDetailProvider(assignmentId));
    _pollIfReading(key);
  }

  void _pollIfReading(AnswerKeyState key) {
    _poll?.cancel();
    if (!key.reading) return;
    _poll = Timer(ref.read(answerKeyPollIntervalProvider), () async {
      try {
        final next = await _repo.answerKey(assignmentId);
        if (!ref.mounted) return;
        if (next.reading) {
          state = AsyncData(next);
          _pollIfReading(next);
        } else {
          _set(next);
        }
      } catch (_) {
        // A network blip: ask again at the next tick.
        if (ref.mounted) _pollIfReading(key);
      }
    });
  }
}

final answerKeyProvider = AsyncNotifierProvider.autoDispose
    .family<AnswerKeyNotifier, AnswerKeyState, int>(AnswerKeyNotifier.new);
