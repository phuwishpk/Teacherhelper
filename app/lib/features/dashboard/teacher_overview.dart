import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import '../assignments/assignments_providers.dart';
import '../practice/practice_models.dart';
import '../practice/practice_repository.dart';
import '../review/review_repository.dart';

/// Assignments whose queue is read when the list has no
/// `needs_review_count` (newest first).
const overviewQueueLimit = 10;

/// Answers still waiting for the teacher's review over the open
/// assignments: the `needs_review_count` of `GET /assignments` when the
/// server sends it, otherwise counted from each open assignment's review
/// queue (at most [overviewQueueLimit] of them).
final awaitingReviewCountProvider = FutureProvider.autoDispose<int>((
  ref,
) async {
  watchSignedInUser(ref, keepAlive: false);
  final assignments = await ref.watch(assignmentsProvider.future);
  final open = assignments
      .where((a) => !a.isDraft && a.status != 'closed')
      .toList();
  if (assignments.any((a) => a.needsReviewCount != null)) {
    return open.fold<int>(0, (sum, a) => sum + (a.needsReviewCount ?? 0));
  }
  final repo = ref.watch(reviewRepositoryProvider);
  final newest = [...open]..sort((a, b) => b.id.compareTo(a.id));
  final counts = await Future.wait([
    for (final a in newest.take(overviewQueueLimit))
      repo
          .queue(a.id)
          .then(
            (q) => q.items.where((i) => !i.isReviewed && !i.isPublished).length,
          )
          .catchError((Object e) {
            debugPrint('review count of ${a.id}: $e');
            return 0;
          }),
  ]);
  return counts.fold<int>(0, (s, n) => s + n);
}, retry: apiRetry);

/// Draft practice items waiting for approval, or null when the server has
/// no practice bank yet (404).
final draftPracticeCountProvider = FutureProvider.autoDispose<int?>((
  ref,
) async {
  watchSignedInUser(ref, keepAlive: false);
  try {
    final drafts = await ref
        .watch(practiceBankRepositoryProvider)
        .list(status: PracticeItemStatus.draft);
    return drafts.length;
  } on DioException catch (e) {
    if (e.response?.statusCode == 404) return null;
    rethrow;
  }
}, retry: apiRetry);
