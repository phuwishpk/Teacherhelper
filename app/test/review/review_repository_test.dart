import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/core/push/devices_repository.dart';
import 'package:eduvision/features/review/review_labels.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'review_fixtures.dart';

Object? _body(RequestOptions r) =>
    r.data is String ? jsonDecode(r.data as String) : r.data;

/// Request/response shapes of the review endpoints (DESIGN §9.5).
void main() {
  test('queue follows the cursor and keeps the first page meta', () async {
    final adapter = FakeHttpAdapter((options) async {
      if (options.uri.queryParameters['cursor'] == 'p2') {
        return jsonResponse(200, {
          'data': [queueRow(id: 13, band: 'confident', position: 3)],
          'meta': {'next_cursor': null},
        });
      }
      return jsonResponse(200, {
        'data': [
          queueRow(id: 11, band: 'look'),
          queueRow(
            id: 12,
            state: 'manual',
            manualReason: 'ai_key_missing',
            position: 2,
          ),
        ],
        'meta': {
          'next_cursor': 'p2',
          'missing_ai_key_count': 1,
          'pending_confirm_scans': [
            {
              'scan_id': 90,
              'submission_id': 71,
              'page_no': 1,
              'scanned_at': '2026-10-01T02:15:00Z',
              'student': {'id': 4013, 'name': 'ด.ช. ก', 'student_number': 13},
            },
          ],
        },
      });
    });
    final repo = ApiReviewRepository(fakeDio(adapter));

    final q = await repo.queue(5);

    expect(adapter.requests.map((r) => r.uri.path).toSet(), {
      '/api/v1/assignments/5/review-queue',
    });
    expect(adapter.requests.last.uri.queryParameters['cursor'], 'p2');
    expect(q.items.map((i) => i.id), [11, 12, 13]);
    expect(q.missingAiKeyCount, 1);
    expect(q.pendingScans.single.scanId, 90);
    expect(q.pendingScans.single.student?.label, 'เลขที่ 13 ด.ช. ก');
    // Manual rows land on the "ต้องตรวจ" tab, above everything else.
    expect(q.tab(PriorityBand.check).map((i) => i.id), [12]);
    expect(q.tab(PriorityBand.look).map((i) => i.id), [11]);
    expect(q.bulkApprovable.map((i) => i.id), [13]);
    // No meta.submissions: derived from rows (one submission, 0/3 reviewed).
    expect(q.submissions.single.responseCount, 3);
    expect(q.submissions.single.canPublish, isFalse);
  });

  test('saveReview PATCHes the §9.5 body and omits an unchanged '
      'explanation', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          ...responseJson(),
          'final_score': 1.5,
          'reviewed_at': '2026-10-01T02:00:00Z',
        },
      }),
    );
    final repo = ApiReviewRepository(fakeDio(adapter));

    final saved = await repo.saveReview(
      11,
      const ReviewDecision(
        finalScore: 1.5,
        understanding: Understanding.partial,
        errorTypes: [ErrorType.calculation],
        reason: 'AI อ่านลายมือผิด',
      ),
    );

    final req = adapter.requests.single;
    expect(req.method, 'PATCH');
    expect(req.uri.path, '/api/v1/responses/11');
    expect(_body(req), {
      'final_score': 1.5,
      'final_understanding': 'partial',
      'final_error_types': ['calculation'],
      'reason': 'AI อ่านลายมือผิด',
    });
    expect(saved.finalScore, 1.5);
    expect(saved.isReviewed, isTrue);
  });

  test('bulk actions, publishing and requeue hit their endpoints', () async {
    final adapter = FakeHttpAdapter((options) async {
      return switch (options.uri.path) {
        '/api/v1/assignments/5/approve-confident' => jsonResponse(200, {
          'approved': 7,
        }),
        '/api/v1/assignments/5/publish' => jsonResponse(200, {
          'published': 20,
          'skipped': 2,
        }),
        '/api/v1/assignments/5/requeue-missing-key' => jsonResponse(202, {
          'requeued': 4,
        }),
        _ => jsonResponse(200, {'ok': true}),
      };
    });
    final repo = ApiReviewRepository(fakeDio(adapter));

    expect(await repo.approveConfident(5), 7);
    final published = await repo.publishAssignment(5);
    expect((published.published, published.skipped), (20, 2));
    expect(await repo.requeueMissingKey(5), 4);
    await repo.publishSubmission(70);
    await repo.confirmReplace(90);

    expect(adapter.requests.map((r) => '${r.method} ${r.uri.path}').toList(), [
      'POST /api/v1/assignments/5/approve-confident',
      'POST /api/v1/assignments/5/publish',
      'POST /api/v1/assignments/5/requeue-missing-key',
      'POST /api/v1/submissions/70/publish',
      'POST /api/v1/scans/90/confirm-replace',
    ]);
  });

  test(
    'appeals list uses status=open and resolve sends the decision',
    () async {
      final adapter = FakeHttpAdapter((options) async {
        if (options.method == 'GET') {
          return jsonResponse(200, {
            'data': [
              {
                'id': 3,
                'response_id': 11,
                'status': 'open',
                'reason': 'ครูดูบรรทัด 2 อีกที',
                'student': {'id': 4012, 'name': 'สมหญิง', 'student_number': 12},
                'assignment': {'id': 5, 'title': 'เศษส่วน'},
                'question': {'position': 1, 'max_points': 2},
                'final_score': 1,
              },
            ],
            'meta': {'next_cursor': null},
          });
        }
        return jsonResponse(200, {
          'data': {'id': 3, 'response_id': 11, 'status': 'accepted'},
        });
      });
      final repo = ApiReviewRepository(fakeDio(adapter));

      final list = await repo.appeals();
      expect(adapter.requests.first.uri.queryParameters, {'status': 'open'});
      expect(list.single.assignmentTitle, 'เศษส่วน');
      expect(list.single.currentScore, 1);
      expect(list.single.maxPoints, 2);

      final resolved = await repo.resolveAppeal(
        3,
        status: 'accepted',
        teacherNote: 'ให้เพิ่มครึ่งคะแนน',
        finalScore: 1.5,
      );
      final patch = adapter.requests.last;
      expect(patch.method, 'PATCH');
      expect(patch.uri.path, '/api/v1/appeals/3');
      expect(_body(patch), {
        'status': 'accepted',
        'teacher_note': 'ให้เพิ่มครึ่งคะแนน',
        'final_score': 1.5,
      });
      expect(resolved.status, 'accepted');
    },
  );

  test('response detail parses extraction, trace and flags', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          ...responseJson(),
          'flags': ['suspicious', 'identity_mismatch'],
        },
      }),
    );
    final d = await ApiReviewRepository(fakeDio(adapter)).response(11);
    expect(adapter.requests.single.uri.path, '/api/v1/responses/11');
    expect(d.question.keySummary, 'คำตอบที่ยอมรับ: 125');
    expect(d.isSuspicious, isTrue);
    expect(d.identityMismatch, isTrue);
    expect(d.startScore, 2);
    expect(d.startUnderstanding, Understanding.good);
    expect(d.cnnText, '126');
  });

  test('crops are fetched as bytes from /responses/{id}/crop', () async {
    final adapter = FakeHttpAdapter(
      (_) async => ResponseBody.fromBytes(
        [1, 2, 3],
        200,
        headers: {
          Headers.contentTypeHeader: ['image/webp'],
        },
      ),
    );
    final loader = ApiCropLoader(fakeDio(adapter));

    expect(await loader.crop(11), [1, 2, 3]);
    await loader.crop(11, finalPart: true);

    expect(adapter.requests.first.uri.path, '/api/v1/responses/11/crop');
    expect(adapter.requests.first.uri.queryParameters, isEmpty);
    expect(adapter.requests.last.uri.queryParameters, {'part': 'final'});
  });

  test('devices register POSTs {fcm_token}', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {'id': 1, 'fcm_token': 'tok'}),
    );
    await ApiDevicesRepository(fakeDio(adapter)).register('tok');
    final req = adapter.requests.single;
    expect('${req.method} ${req.uri.path}', 'POST /api/v1/devices');
    expect(_body(req), {'fcm_token': 'tok'});
  });
}
