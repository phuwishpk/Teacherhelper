import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import 'review_models.dart';

/// Teacher review, publishing and appeals (DESIGN §9.5, §9.4 confirm-replace).
abstract class ReviewRepository {
  /// Every response of the assignment (all bands, all pages) plus `meta`.
  Future<ReviewQueue> queue(int assignmentId);
  Future<ResponseDetail> response(int id);

  /// `PATCH /responses/{id}`: saving also marks the response reviewed.
  Future<ResponseDetail> saveReview(int id, ReviewDecision decision);

  /// Returns the new explanation when the server wrote it synchronously,
  /// null when it was queued.
  Future<String?> regenerateExplanation(int id);

  /// Returns how many responses were approved.
  Future<int> approveConfident(int assignmentId);
  Future<void> publishSubmission(int submissionId);
  Future<PublishResult> publishAssignment(int assignmentId);

  /// `POST /assignments/{id}/requeue-missing-key`: returns how many
  /// `manual` responses with reason `ai_key_missing` went back to grading.
  Future<int> requeueMissingKey(int assignmentId);

  Future<List<Appeal>> appeals({String status = 'open'});

  /// [status] is `accepted` or `rejected`; [finalScore] only when accepted.
  Future<Appeal> resolveAppeal(
    int id, {
    required String status,
    String? teacherNote,
    double? finalScore,
  });

  /// `POST /scans/{id}/confirm-replace` for a `pending_confirm` scan.
  Future<void> confirmReplace(int scanId);
}

class ApiReviewRepository implements ReviewRepository {
  ApiReviewRepository(this._dio);

  final Dio _dio;

  @override
  Future<ReviewQueue> queue(int assignmentId) async {
    final items = <ReviewItem>[];
    Map<String, dynamic>? meta;
    String? cursor;
    for (var page = 0; page < 50; page++) {
      final res = await _dio.get<Object?>(
        '/assignments/$assignmentId/review-queue',
        queryParameters: {'cursor': ?cursor},
      );
      final body = res.data;
      if (page == 0 && body is Map && body['meta'] is Map) {
        meta = (body['meta'] as Map).cast<String, dynamic>();
      }
      items.addAll(unwrapList(body).map(ReviewItem.fromJson));
      cursor = nextCursorOf(body);
      if (cursor == null) break;
    }
    return ReviewQueue.fromParts(items, meta);
  }

  @override
  Future<ResponseDetail> response(int id) async {
    final res = await _dio.get<Object?>('/responses/$id');
    return ResponseDetail.fromJson(unwrapJson(res.data));
  }

  @override
  Future<ResponseDetail> saveReview(int id, ReviewDecision decision) async {
    final res = await _dio.patch<Object?>(
      '/responses/$id',
      data: decision.toJson(),
    );
    final body = res.data;
    if (body is Map && (body['id'] != null || body['data'] is Map)) {
      return ResponseDetail.fromJson(unwrapJson(body));
    }
    return response(id);
  }

  @override
  Future<String?> regenerateExplanation(int id) async {
    final res = await _dio.post<Object?>(
      '/responses/$id/regenerate-explanation',
    );
    final body = res.data;
    if (body is Map) {
      final json = unwrapJson(body);
      if (json['explanation'] case final String text) return text;
    }
    return null;
  }

  @override
  Future<int> approveConfident(int assignmentId) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/approve-confident',
    );
    final body = res.data;
    return body is Map
        ? countFrom(unwrapJson(body), const ['approved', 'approved_count'])
        : 0;
  }

  @override
  Future<void> publishSubmission(int submissionId) async {
    await _dio.post<Object?>('/submissions/$submissionId/publish');
  }

  @override
  Future<PublishResult> publishAssignment(int assignmentId) async {
    final res = await _dio.post<Object?>('/assignments/$assignmentId/publish');
    final body = res.data;
    return body is Map
        ? PublishResult.fromJson(unwrapJson(body))
        : const PublishResult(published: 0);
  }

  @override
  Future<int> requeueMissingKey(int assignmentId) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/requeue-missing-key',
    );
    final body = res.data;
    return body is Map
        ? countFrom(unwrapJson(body), const ['requeued', 'requeued_count'])
        : 0;
  }

  @override
  Future<List<Appeal>> appeals({String status = 'open'}) async {
    final rows = await fetchAllPages(
      _dio,
      '/appeals',
      query: {'status': status},
    );
    return rows.map(Appeal.fromJson).toList();
  }

  @override
  Future<Appeal> resolveAppeal(
    int id, {
    required String status,
    String? teacherNote,
    double? finalScore,
  }) async {
    final res = await _dio.patch<Object?>(
      '/appeals/$id',
      data: {
        'status': status,
        'teacher_note': ?teacherNote,
        'final_score': ?finalScore,
      },
    );
    return Appeal.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> confirmReplace(int scanId) async {
    await _dio.post<Object?>('/scans/$scanId/confirm-replace');
  }
}

final reviewRepositoryProvider = Provider<ReviewRepository>(
  (ref) => ApiReviewRepository(ref.watch(dioProvider)),
);
