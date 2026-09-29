import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:dio/dio.dart';
import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/core/api/submission_pages.dart';
import 'package:eduvision/core/widgets/content_column.dart';
import 'package:eduvision/core/widgets/submission_page_image.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/review/review_detail_screen.dart';
import 'package:eduvision/features/review/review_labels.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_queue_screen.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import 'review_fixtures.dart';

class _OneAssignment extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 1,
    subjectId: 1,
    title: 'บวกเลข',
    status: 'ready',
  );
}

class _Pages implements SubmissionPageLoader {
  _Pages({this.error});

  final Object? error;
  final loads = <int>[];

  @override
  Future<Uint8List> page(int pageId) async {
    loads.add(pageId);
    if (error case final e?) throw e;
    return Uint8List.fromList([1, 2, 3, pageId]);
  }
}

class _Opener implements PageFileOpener {
  _Opener({this.problem});

  final String? problem;
  final opened = <(List<int>, String, String?)>[];

  @override
  Future<String?> open(
    Uint8List bytes, {
    required String fileName,
    String? mimeType,
  }) async {
    opened.add((bytes, fileName, mimeType));
    return problem;
  }
}

/// GET /responses/{id} of a whole-page answer (DESIGN §19.4).
Map<String, dynamic> _wholePage({
  int id = 11,
  String mime = 'application/pdf',
  List<Object>? box,
  bool notFound = false,
  String? aiExplanation,
  String? explanation = 'ตรวจการทดอีกครั้ง',
}) => {
  ...responseJson(
    id: id,
    state: notFound ? 'manual' : 'scored',
    manualReason: notFound ? 'answer_not_found' : null,
    aiScore: notFound ? null : 1,
  ),
  'priority_band': 'check',
  'cnn_text': null,
  'has_crop': false,
  'crop_url': null,
  'channel': 'classroom',
  'late': true,
  'submission_page_id': 9,
  'page_image_url': '/api/v1/submission-pages/9/image',
  'page_mime_type': mime,
  'answer_box': box,
  'explanation': explanation,
  'explanation_edited': aiExplanation != null,
  'ai_explanation': aiExplanation,
  'explanation_source': aiExplanation != null ? 'teacher' : 'ai',
};

void _phone(WidgetTester tester) {
  tester.view.physicalSize = const Size(800, 3600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<FakeReviewRepository> _detail(
  WidgetTester tester,
  Map<String, dynamic> json, {
  SubmissionPageLoader? pages,
  PageFileOpener? opener,
  PageDecoder? decoder,
}) async {
  _phone(tester);
  final repo = FakeReviewRepository(
    rows: [queueRow(id: json['id'] as int)],
    responses: {json['id'] as int: json},
  );
  await pumpScreen(
    tester,
    ReviewDetailScreen(
      assignmentId: 5,
      responseId: json['id'] as int,
      band: PriorityBand.check,
    ),
    overrides: [
      reviewRepositoryProvider.overrideWithValue(repo),
      cropLoaderProvider.overrideWithValue(NoCropLoader()),
      submissionPageLoaderProvider.overrideWithValue(pages ?? _Pages()),
      pageFileOpenerProvider.overrideWithValue(opener ?? _Opener()),
      pageDecoderProvider.overrideWithValue(
        decoder ?? (_) async => throw Exception('cannot decode'),
      ),
    ],
  );
  return repo;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('whole-page answers in the review pane (DESIGN §19.4)', () {
    testWidgets('a PDF page cannot be drawn: the file opens in another app', (
      tester,
    ) async {
      final opener = _Opener();
      final pages = _Pages();
      await _detail(tester, _wholePage(), pages: pages, opener: opener);

      expect(find.text('ภาพงานทั้งหน้า'), findsOneWidget);
      expect(find.text('ไม่มีภาพของข้อนี้'), findsNothing);
      expect(find.text('แสดงภาพนี้บนเครื่องนี้ไม่ได้'), findsOneWidget);
      expect(find.textContaining('ไฟล์ PDF'), findsOneWidget);
      expect(pages.loads, [9]);
      // Late work and the path are labelled.
      expect(find.text('ส่งช้า'), findsOneWidget);
      expect(find.text('ตรวจจากรูปทั้งหน้า'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('page_download')));
      await tester.pumpAndSettle();
      expect(opener.opened.single.$1, [1, 2, 3, 9]);
      expect(opener.opened.single.$2, 'page-9.pdf');
      expect(opener.opened.single.$3, 'application/pdf');
    });

    testWidgets('a file no app can open says why', (tester) async {
      await _detail(
        tester,
        _wholePage(),
        opener: _Opener(problem: 'No APP found to open this file'),
      );
      await tester.tap(find.byKey(const ValueKey('page_download')));
      await tester.pumpAndSettle();
      expect(
        find.text('เปิดไฟล์ไม่ได้: No APP found to open this file'),
        findsOneWidget,
      );
    });

    testWidgets('HEIC that this device cannot decode falls back too', (
      tester,
    ) async {
      final opener = _Opener();
      await _detail(
        tester,
        _wholePage(mime: 'image/heic', box: [100, 100, 300, 900]),
        opener: opener,
      );
      expect(find.text('แสดงภาพนี้บนเครื่องนี้ไม่ได้'), findsOneWidget);
      expect(find.textContaining('ไฟล์ PDF'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('page_download')));
      await tester.pumpAndSettle();
      expect(opener.opened.single.$2, 'page-9.heic');
    });

    testWidgets('a decoded photo is drawn with the answer box highlighted', (
      tester,
    ) async {
      final image = (await tester.runAsync(
        () => createTestImage(width: 200, height: 300),
      ))!;
      await _detail(
        tester,
        _wholePage(mime: 'image/jpeg', box: [100, 200, 400, 800]),
        decoder: (_) async => image,
      );

      expect(find.text('แสดงภาพนี้บนเครื่องนี้ไม่ได้'), findsNothing);
      final canvas = tester.widget<CustomPaint>(
        find.byKey(const ValueKey('page_canvas')),
      );
      final painter = canvas.painter! as PagePainter;
      expect(painter.answerBox, [100, 200, 400, 800]);
      expect(
        PagePainter.boxRect(painter.answerBox, const Size(200, 300)),
        const Rect.fromLTRB(40, 30, 160, 120),
      );
      expect(find.textContaining('กรอบสีแดงคือตำแหน่งคำตอบ'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('page_canvas')));
      await tester.pumpAndSettle();
      expect(find.byType(InteractiveViewer), findsOneWidget);
    });

    testWidgets('a purged file says so', (tester) async {
      final pages = _Pages(
        error: apiError(410, {'message': 'ลบแล้ว', 'code': 'image_purged'}),
      );
      await _detail(tester, _wholePage(), pages: pages);
      expect(
        find.text('ไฟล์งานนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว'),
        findsOneWidget,
      );
      expect(find.text('ลองใหม่'), findsNothing);
      expect(pages.loads, [9], reason: 'a 4xx is not asked again');
    });

    testWidgets('a network error can be retried', (tester) async {
      final pages = _Pages(
        error: DioException(
          requestOptions: RequestOptions(path: '/x'),
          type: DioExceptionType.connectionError,
        ),
      );
      await _detail(tester, _wholePage(), pages: pages);
      expect(find.text('โหลดไฟล์งานไม่ได้'), findsOneWidget);
      final before = pages.loads.length;
      await tester.tap(find.text('ลองใหม่'));
      await tester.pumpAndSettle();
      expect(pages.loads.length, greaterThan(before));
    });

    testWidgets(
      'an answer found nowhere is flagged "หาคำตอบข้อนี้ในภาพไม่เจอ"',
      (tester) async {
        await _detail(tester, _wholePage(notFound: true, explanation: null));
        expect(
          find.byKey(const ValueKey('answer_not_found_notice')),
          findsOneWidget,
        );
        expect(find.textContaining('หาคำตอบข้อนี้ในภาพไม่เจอ'), findsOneWidget);
        expect(find.textContaining('ตรวจเอง:'), findsNothing);
        expect(find.textContaining('AI ไม่พบตำแหน่งคำตอบ'), findsOneWidget);
      },
    );

    testWidgets('the teacher edits the explanation before publishing and can '
        'bring back the AI text', (tester) async {
      final repo = await _detail(
        tester,
        _wholePage(
          explanation: 'ครูเขียนเอง',
          aiExplanation: 'ข้อความจาก Gemini',
        ),
      );
      expect(find.textContaining('ใช้แทนข้อความของ AI'), findsOneWidget);
      expect(find.text('ข้อความจาก Gemini'), findsNothing);

      await tester.tap(find.text('ดูข้อความเดิมของ AI'));
      await tester.pumpAndSettle();
      expect(find.text('ข้อความจาก Gemini'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('use_ai_original')));
      await tester.pumpAndSettle();
      final field = tester.widget<TextField>(
        find.byKey(const ValueKey('explanation_field')),
      );
      expect(field.controller!.text, 'ข้อความจาก Gemini');

      await tester.enterText(
        find.byKey(const ValueKey('explanation_field')),
        'ลองตรวจการยืมเลขอีกครั้ง',
      );
      await tester.tap(find.text('เข้าใจบางส่วน'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('บันทึก'));
      await tester.pumpAndSettle();
      expect(repo.saved.single.$2['explanation'], 'ลองตรวจการยืมเลขอีกครั้ง');
    });

    testWidgets('no "AI original" before the teacher edits', (tester) async {
      await _detail(tester, _wholePage());
      expect(find.byKey(const ValueKey('ai_original')), findsNothing);
      expect(find.textContaining('แก้ได้ก่อนเผยแพร่'), findsOneWidget);
    });

    testWidgets('mcq read from the page shows the chosen options', (
      tester,
    ) async {
      final json = _wholePage();
      json['question'] = {
        ...json['question'] as Map<String, dynamic>,
        'type': 'mcq',
        'answer_key': {'correct': 'B'},
      };
      json['extraction'] = {
        'blank': true,
        'suspicious_instruction': false,
        'legibility': 'clear',
        'selected_options': <String>[],
        'error_types': ['no_answer'],
      };
      await _detail(tester, json);
      expect(find.text('ตัวเลือกที่อ่านได้: ไม่ได้เลือก'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(StatusChip),
          matching: find.text('ไม่ได้ตอบ'),
        ),
        findsOneWidget,
      );
    });
  });

  group('queue', () {
    Future<FakeReviewRepository> pumpQueue(
      WidgetTester tester,
      FakeReviewRepository repo,
    ) async {
      _phone(tester);
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
        ],
      );
      return repo;
    }

    testWidgets('an answer found nowhere is labelled in the list', (
      tester,
    ) async {
      await pumpQueue(
        tester,
        FakeReviewRepository(
          rows: [
            {
              ...queueRow(
                id: 11,
                state: 'manual',
                manualReason: 'answer_not_found',
              ),
              'priority_band': 'check',
              'submission_page_id': 9,
            },
          ],
        ),
      );
      expect(find.text('หาคำตอบในภาพไม่เจอ'), findsOneWidget);
      expect(find.text('ตรวจเอง'), findsNothing);
    });

    testWidgets('a new hand-in waiting for the teacher is graded from the '
        'per-student tab', (tester) async {
      final repo = await pumpQueue(
        tester,
        FakeReviewRepository(
          rows: [queueRow(id: 11)],
          meta: {
            'submissions': [
              {
                'id': 70,
                'status': 'published',
                'student': {
                  'id': 4012,
                  'name': 'ด.ญ. สมหญิง',
                  'student_number': 12,
                },
                'channel': 'classroom',
                'late': true,
                'regrade_pending': true,
                'response_count': 1,
                'reviewed_count': 1,
                'publishable': false,
                'total_score': 2,
              },
            ],
          },
        ),
      );
      await tester.tap(find.text('รายคน'));
      await tester.pumpAndSettle();
      expect(find.text('ส่งช้า'), findsOneWidget);
      expect(find.text('ส่งใหม่ รอครูกดตรวจ'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('grade_70')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ผลเดิมเผยแพร่แล้ว'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'ตรวจ').last);
      await tester.pumpAndSettle();

      expect(repo.graded, [70]);
      expect(find.byKey(const ValueKey('grade_70')), findsNothing);
      expect(find.text('AI กำลังตรวจ'), findsWidgets);
    });

    testWidgets('grading that fails shows the server reason', (tester) async {
      final repo =
          FakeReviewRepository(
              rows: [queueRow(id: 11)],
              meta: {
                'submissions': [
                  {
                    'id': 70,
                    'status': 'needs_review',
                    'student': {
                      'id': 4012,
                      'name': 'ด.ญ. สมหญิง',
                      'student_number': 12,
                    },
                    'regrade_pending': true,
                    'response_count': 1,
                    'reviewed_count': 0,
                  },
                ],
              },
            )
            ..gradeError = apiError(409, {
              'message': 'ไม่มีงาน',
              'errors': null,
              'code': 'nothing_to_grade',
            });
      await pumpQueue(tester, repo);
      await tester.tap(find.text('รายคน'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('grade_70')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ผลเดิมเผยแพร่แล้ว'), findsNothing);
      await tester.tap(find.widgetWithText(FilledButton, 'ตรวจ').last);
      await tester.pumpAndSettle();
      expect(find.text('ไม่มีงานที่ส่งใหม่รอตรวจแล้ว'), findsOneWidget);
    });
  });

  group('models', () {
    test('whole-page fields of a response detail', () {
      final d = ResponseDetail.fromJson(
        _wholePage(
          mime: 'image/png',
          box: [10, 20, 30, 40],
          aiExplanation: 'ต้นฉบับ',
          explanation: 'ของครู',
        ),
      );
      expect(d.isWholePage, isTrue);
      expect(d.submissionPageId, 9);
      expect(d.pageMimeType, 'image/png');
      expect(d.answerBox, [10, 20, 30, 40]);
      expect(d.late, isTrue);
      expect(d.channel, 'classroom');
      expect(d.hasAiOriginal, isTrue);
      expect(d.explanationSource, 'teacher');
    });

    test('a box the app cannot place is dropped', () {
      for (final box in <Object?>[
        [10, 20, 30],
        [30, 20, 10, 40],
        [10, 40, 30, 20],
        [10, 20, 30, 1200],
        ['a', 20, 30, 40],
        'x',
      ]) {
        final d = ResponseDetail.fromJson({..._wholePage(), 'answer_box': box});
        expect(d.answerBox, isNull, reason: '$box');
      }
    });

    test('the AI original is not offered when it matches the text', () {
      final d = ResponseDetail.fromJson(
        _wholePage(aiExplanation: 'เหมือนกัน', explanation: 'เหมือนกัน'),
      );
      expect(d.hasAiOriginal, isFalse);
    });

    test('queue rows and submissions carry the whole-page flags', () {
      final item = ReviewItem.fromJson({
        ...queueRow(id: 1, state: 'manual', manualReason: 'answer_not_found'),
        'submission_page_id': 4,
      });
      expect(item.isWholePage, isTrue);
      expect(item.answerNotFound, isTrue);
      expect(item.bulkApprovable, isFalse);
      expect(item.tab, PriorityBand.check);

      final s = SubmissionSummary.fromJson({
        'id': 3,
        'status': 'grading',
        'channel': 'student_app',
        'late': 1,
        'regrade_pending': true,
        'publishable': false,
        'response_count': 2,
        'reviewed_count': 2,
      });
      expect(s.channel, 'student_app');
      expect(s.late, isTrue);
      expect(s.regradePending, isTrue);
      expect(s.isGrading, isTrue);
      expect(s.canPublish, isFalse, reason: 'the server said so');
      expect(manualReasonLabel('answer_not_found'), 'หาคำตอบข้อนี้ในภาพไม่เจอ');
    });

    test('page file extensions', () {
      expect(pageFileExtension('image/jpeg'), 'jpg');
      expect(pageFileExtension('image/png'), 'png');
      expect(pageFileExtension('image/webp'), 'webp');
      expect(pageFileExtension('image/heif'), 'heif');
      expect(pageFileExtension(null), 'bin');
    });
  });

  group('repository', () {
    test('grade posts to /submissions/{id}/grade', () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(202, {
          'data': {'id': 70, 'status': 'grading', 'regrade_pending': false},
        }),
      );
      await ApiReviewRepository(fakeDio(adapter)).gradeSubmission(70);
      expect(adapter.requests.single.method, 'POST');
      expect(adapter.requests.single.uri.path, '/api/v1/submissions/70/grade');
    });

    test('page files are fetched as bytes', () async {
      final adapter = FakeHttpAdapter(
        (_) async => ResponseBody.fromBytes(
          [37, 80, 68, 70],
          200,
          headers: {
            Headers.contentTypeHeader: ['application/pdf'],
          },
        ),
      );
      final bytes = await ApiSubmissionPageLoader(fakeDio(adapter)).page(9);
      expect(bytes, [37, 80, 68, 70]);
      expect(
        adapter.requests.single.uri.path,
        '/api/v1/submission-pages/9/image',
      );
    });

    test('decodePageImage rejects bytes that are not an image', () async {
      await expectLater(
        decodePageImage(Uint8List.fromList([1, 2, 3])),
        throwsA(anything),
      );
    });
  });

  test('PagePainter repaints only when something changed', () async {
    final image = await createTestImage(width: 4, height: 4);
    final a = PagePainter(image: image, answerBox: null, color: Colors.red);
    expect(
      a.shouldRepaint(
        PagePainter(image: image, answerBox: null, color: Colors.red),
      ),
      isFalse,
    );
    expect(
      a.shouldRepaint(
        PagePainter(
          image: image,
          answerBox: const [1, 2, 3, 4],
          color: Colors.red,
        ),
      ),
      isTrue,
    );
    final recorder = ui.PictureRecorder();
    PagePainter(
      image: image,
      answerBox: const [0, 0, 500, 500],
      color: Colors.red,
    ).paint(Canvas(recorder), const Size(10, 10));
    recorder.endRecording().dispose();
  });
}
