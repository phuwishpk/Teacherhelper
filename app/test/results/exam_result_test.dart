import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/features/results/result_detail_screen.dart';
import 'package:eduvision/features/results/results_repository.dart';
import 'package:eduvision/features/results/student_result.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';

/// `GET /student/results/{id}` of a published exam (ExamResult::forStudent):
/// score per section, and per-question items only when the key is shown.
Map<String, dynamic> _examDetail({bool showKey = true}) => {
  'id': 80,
  'submission_id': 80,
  'kind': 'exam',
  'title': 'สอบกลางภาค',
  'subject_name': 'คณิตศาสตร์',
  'total_score': 3,
  'max_score': 5,
  'published_at': '2026-10-16T03:00:00Z',
  'responses': <Object>[],
  'version_label': 'ข',
  'total': 3,
  'max': 5,
  'sections': [
    {'title': 'ปรนัย', 'score': 1, 'max': 2},
    {'title': 'เติมตัวเลข', 'score': 2, 'max': 3},
  ],
  'items': showKey
      ? [
          {
            'response_id': 902,
            'number': 2,
            'section_title': 'ปรนัย',
            'type': 'mcq',
            'prompt_text': '3 × 4 เท่ากับเท่าใด',
            'max_points': 1,
            'score': 0,
            'marked': ['ก', 'ค'],
            'marked_value': null,
            'correct': ['ข'],
            'correct_values': null,
            'appeal': null,
            'can_appeal': true,
          },
          {
            'response_id': 901,
            'number': 1,
            'section_title': 'ปรนัย',
            'type': 'mcq',
            'prompt_text': '2 + 2 เท่ากับเท่าใด',
            'max_points': 1,
            'score': 1,
            'marked': ['ค'],
            'marked_value': null,
            'correct': ['ค'],
            'correct_values': null,
            'appeal': {
              'id': 3,
              'status': 'rejected',
              'teacher_note': 'ถูกแล้ว',
            },
            'can_appeal': false,
          },
          {
            'response_id': 903,
            'number': 3,
            'section_title': 'เติมตัวเลข',
            'type': 'numeric',
            'prompt_text': 'ครึ่งหนึ่งของ 1',
            'max_points': 3,
            'score': 2,
            'marked': null,
            'marked_value': null,
            'correct': null,
            'correct_values': ['0.5', '.5'],
            'appeal': null,
            'can_appeal': true,
          },
        ]
      : null,
};

class _FakeResults extends Fake implements ResultsRepository {
  _FakeResults({required this.showKey});

  final bool showKey;
  final appeals = <(int, String?)>[];

  @override
  Future<StudentResultDetail> detail(int submissionId) async =>
      StudentResultDetail.fromJson(_examDetail(showKey: showKey));

  @override
  Future<Appeal> appeal(int responseId, {String? reason}) async {
    appeals.add((responseId, reason));
    return Appeal(id: 9, status: 'open', responseId: responseId);
  }
}

Future<_FakeResults> _pump(WidgetTester tester, {required bool showKey}) async {
  tester.view.physicalSize = const Size(800, 3000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final results = _FakeResults(showKey: showKey);
  await pumpScreen(
    tester,
    const ResultDetailScreen(submissionId: 80),
    overrides: [
      resultsRepositoryProvider.overrideWithValue(results),
      cropLoaderProvider.overrideWithValue(NoCropLoader()),
    ],
  );
  return results;
}

void main() {
  group('a published exam as the student sees it (DESIGN §22.12)', () {
    testWidgets('without the key: total, version and sections only', (
      tester,
    ) async {
      await _pump(tester, showKey: false);

      expect(find.text('สอบกลางภาค'), findsWidgets);
      expect(find.text('3/5'), findsOneWidget);
      expect(find.text('ชุด ข'), findsOneWidget);
      expect(find.text('ปรนัย'), findsOneWidget);
      expect(find.text('1/2'), findsOneWidget);
      expect(find.text('เติมตัวเลข'), findsOneWidget);
      expect(find.text('2/3'), findsOneWidget);
      expect(find.byKey(const ValueKey('exam_key_hidden')), findsOneWidget);
      expect(find.textContaining('คำตอบที่ถูก'), findsNothing);
      expect(find.text('ขอให้ครูตรวจใหม่'), findsNothing);
      expect(find.text('ไม่มีภาพของข้อนี้'), findsNothing, reason: 'no crops');
    });

    testWidgets('with the key: each question in the student\'s own version '
        'and one appeal per question', (tester) async {
      final results = await _pump(tester, showKey: true);

      expect(find.byKey(const ValueKey('exam_key_hidden')), findsNothing);
      final q1 = tester.getTopLeft(find.text('ข้อ 1')).dy;
      final q2 = tester.getTopLeft(find.text('ข้อ 2')).dy;
      expect(q1, lessThan(q2), reason: 'in sheet order');
      expect(find.text('คำตอบของเรา: ก, ค'), findsOneWidget);
      expect(find.text('คำตอบที่ถูก: ข'), findsOneWidget);
      expect(find.text('คำตอบของเรา: ไม่ได้ตอบ'), findsOneWidget);
      expect(find.text('คำตอบที่ถูก: 0.5 หรือ .5'), findsOneWidget);
      expect(find.text('0/1 คะแนน'), findsOneWidget);
      expect(find.text('คำขอให้ครูตรวจใหม่: ครูยืนยันผลเดิม'), findsOneWidget);
      expect(find.text('ขอให้ครูตรวจใหม่'), findsNWidgets(2));

      await tester.tap(find.text('ขอให้ครูตรวจใหม่').first);
      await tester.pumpAndSettle();
      expect(find.text('ขอให้ครูตรวจข้อ 2 ใหม่?'), findsOneWidget);
      await tester.tap(find.text('ส่งคำขอ'));
      await tester.pumpAndSettle();
      expect(results.appeals, [(902, null)]);
      expect(find.text('ส่งคำขอแล้ว ครูจะตรวจข้อนี้อีกครั้ง'), findsOneWidget);
    });
  });

  test('parses kind, totals, sections and items', () {
    final d = StudentResultDetail.fromJson(_examDetail());
    expect(d.summary.isExam, isTrue);
    expect(d.summary.totalScore, 3);
    expect(d.summary.maxScore, 5);
    expect(d.answers, isEmpty);
    final exam = d.exam!;
    expect(exam.versionLabel, 'ข');
    expect(exam.sections.map((s) => s.title), ['ปรนัย', 'เติมตัวเลข']);
    expect(exam.items!.map((i) => i.number), [1, 2, 3]);
    expect(exam.items!.first.canAppeal, isFalse);
    expect(exam.items![1].markedText, 'ก, ค');
    expect(exam.items![2].isNumeric, isTrue);

    final hidden = StudentResultDetail.fromJson(_examDetail(showKey: false));
    expect(hidden.exam!.items, isNull);
    final single = StudentResultDetail.fromJson({
      ..._examDetail(showKey: false),
      'version_label': null,
    });
    expect(single.exam!.versionLabel, isNull);

    final homework = StudentResult.fromJson({'id': 1, 'title': 'บวกเลข'});
    expect(homework.kind, 'homework');
    expect(homework.isExam, isFalse);
  });

  test('repository reads the exam detail', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {'data': _examDetail()}),
    );
    final d = await ApiResultsRepository(fakeDio(adapter)).detail(80);
    expect(adapter.requests.single.uri.path, '/api/v1/student/results/80');
    expect(d.exam!.items, hasLength(3));
  });
}
