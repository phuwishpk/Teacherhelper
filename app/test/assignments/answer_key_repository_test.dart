import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'answer_key_fixtures.dart';

/// Request/response shapes of the answer-key endpoints (DESIGN §19.9).
void main() {
  test('upload sends every file as files[] with its type', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'data': [
          {
            'id': 31,
            'sha256': 'abc',
            'original_name': 'key.pdf',
            'mime_type': 'application/pdf',
            'size_bytes': 2048,
            'page_count': 42,
            'needs_page_range': true,
            'cached_purposes': ['answer_key'],
            'estimate': {
              'input_tokens': 25020,
              'output_tokens': 16384,
              'thb': 1.23,
            },
          },
        ],
      }),
    );
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));

    final docs = await repo.upload([
      PickedDocument(name: 'key.pdf', bytes: Uint8List.fromList([37, 80])),
      PickedDocument(name: 'page.JPG', bytes: Uint8List.fromList([1, 2])),
    ]);

    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/documents');
    final form = req.data as FormData;
    expect(form.files.map((e) => e.key), ['files[]', 'files[]']);
    expect(form.files.map((e) => e.value.filename), ['key.pdf', 'page.JPG']);
    expect(form.files.map((e) => e.value.contentType?.mimeType), [
      'application/pdf',
      'image/jpeg',
    ]);

    final doc = docs.single;
    expect(doc.id, 31);
    expect(doc.isPdf, isTrue);
    expect(doc.pageCount, 42);
    expect(doc.needsPageRange, isTrue);
    expect(doc.keyReadBefore, isTrue);
    expect(doc.estimate?.thb, 1.23);
  });

  test('answerKey reads the state, extraction and questions', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': answerKeyJson(
          keyOrigin: 'ai_draft',
          extraction: {
            'id': 9,
            'purpose': 'answer_key',
            'status': 'done',
            'error': null,
            'kind': 'answer_key_draft',
            'notes_th': 'ข้อ 2 อ่านไม่ชัด',
          },
          incomplete: [2],
          questions: [
            questionJson(1, 'mcq', answerKey: {'correct': 'B'}),
            questionJson(2, 'short', complete: false),
            questionJson(
              3,
              'open',
              modelAnswer: 'เพราะมีคลอโรฟิลล์',
              rubric: 'draft',
              complete: false,
            ),
          ],
        ),
      }),
    );
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));

    final key = await repo.answerKey(12);

    expect(
      adapter.requests.single.uri.path,
      '/api/v1/assignments/12/answer-key',
    );
    expect(key.mode, AssignmentMode.freeform);
    expect(key.keyOrigin, KeyOrigin.aiDraft);
    expect(key.approved, isFalse);
    expect(key.extraction?.isDraft, isTrue);
    expect(key.extraction?.notesTh, 'ข้อ 2 อ่านไม่ชัด');
    expect(key.incompleteQuestions, [2]);
    expect(key.questions.map((q) => q.keyComplete), [true, false, false]);
    expect(key.questions[1].missingAnswer, isTrue);
    expect(key.questions[2].modelAnswer, 'เพราะมีคลอโรฟิลล์');
    expect(key.questions[2].missingAnswer, isFalse);
  });

  test('estimate posts the kind, files and range', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'kind': 'answer_key_read',
          'pages': 5,
          'cached': false,
          'estimate': {
            'input_tokens': 4300,
            'output_tokens': 3750,
            'thb': null,
          },
        },
      }),
    );
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));

    final estimate = await repo.estimate(
      12,
      kind: KeyRequestKind.read,
      documentIds: [31],
      pageFrom: 3,
      pageTo: 7,
    );
    await repo.estimate(12, kind: KeyRequestKind.draft);

    final first = adapter.requests.first;
    expect(first.uri.path, '/api/v1/assignments/12/answer-key/estimate');
    expect(first.data, {
      'kind': 'read',
      'document_ids': [31],
      'page_from': 3,
      'page_to': 7,
    });
    expect(adapter.requests.last.data, {'kind': 'draft'});
    expect(estimate.pages, 5);
    expect(estimate.cached, isFalse);
    expect(estimate.estimate.thb, isNull);
    expect(estimate.estimate.label, contains('4,300'));
  });

  test('request posts extract or draft and reads the result', () async {
    final adapter = FakeHttpAdapter(
      (options) async =>
          jsonResponse(options.uri.path.endsWith('extract') ? 200 : 202, {
            'data': {
              'cached': options.uri.path.endsWith('extract'),
              'estimate': options.uri.path.endsWith('extract')
                  ? null
                  : {'input_tokens': 1500, 'output_tokens': 300, 'thb': 0.04},
              'applied': options.uri.path.endsWith('extract')
                  ? {
                      'created': 0,
                      'filled': 2,
                      'skipped': [
                        {'question_no': 5, 'reason': 'no_such_question'},
                      ],
                    }
                  : null,
              'answer_key': answerKeyJson(
                extraction: {
                  'id': 3,
                  'purpose': 'answer_key',
                  'status': options.uri.path.endsWith('extract')
                      ? 'done'
                      : 'queued',
                },
              ),
            },
          }),
    );
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));

    final read = await repo.request(
      12,
      kind: KeyRequestKind.read,
      documentIds: [31, 32],
    );
    final draft = await repo.request(12, kind: KeyRequestKind.draft);

    expect(
      adapter.requests.first.uri.path,
      '/api/v1/assignments/12/answer-key/extract',
    );
    expect(adapter.requests.first.data, {
      'document_ids': [31, 32],
    });
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/assignments/12/answer-key/draft',
    );
    expect(adapter.requests.last.data, isEmpty);

    expect(read.cached, isTrue);
    expect(read.applied?.filled, 2);
    expect(read.applied?.skipped.single.label, 'ข้อ 5 ไม่มีในการบ้านนี้');
    expect(read.answerKey.reading, isFalse);
    expect(draft.cached, isFalse);
    expect(draft.estimate?.thb, 0.04);
    expect(draft.answerKey.reading, isTrue);
  });

  test('approve posts to the approve endpoint', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': answerKeyJson(
          status: 'ready',
          approvedAt: '2026-09-30T03:00:00Z',
          complete: true,
        ),
      }),
    );
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));

    final key = await repo.approve(12);

    expect(adapter.requests.single.method, 'POST');
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/assignments/12/answer-key/approve',
    );
    expect(key.approved, isTrue);
    expect(key.status, 'ready');
  });

  test('create and update carry mode, accept_late and score_only', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'id': 12,
          'classroom_id': 7,
          'subject_id': 1,
          'title': 'เรียงความ',
          'status': 'draft',
          'mode': 'freeform',
          'source': 'app',
          'accept_late': false,
          'score_only': true,
          'key_origin': 'document',
          'key_approved_at': null,
        },
      }),
    );
    final repo = ApiAssignmentsRepository(fakeDio(adapter));

    final created = await repo.create(
      classroomId: 7,
      courseId: 4,
      title: 'เรียงความ',
      mode: AssignmentMode.freeform,
      acceptLate: false,
      scoreOnly: true,
    );
    await repo.update(12, scoreOnly: false);

    final body = adapter.requests.first.data as Map;
    expect(body['course_id'], 4);
    expect(body.containsKey('subject_id'), isFalse);
    expect(body.containsKey('lesson_plan_id'), isFalse);
    expect(body['mode'], 'freeform');
    expect(body['accept_late'], false);
    expect(body['score_only'], true);
    expect(adapter.requests.last.data, {'score_only': false});
    expect(created.isFreeform, isTrue);
    expect(created.acceptLate, isFalse);
    expect(created.scoreOnly, isTrue);
    expect(created.keyOrigin, KeyOrigin.document);
    expect(created.keyApproved, isFalse);
  });

  test('a question draft sends model_answer for open questions only', () {
    const open = QuestionDraft(
      type: QuestionType.open,
      promptText: 'ทำไมใบไม้สีเขียว',
      maxPoints: 4,
      answerLines: 5,
      modelAnswer: 'คลอโรฟิลล์',
    );
    const short = QuestionDraft(
      type: QuestionType.short,
      promptText: '2 + 2',
      maxPoints: 1,
      modelAnswer: 'ignored',
    );
    expect(open.toJson()['model_answer'], 'คลอโรฟิลล์');
    expect(short.toJson().containsKey('model_answer'), isFalse);
    expect(short.toJson()['answer_key'], isNull);
  });

  test('cost labels and file types', () {
    expect(
      const CostEstimate(
        inputTokens: 4300,
        outputTokens: 3750,
        thb: 0.44,
      ).label,
      'ประมาณ 0.44 บาท (ขาเข้า 4,300 token · ขาออกไม่เกิน 3,750 token)',
    );
    expect(
      const CostEstimate(
        inputTokens: 2060,
        outputTokens: 750,
        thb: 0.001,
      ).label,
      startsWith('ประมาณ ไม่ถึง 0.01 บาท'),
    );
    expect(documentMimeType('a.HEIC'), 'image/heic');
    expect(documentMimeType('a.webp'), 'image/webp');
    expect(documentMimeType('a.png'), 'image/png');
    expect(documentMimeType('a.docx'), 'application/octet-stream');
    expect(
      const SkippedQuestion(questionNo: 2, reason: 'type_mismatch').label,
      'ข้อ 2 ประเภทคำถามไม่ตรงกับในการบ้าน',
    );
    expect(
      const SkippedQuestion(questionNo: 3, reason: 'no_answer').label,
      'ข้อ 3 AI หาคำตอบไม่เจอ',
    );
    expect(
      const SkippedQuestion(questionNo: 4, reason: 'other').label,
      'ข้อ 4 ไม่ได้เติมเฉลย',
    );
  });
}
