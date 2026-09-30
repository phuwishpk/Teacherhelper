import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/core/api/scan_pages.dart';
import 'package:eduvision/core/widgets/scan_page_image.dart';
import 'package:eduvision/core/widgets/submission_page_image.dart';
import 'package:eduvision/features/review/exam_answer.dart';
import 'package:eduvision/features/review/review_detail_screen.dart';
import 'package:eduvision/features/review/review_labels.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_queue_screen.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import 'review_fixtures.dart';

/// An exam answer as `GET /responses/{id}` sends it (ExamAnswerView):
/// sheet number 12 of version ข, marked ก and ค (double_mark), 0 points.
Map<String, dynamic> examResponseJson({
  int id = 21,
  String type = 'mcq',
  List<String> doubts = const ['double_mark'],
  Map<String, dynamic>? resolved,
  String? reviewedAt,
  String submissionStatus = 'needs_review',
}) {
  final numeric = type == 'numeric';
  return {
    ...responseJson(id: id, aiScore: 0, reviewedAt: reviewedAt),
    'question': {
      'id': 540,
      'position': 3,
      'type': type,
      'prompt_text': numeric ? 'ครึ่งหนึ่งของ 1' : '3 × 4 เท่ากับเท่าใด',
      'max_points': 1,
      'answer_key': null,
    },
    'submission_status': submissionStatus,
    'extraction': null,
    'fuzzy_trace': {'system': 'exam_sheet', 'sheet_no': 12, 'doubts': doubts},
    'explanation': null,
    'has_crop': false,
    'exam': {
      'sheet_no': 12,
      'version_no': 2,
      'version_label': 'ข',
      'labels': numeric ? <String>[] : ['ก', 'ข', 'ค', 'ง'],
      'selected': numeric ? <int>[] : [1, 3],
      'value': null,
      'doubts': doubts,
      'resolved': resolved,
      'scan_id': 77,
      'page_no': 1,
      'page_image_url': '/api/v1/scans/77/page',
      'rect': {'x': 0.1, 'y': 0.2, 'w': 0.25, 'h': 0.03},
    },
  };
}

Map<String, dynamic> examQueueRow({int id = 21, bool resolved = false}) => {
  ...queueRow(id: id, position: 3, aiScore: 0, maxPoints: 1),
  'question_type': 'mcq',
  'exam_answer': {
    'sheet_no': 12,
    'version_no': 2,
    'doubts': ['double_mark'],
    'resolved': resolved,
  },
};

class _Pages implements ScanPageLoader {
  _Pages({this.error});

  final Object? error;
  final asked = <int>[];

  @override
  Future<Uint8List> page(int scanId) async {
    asked.add(scanId);
    if (error case final e?) throw e;
    return Uint8List.fromList([1, 2, 3]);
  }
}

void _phone(WidgetTester tester) {
  tester.view.physicalSize = const Size(800, 3600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<FakeReviewRepository> _pumpDetail(
  WidgetTester tester,
  Map<String, dynamic> json, {
  ScanPageLoader? pages,
  PageDecoder? decoder,
}) async {
  _phone(tester);
  final repo = FakeReviewRepository(
    rows: [
      examQueueRow(id: json['id'] as int),
      examQueueRow(id: 99),
    ],
    responses: {json['id'] as int: json},
  );
  await pumpScreen(
    tester,
    ReviewDetailScreen(
      assignmentId: 40,
      responseId: json['id'] as int,
      band: PriorityBand.check,
    ),
    overrides: [
      reviewRepositoryProvider.overrideWithValue(repo),
      cropLoaderProvider.overrideWithValue(NoCropLoader()),
      scanPageLoaderProvider.overrideWithValue(
        pages ??
            _Pages(
              error: apiError(410, {
                'message': 'ลบแล้ว',
                'errors': {},
                'code': 'image_purged',
              }),
            ),
      ),
      pageDecoderProvider.overrideWithValue(
        decoder ?? (_) async => throw Exception('cannot decode'),
      ),
    ],
  );
  return repo;
}

void main() {
  group('reviewing an exam answer (DESIGN §22.11)', () {
    testWidgets('shows the sheet, the marks and the doubt; no AI parts', (
      tester,
    ) async {
      await _pumpDetail(tester, examResponseJson());

      expect(find.text('ข้อ 12 ชุด ข · ปรนัย · 1 คะแนน'), findsOneWidget);
      expect(find.text('กระดาษคำตอบ'), findsOneWidget);
      expect(find.text('ชุด ข · หน้า 1 · ข้อ 12 บนกระดาษ'), findsOneWidget);
      expect(find.text('อ่านได้: ก, ค'), findsOneWidget);
      expect(find.text('ฝนหลายวง'), findsOneWidget);
      expect(find.text('ภาพหน้ากระดาษถูกลบหลังประกาศผลแล้ว'), findsOneWidget);
      expect(find.text('กรอบสีแดงคือข้อ 12 แตะภาพเพื่อขยาย'), findsOneWidget);
      // Homework-only parts are hidden: no crop, extraction or AI text.
      expect(find.text('สิ่งที่อ่านได้'), findsNothing);
      expect(find.text('เหตุผลของคะแนน'), findsNothing);
      expect(
        find.byKey(const ValueKey('regenerate_explanation')),
        findsNothing,
      );
      expect(find.text('แก้คะแนนตรง'), findsOneWidget);
      expect(find.textContaining('คิดด้วยเฉลยได้ 0 คะแนน'), findsOneWidget);
    });

    testWidgets('the teacher picks what the student meant; it is resolved', (
      tester,
    ) async {
      final repo = await _pumpDetail(tester, examResponseJson());

      // Starts from what code read: ก and ค.
      FilterChip chip(int p) =>
          tester.widget(find.byKey(ValueKey('exam_option_$p')));
      expect(
        [for (var p = 1; p <= 4; p++) chip(p).selected],
        [true, false, true, false],
      );
      await tester.tap(find.byKey(const ValueKey('exam_option_1')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const ValueKey('exam_resolve_next')),
      );
      await tester.tap(find.byKey(const ValueKey('exam_resolve_next')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('ใช้คำตอบนี้แล้ว คิดคะแนนตามเฉลย'), findsOneWidget);
      await tester.pumpAndSettle();

      expect(repo.examResolutions.single.$1, 21);
      expect(repo.examResolutions.single.$2, {
        'options': [3],
      });
      expect(find.text('ต้องตรวจ 2/2'), findsOneWidget, reason: 'advanced');
    });

    testWidgets('a number is sent as value; blank means no answer', (
      tester,
    ) async {
      final repo = await _pumpDetail(
        tester,
        examResponseJson(type: 'numeric', doubts: ['invalid_number']),
      );
      expect(find.text('อ่านได้: อ่านไม่ได้'), findsOneWidget);
      expect(find.text('ตัวเลขอ่านไม่ได้'), findsOneWidget);

      await tester.ensureVisible(find.byKey(const ValueKey('exam_resolve')));
      await tester.tap(find.byKey(const ValueKey('exam_resolve')));
      await tester.pumpAndSettle();
      expect(repo.examResolutions.last.$2, {'value': null});

      await tester.enterText(find.byKey(const ValueKey('exam_value')), '12.5');
      repo.resolveError = apiError(422, {
        'message': 'ตัวเลขไม่ถูกต้อง',
        'errors': {
          'value': ['ตัวเลขไม่ถูกต้อง'],
        },
        'code': 'validation_failed',
      });
      await tester.ensureVisible(find.byKey(const ValueKey('exam_resolve')));
      await tester.tap(find.byKey(const ValueKey('exam_resolve')));
      await tester.pumpAndSettle();
      expect(repo.examResolutions.last.$2, {'value': '12.5'});
      expect(find.byKey(const ValueKey('exam_resolve_error')), findsOneWidget);
    });

    testWidgets('a resolved answer shows the teacher\'s reading; a published '
        'one cannot be resolved', (tester) async {
      await _pumpDetail(
        tester,
        examResponseJson(
          resolved: {
            'selected': [2],
            'value': null,
            'by': 5,
            'at': '2026-10-01T02:00:00Z',
          },
          reviewedAt: '2026-10-01T02:00:00Z',
          submissionStatus: 'published',
        ),
      );
      expect(find.text('ครูอ่านรอยฝนแล้ว: ข'), findsOneWidget);
      final chip = tester.widget<FilterChip>(
        find.byKey(const ValueKey('exam_option_2')),
      );
      expect(chip.selected, isTrue);
      expect(chip.onSelected, isNull);
      final button = tester.widget<OutlinedButton>(
        find.byKey(const ValueKey('exam_resolve')),
      );
      expect(button.onPressed, isNull);
    });

    testWidgets('the warped page is drawn with the row highlighted', (
      tester,
    ) async {
      final image = (await tester.runAsync(
        () => createTestImage(width: 200, height: 280),
      ))!;
      final pages = _Pages();
      await _pumpDetail(
        tester,
        examResponseJson(),
        pages: pages,
        decoder: (_) async => image,
      );
      await tester.pumpAndSettle();
      expect(pages.asked, [77]);
      final canvas = find.byKey(const ValueKey('scan_page_canvas'));
      expect(canvas, findsOneWidget);
      final painter =
          tester.widget<CustomPaint>(canvas).painter! as PagePainter;
      expect(painter.answerBox, [200, 100, 230, 350]);

      await tester.tap(canvas);
      await tester.pumpAndSettle();
      expect(find.text('ภาพหน้ากระดาษคำตอบ'), findsOneWidget);
    });
  });

  testWidgets('queue rows of an exam show the sheet number and doubts', (
    tester,
  ) async {
    _phone(tester);
    final repo = FakeReviewRepository(
      rows: [examQueueRow(), examQueueRow(id: 22, resolved: true)],
    );
    await pumpScreen(
      tester,
      const ReviewQueueScreen(assignmentId: 40),
      overrides: [
        reviewRepositoryProvider.overrideWithValue(repo),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
      ],
    );
    expect(find.text('ข้อ 12 · ปรนัย · เข้าใจดี'), findsNWidgets(2));
    expect(find.text('ฝนหลายวง'), findsNWidgets(2));
    expect(find.text('ครูอ่านรอยฝนแล้ว'), findsOneWidget);
  });

  testWidgets('ScanPageImage offers a retry when offline', (tester) async {
    final pages = _Pages(
      error: DioException(
        requestOptions: RequestOptions(path: '/x'),
        type: DioExceptionType.connectionError,
      ),
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [scanPageLoaderProvider.overrideWithValue(pages)],
        child: const MaterialApp(
          home: Scaffold(body: ScanPageImage(scanId: 5)),
        ),
      ),
    );
    await tester.pumpAndSettle(const Duration(seconds: 10));
    expect(find.text('โหลดภาพหน้ากระดาษไม่ได้'), findsOneWidget);
    expect(find.text('ลองใหม่'), findsOneWidget);
  });

  group('models and repository', () {
    test('the exam block and the queue row parse', () {
      final d = ResponseDetail.fromJson(examResponseJson());
      final e = d.exam!;
      expect(d.isExam, isTrue);
      expect(
        [e.sheetNo, e.versionNo, e.versionLabel, e.scanId],
        [12, 2, 'ข', 77],
      );
      expect(e.readText, 'ก, ค');
      expect(e.box, [200, 100, 230, 350]);
      expect(e.reviewDoubts, ['double_mark']);
      expect(e.resolvedText, isNull);
      expect(ResponseDetail.fromJson(responseJson()).exam, isNull);

      final item = ReviewItem.fromJson(examQueueRow(resolved: true));
      expect(item.isExam, isTrue);
      expect(item.displayNumber, 12);
      expect(item.examAnswer!.resolved, isTrue);
      expect(ReviewItem.fromJson(queueRow(id: 1)).displayNumber, 1);

      expect(examDoubtLabel('version_unknown'), 'ไม่ทราบชุด');
      expect(examDoubtLabel('other'), 'other');
      expect(
        ExamAnswerView.fromJson({
          'sheet_no': 1,
          'labels': <String>[],
          'rect': null,
        })!.box,
        isNull,
      );
    });

    test(
      'resolve POSTs options or value to /exam-responses/{id}/resolve',
      () async {
        final adapter = FakeHttpAdapter(
          (_) async => jsonResponse(200, {'data': examResponseJson()}),
        );
        final repo = ApiReviewRepository(fakeDio(adapter));
        final d = await repo.resolveExamAnswer(
          21,
          const ExamResolution.options([2]),
        );
        expect(d.exam!.sheetNo, 12);
        await repo.resolveExamAnswer(21, const ExamResolution.number(null));
        expect(
          adapter.requests.first.uri.path,
          '/api/v1/exam-responses/21/resolve',
        );
        Object? body(int i) => adapter.requests[i].data is String
            ? jsonDecode(adapter.requests[i].data as String)
            : adapter.requests[i].data;
        expect(body(0), {
          'options': [2],
        });
        expect(body(1), {'value': null});
      },
    );

    test('scan pages are fetched as bytes from /scans/{id}/page', () async {
      final adapter = FakeHttpAdapter(
        (_) async => ResponseBody.fromBytes([7, 8], 200),
      );
      final bytes = await ApiScanPageLoader(fakeDio(adapter)).page(77);
      expect(adapter.requests.single.uri.path, '/api/v1/scans/77/page');
      expect(bytes, [7, 8]);
    });
  });
}
