import 'package:eduvision/features/analysis/analysis_repository.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/class_regrade.dart';
import 'package:eduvision/features/assignments/indicator_mapping.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../analysis/analysis_fakes.dart';
import '../helpers/fake_http_adapter.dart';
import 'answer_key_fixtures.dart';

const _estimate = {
  'pages': 2,
  'cached': true,
  'estimate': {'input_tokens': 10, 'output_tokens': 5, 'thb': null},
};

/// Answers every AI endpoint the way the server does (DESIGN §21.12,
/// §21.13) and echoes the guidance it was sent.
FakeHttpAdapter _server() => FakeHttpAdapter((options) async {
  final body = options.data is Map ? options.data as Map : const {};
  final guidance = body['guidance'];
  final path = options.uri.path.replaceFirst('/api/v1', '');
  final data = switch (path) {
    '/assignments/12/answer-key/estimate' ||
    '/courses/extract/estimate' => _estimate,
    '/assignments/12/answer-key/extract' ||
    '/assignments/12/answer-key/draft' => {
      'cached': false,
      'answer_key': answerKeyJson(
        extraction: {
          'id': 4,
          'status': 'queued',
          'kind': path.endsWith('draft')
              ? 'answer_key_draft'
              : 'answer_key_read',
          'guidance': guidance,
        },
      ),
    },
    '/courses/extract' => {
      'cached': false,
      'estimate': _estimate['estimate'],
      'extraction': {'id': 8, 'status': 'queued', 'guidance': guidance},
      'result': null,
      'indicator_matches': [],
    },
    '/document-extractions/8' => {
      'id': 8,
      'purpose': 'course',
      'status': 'failed',
      'error': 'อ่านไม่ได้',
      'guidance': 'แผนอยู่หน้า 3',
    },
    '/assignments/12/indicator-suggestions' => {
      'status': 'queued',
      'requested_at': '2026-09-30T01:00:00+00:00',
      'guidance': guidance,
    },
    '/responses/70/regenerate-explanation' => {
      'id': 70,
      'explanation': 'คำอธิบายใหม่',
    },
    '/students/55/analysis/run' =>
      teacherAnalysisJson()..['guidance'] = guidance,
    '/assignments/12/regrade/estimate' => regradeEstimateJson(
      submissions: 2,
      queued: 3,
      mcq: 1,
      overridden: body['include_overridden'] == true ? 0 : 1,
    ),
    '/assignments/12/regrade' => {
      'queued_submissions': 2,
      'skipped_overridden': 1,
      'queued_responses': 3,
      'rescored_by_code': 1,
      'skipped_in_progress': 0,
      'skipped_missing_image': 0,
      'reopened_submissions': 1,
    },
    _ => throw StateError('unexpected $path'),
  };
  return jsonResponse(200, {'data': data});
});

Map<dynamic, dynamic>? _body(FakeHttpAdapter a) => a.requests.last.data as Map?;

void main() {
  test('answer key: guidance goes with the estimate, read and draft', () async {
    final adapter = _server();
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));

    final e = await repo.estimate(
      12,
      kind: KeyRequestKind.read,
      documentIds: const [31],
      guidance: '  เฉลยอยู่หน้าสุดท้าย ',
    );
    expect(e.cached, isTrue);
    expect(_body(adapter), {
      'kind': 'read',
      'document_ids': [31],
      'guidance': 'เฉลยอยู่หน้าสุดท้าย',
    });

    final read = await repo.request(
      12,
      kind: KeyRequestKind.read,
      documentIds: const [31],
      guidance: 'เฉลยอยู่หน้าสุดท้าย',
    );
    expect(read.answerKey.extraction?.guidance, 'เฉลยอยู่หน้าสุดท้าย');
    expect(read.answerKey.extraction?.isRead, isTrue);

    final draft = await repo.request(
      12,
      kind: KeyRequestKind.draft,
      guidance: '   ',
    );
    expect(_body(adapter), isNot(contains('guidance')));
    expect(draft.answerKey.extraction?.guidance, isNull);
    expect(draft.answerKey.extraction?.isRead, isFalse);
  });

  test('courses: guidance with estimate and extract, echoed back', () async {
    final adapter = _server();
    final repo = ApiCoursesRepository(fakeDio(adapter));

    await repo.estimate(
      purpose: CourseDocumentPurpose.course,
      documentIds: const [5],
      guidance: 'แผนอยู่หน้า 3',
    );
    expect(_body(adapter)!['guidance'], 'แผนอยู่หน้า 3');

    final x = await repo.extract(
      purpose: CourseDocumentPurpose.course,
      documentIds: const [5],
      guidance: 'แผนอยู่หน้า 3',
    );
    expect(x.guidance, 'แผนอยู่หน้า 3');

    await repo.extract(
      purpose: CourseDocumentPurpose.course,
      documentIds: const [5],
    );
    expect(_body(adapter), isNot(contains('guidance')));

    final polled = await repo.extraction(8);
    expect(polled.failed, isTrue);
    expect(polled.guidance, 'แผนอยู่หน้า 3');
  });

  test('indicator suggestions: guidance only when given', () async {
    final adapter = _server();
    final repo = ApiIndicatorMappingRepository(fakeDio(adapter));

    final state = await repo.requestSuggestions(12, guidance: 'ข้อ 1 บวก');
    expect(_body(adapter), {'guidance': 'ข้อ 1 บวก'});
    expect(state.guidance, 'ข้อ 1 บวก');

    final none = await repo.requestSuggestions(12);
    expect(adapter.requests.last.data, isNull);
    expect(none.guidance, isNull);
  });

  test('regenerate explanation and analyze now carry guidance', () async {
    final adapter = _server();
    final review = ApiReviewRepository(fakeDio(adapter));
    expect(
      await review.regenerateExplanation(70, guidance: 'นับทีละสิบ'),
      'คำอธิบายใหม่',
    );
    expect(_body(adapter), {'guidance': 'นับทีละสิบ'});
    await review.regenerateExplanation(70);
    expect(adapter.requests.last.data, isNull);

    final analysis = ApiAnalysisRepository(fakeDio(adapter));
    final a = await analysis.runNow(55, 7, guidance: 'เน้นเศษส่วน');
    expect(_body(adapter), {'classroom_id': 7, 'guidance': 'เน้นเศษส่วน'});
    expect(a.guidance, 'เน้นเศษส่วน');
    final b = await analysis.runNow(55, 7);
    expect(_body(adapter), {'classroom_id': 7});
    expect(b.guidance, isNull);
  });

  test('class regrade: estimate and run with include_overridden', () async {
    final adapter = _server();
    final repo = ApiClassRegradeRepository(fakeDio(adapter));

    final e = await repo.estimate(12);
    expect(
      adapter.requests.last.uri.path,
      '/api/v1/assignments/12/regrade/estimate',
    );
    expect(_body(adapter), {'include_overridden': false});
    expect(e.submissions, 2);
    expect(e.queuedResponses, 3);
    expect(e.mcqByCode, 1);
    expect(e.skippedOverridden, 1);
    expect(e.publishedSubmissions, 0);
    expect(e.inProgress, isFalse);
    expect(e.estimate.inputTokens, 12000);
    expect(e.hasGraded, isTrue);
    expect(e.usesAi, isTrue);
    expect(e.nothingToDo, isFalse);

    final all = await repo.estimate(12, includeOverridden: true);
    expect(_body(adapter), {'include_overridden': true});
    expect(all.skippedOverridden, 0);

    final outcome = await repo.regrade(12, includeOverridden: true);
    expect(adapter.requests.last.uri.path, '/api/v1/assignments/12/regrade');
    expect(_body(adapter), {'include_overridden': true});
    expect(outcome.queuedSubmissions, 2);
    expect(outcome.reopenedSubmissions, 1);
    expect(
      outcome.summary,
      'เริ่มตรวจใหม่ 2 งาน · AI กำลังอ่านใหม่ 3 ข้อ · คิดคะแนนปรนัยใหม่ 1 ข้อ · '
      'เปิดงานที่เผยแพร่แล้วกลับมาตรวจทาน 1 งาน · ข้ามข้อที่ครูแก้คะแนนเอง 1 ข้อ',
    );
  });

  test('regrade summaries when nothing or only mcq changed', () {
    expect(const RegradeOutcome().summary, 'ตรวจใหม่แล้ว ไม่มีคะแนนที่เปลี่ยน');
    expect(
      const RegradeOutcome(skippedOverridden: 2).summary,
      'ตรวจใหม่แล้ว ข้ามข้อที่ครูแก้คะแนนเอง 2 ข้อ',
    );
    expect(
      const RegradeOutcome(rescoredByCode: 3).summary,
      'ตรวจใหม่แล้ว คิดคะแนนปรนัยใหม่ 3 ข้อ',
    );
    final empty = RegradeEstimate.fromJson(const {});
    expect(empty.hasGraded, isFalse);
    expect(empty.nothingToDo, isTrue);
    expect(empty.usesAi, isFalse);
    expect(
      RegradeEstimate.fromJson(regradeEstimateJson(overridden: 1)).hasGraded,
      isTrue,
    );
  });
}
