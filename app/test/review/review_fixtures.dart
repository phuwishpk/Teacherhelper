import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter_test/flutter_test.dart';

/// Queue rows in the shape of GET /assignments/{id}/review-queue.
Map<String, dynamic> queueRow({
  required int id,
  int submissionId = 70,
  int position = 1,
  String band = 'check',
  String state = 'scored',
  double aiScore = 2,
  double maxPoints = 2,
  String? manualReason,
  bool suspicious = false,
  bool identityMismatch = false,
  String? reviewedAt,
  int studentNumber = 12,
  String studentName = 'ด.ญ. สมหญิง',
}) => {
  'id': id,
  'submission_id': submissionId,
  'question_id': 500 + position,
  'question_position': position,
  'question_type': 'short',
  'max_points': maxPoints,
  'student': {
    'id': 4000 + studentNumber,
    'name': studentName,
    'student_number': studentNumber,
  },
  'grading_state': state,
  'manual_reason': manualReason,
  'priority_band': state == 'manual' ? null : band,
  'review_priority': band == 'check' ? 0.7 : (band == 'look' ? 0.3 : 0.05),
  'suspicious': suspicious,
  'identity_mismatch': identityMismatch,
  'ai_score': state == 'manual' ? null : aiScore,
  'final_score': null,
  'ai_understanding': state == 'manual' ? null : 'good',
  'reviewed_at': reviewedAt,
  'submission_status': 'needs_review',
};

/// GET /responses/{id} of a short numeric answer the AI scored full marks.
Map<String, dynamic> responseJson({
  int id = 11,
  double? aiScore = 2,
  String state = 'scored',
  String? manualReason,
  String? reviewedAt,
}) => {
  'id': id,
  'submission_id': 70,
  'student': {'id': 4012, 'name': 'ด.ญ. สมหญิง', 'student_number': 12},
  'question': {
    'id': 501,
    'position': 1,
    'type': 'short',
    'prompt_text': '100 + 25 = ?',
    'max_points': 2,
    'answer_key': {
      'accepted': ['125'],
    },
  },
  'grading_state': state,
  'manual_reason': manualReason,
  'priority_band': state == 'manual' ? null : 'check',
  'review_priority': 0.62,
  'extraction': state == 'manual'
      ? null
      : {
          'blank': false,
          'suspicious_instruction': false,
          'legibility': 'readable',
          'answer_text': '125',
          'key_match': 'exact',
          'error_types': <String>[],
          'summary_th': 'ตอบถูก',
        },
  'fuzzy_trace': state == 'manual'
      ? null
      : {
          'scoring': {
            'inputs': {'M': 1.0},
            'rules': [
              {
                'name': 'S1',
                'weight': 0.0,
                'then': {'score_ratio': 0.0, 'u': 0.0},
              },
              {
                'name': 'S3',
                'weight': 1.0,
                'then': {'score_ratio': 1.0, 'u': 1.0},
              },
            ],
            'outputs': {'score_ratio': 1.0, 'u': 1.0},
          },
          'priority': {
            'system': 'review_priority',
            'inputs': {'D': 1.0, 'L': 0.4, 'B': 0.0},
            'rules': [
              {'rule': 'P1', 'w': 1.0, 'z': 1.0},
              {'rule': 'P2', 'w': 0.4, 'z': 0.8},
            ],
            'p': 0.62,
          },
        },
  'ai_score': aiScore,
  'ai_understanding': aiScore == null ? null : 'good',
  'ai_error_types': <String>[],
  'final_score': null,
  'final_understanding': null,
  'final_error_types': null,
  'explanation': aiScore == null ? null : 'ทำได้ดีมาก',
  'explanation_edited': false,
  'reviewed_at': reviewedAt,
  'cnn_text': '126',
  'cnn_confidence': 0.91,
  'ink_ratio': 0.12,
  'has_crop': true,
  'has_final_crop': false,
};

DioException apiError(
  int status,
  Map<String, dynamic> body, {
  String path = '/x',
}) {
  final options = RequestOptions(path: path);
  return DioException(
    requestOptions: options,
    response: Response(requestOptions: options, statusCode: status, data: body),
    type: DioExceptionType.badResponse,
  );
}

class FakeReviewRepository extends Fake implements ReviewRepository {
  FakeReviewRepository({
    this.rows = const [],
    this.meta,
    Map<int, Map<String, dynamic>>? responses,
  }) : responses = responses ?? {};

  List<Map<String, dynamic>> rows;
  Map<String, dynamic>? meta;
  final Map<int, Map<String, dynamic>> responses;

  final saved = <(int, Map<String, dynamic>)>[];
  final requeued = <int>[];
  final approved = <int>[];
  final published = <int>[];
  final publishedSubmissions = <int>[];
  final confirmed = <int>[];
  int queueCalls = 0;

  @override
  Future<ReviewQueue> queue(int assignmentId) async {
    queueCalls++;
    return ReviewQueue.fromParts(rows.map(ReviewItem.fromJson).toList(), meta);
  }

  @override
  Future<ResponseDetail> response(int id) async =>
      ResponseDetail.fromJson(responses[id] ?? responseJson(id: id));

  @override
  Future<ResponseDetail> saveReview(int id, ReviewDecision decision) async {
    saved.add((id, decision.toJson()));
    final json = {
      ...responses[id] ?? responseJson(id: id),
      'final_score': decision.finalScore,
      'final_understanding': decision.understanding.apiValue,
      'reviewed_at': '2026-10-01T02:00:00Z',
    };
    responses[id] = json;
    return ResponseDetail.fromJson(json);
  }

  @override
  Future<int> requeueMissingKey(int assignmentId) async {
    requeued.add(assignmentId);
    meta = {...?meta, 'missing_ai_key_count': 0};
    return 3;
  }

  @override
  Future<int> approveConfident(int assignmentId) async {
    approved.add(assignmentId);
    return rows.where((r) => r['priority_band'] == 'confident').length;
  }

  @override
  Future<PublishResult> publishAssignment(int assignmentId) async {
    published.add(assignmentId);
    return const PublishResult(published: 1);
  }

  @override
  Future<void> publishSubmission(int submissionId) async {
    publishedSubmissions.add(submissionId);
  }

  @override
  Future<void> confirmReplace(int scanId) async {
    confirmed.add(scanId);
  }

  final graded = <int>[];

  /// When set, gradeSubmission() throws it.
  Object? gradeError;

  @override
  Future<void> gradeSubmission(int submissionId) async {
    graded.add(submissionId);
    if (gradeError case final e?) throw e;
    if (meta?['submissions'] is! List) return;
    meta = {
      ...?meta,
      'submissions': [
        for (final s in (meta?['submissions'] as List?) ?? const [])
          if (s is Map && s['id'] == submissionId)
            {...s, 'regrade_pending': false, 'status': 'grading'}
          else
            s,
      ],
    };
  }

  List<Map<String, dynamic>> appealRows = [];
  final resolved = <(int, String, String?, double?)>[];

  @override
  Future<List<Appeal>> appeals({String status = 'open'}) async =>
      appealRows.map(Appeal.fromJson).toList();

  @override
  Future<Appeal> resolveAppeal(
    int id, {
    required String status,
    String? teacherNote,
    double? finalScore,
  }) async {
    resolved.add((id, status, teacherNote, finalScore));
    appealRows = [
      for (final a in appealRows)
        if (a['id'] != id) a,
    ];
    return Appeal(id: id, status: status);
  }
}

/// Crops always 404 in widget tests, so no image has to be decoded.
class NoCropLoader implements CropLoader {
  @override
  Future<Uint8List> crop(int responseId, {bool finalPart = false}) async =>
      throw apiError(404, {'message': 'ไม่มีภาพ', 'code': 'not_found'});
}

class FakeAiKeyRepository extends Fake implements AiKeyRepository {
  FakeAiKeyRepository(this.current);

  AiKeyStatus current;
  final saved = <String>[];
  int deletes = 0;

  /// When set, save() throws it instead of succeeding.
  Object? saveError;

  @override
  Future<AiKeyStatus> status() async => current;

  @override
  Future<AiKeyStatus> save(String geminiApiKey) async {
    saved.add(geminiApiKey);
    if (saveError case final e?) throw e;
    current = AiKeyStatus(
      configured: true,
      keyLast4: geminiApiKey.substring(geminiApiKey.length - 4),
      lastVerifiedAt: DateTime.utc(2026, 10, 1, 2),
      serverKeyAvailable: current.serverKeyAvailable,
    );
    return current;
  }

  @override
  Future<AiKeyStatus> delete() async {
    deletes++;
    current = AiKeyStatus(
      configured: false,
      serverKeyAvailable: current.serverKeyAvailable,
    );
    return current;
  }
}
