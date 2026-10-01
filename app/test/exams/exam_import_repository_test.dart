import 'dart:io';

import 'package:dio/dio.dart';
import 'package:eduvision/features/exams/exam_import_models.dart';
import 'package:eduvision/features/exams/exam_import_repository.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'exam_fakes.dart';
import 'exam_import_fakes.dart';

/// Request/response shapes of the build 5 endpoints (DESIGN §22.15) and
/// the models they fill.
void main() {
  late FakeHttpAdapter adapter;
  late ApiExamImportRepository repo;
  Object? body;
  var status = 200;

  setUp(() {
    body = {'data': <String, dynamic>{}};
    status = 200;
    adapter = FakeHttpAdapter((_) async => jsonResponse(status, body));
    repo = ApiExamImportRepository(fakeDio(adapter));
  });

  RequestOptions last() => adapter.requests.last;

  test('estimate POSTs the files, the range and the guidance', () async {
    body = {
      'data': {
        'pages': 3,
        'cached': false,
        'estimate': {'input_tokens': 1800, 'output_tokens': 16384, 'thb': 0.4},
      },
    };
    final e = await repo.estimate(
      40,
      documentIds: [501],
      pageFrom: 2,
      pageTo: 4,
      guidance: '  เฉลยอยู่ท้ายไฟล์ ',
    );
    expect(last().method, 'POST');
    expect(last().uri.path, '/api/v1/exams/40/import/estimate');
    expect(last().data, {
      'document_ids': [501],
      'page_from': 2,
      'page_to': 4,
      'guidance': 'เฉลยอยู่ท้ายไฟล์',
    });
    expect(e.pages, 3);
    expect(e.estimate.thb, 0.4);
  });

  test('import 200 (cached) is applied with figures pending', () async {
    body = {
      'data': {
        'cached': true,
        'estimate': null,
        'extraction': {
          'id': 70,
          'purpose': 'exam',
          'status': 'done',
          'error': null,
          'guidance': null,
        },
        'import': {'id': 5, 'extraction_id': 70, 'documents': []},
        'applied': {
          'sections': 2,
          'questions': 31,
          'skipped': [
            {'number': 32, 'reason_th': 'ข้อเขียนตอบ ฝนไม่ได้'},
          ],
        },
        'figures_pending': [
          {
            'source_document_id': 501,
            'page_no': 2,
            'original_name': 'a.pdf',
            'mime_type': 'application/pdf',
            'figures': 3,
            'reason': 'needs_render',
          },
          {
            'source_document_id': 502,
            'page_no': 1,
            'original_name': null,
            'mime_type': null,
            'figures': 1,
            'reason': 'document_missing',
          },
        ],
      },
    };
    final r = await repo.import(40, documentIds: [501, 502]);
    expect(last().uri.path, '/api/v1/exams/40/import');
    expect(last().data, {
      'document_ids': [501, 502],
    });
    expect(r.cached, isTrue);
    expect(r.read.done, isTrue);
    expect(r.applied!.questions, 31);
    expect(r.applied!.skipped.single.label, 'ข้อ 32: ข้อเขียนตอบ ฝนไม่ได้');
    expect(r.figuresPending, hasLength(2));
    expect(r.figuresPending.first.needsRender, isTrue);
    expect(r.figuresPending.last.needsRender, isFalse);
  });

  test('import 202 is queued without applied', () async {
    status = 202;
    body = {
      'data': {
        'cached': false,
        'estimate': {'input_tokens': 10, 'output_tokens': 20, 'thb': null},
        'extraction': {'id': 71, 'status': 'queued', 'guidance': 'ข้าม ตอน 3'},
        'import': {'id': 6},
        'applied': null,
        'figures_pending': [],
      },
    };
    final r = await repo.import(40, documentIds: [501], guidance: 'ข้าม ตอน 3');
    expect(r.read.queued, isTrue);
    expect(r.read.guidance, 'ข้าม ตอน 3');
    expect(r.applied, isNull);
    expect(r.estimate!.inputTokens, 10);
  });

  test(
    'read polls GET /document-extractions/{id} with notes and skipped',
    () async {
      body = {
        'data': {
          'id': 71,
          'purpose': 'exam',
          'status': 'done',
          'result': {
            'kind': 'exam',
            'notes_th': ' ข้อ 7 ภาพไม่ชัด ',
            'sections': [],
            'skipped': [
              {'number': null, 'reason_th': 'ตารางคะแนนท้ายไฟล์'},
            ],
          },
        },
      };
      final r = await repo.read(71);
      expect(last().method, 'GET');
      expect(last().uri.path, '/api/v1/document-extractions/71');
      expect(r.done, isTrue);
      expect(r.notesTh, 'ข้อ 7 ภาพไม่ชัด');
      expect(r.skipped.single.label, 'ตารางคะแนนท้ายไฟล์');
    },
  );

  test('page image and source file come back as bytes', () async {
    adapter = FakeHttpAdapter(
      (_) async => ResponseBody.fromBytes(const [1, 2, 3], 200),
    );
    repo = ApiExamImportRepository(fakeDio(adapter));
    expect(await repo.pageImage(801), [1, 2, 3]);
    expect(last().uri.path, '/api/v1/exam-page-images/801');
    expect(last().responseType, ResponseType.bytes);
    expect(await repo.documentFile(40, 501), [1, 2, 3]);
    expect(last().uri.path, '/api/v1/exams/40/documents/501/file');
  });

  test('uploadPageImage sends multipart and answers figures pending', () async {
    final dir = await Directory.systemTemp.createTemp('page');
    addTearDown(() => dir.delete(recursive: true));
    final file = File('${dir.path}/p.jpg')..writeAsBytesSync([0xFF, 0xD8]);
    status = 201;
    body = {
      'data': {
        'page_image': {'id': 802},
        'figures_pending': [],
      },
    };
    final left = await repo.uploadPageImage(
      40,
      sourceDocumentId: 501,
      pageNo: 2,
      jpegPath: file.path,
    );
    expect(last().uri.path, '/api/v1/exams/40/page-images');
    final form = last().data as FormData;
    expect(
      {for (final f in form.fields) f.key: f.value},
      {'source_document_id': '501', 'page_no': '2'},
    );
    expect(form.files.single.key, 'image');
    expect(form.files.single.value.contentType.toString(), 'image/jpeg');
    expect(left, isEmpty);
  });

  test('setFigure PUTs page_image_id and box_2d', () async {
    body = {'data': questionJson(id: 41, sectionId: 4, position: 5)};
    await repo.setFigure(
      (option: false, id: 41),
      pageImageId: 801,
      box: [10, 20, 300, 400],
    );
    expect(last().method, 'PUT');
    expect(last().uri.path, '/api/v1/questions/41/figure');
    expect(last().data, {
      'page_image_id': 801,
      'box_2d': [10, 20, 300, 400],
    });
    await repo.setFigure(
      (option: true, id: 412),
      pageImageId: 801,
      box: [1, 2, 30, 40],
    );
    expect(last().uri.path, '/api/v1/question-options/412/figure');
  });

  test('library filters and reads a cursor page', () async {
    body = {
      'data': [
        libraryJson(
          id: 7,
          examId: 30,
          examTitle: 'สอบปลายภาค 2568',
          sectionId: 3,
        ),
      ],
      'meta': {'per_page': 50, 'next_cursor': 'abc'},
    };
    final page = await repo.library(
      courseId: 3,
      query: ' เศษส่วน ',
      excludeExam: 40,
      cursor: 'xyz',
    );
    expect(last().uri.path, '/api/v1/teacher/exam-questions');
    expect(last().queryParameters, {
      'course_id': 3,
      'q': 'เศษส่วน',
      'exclude_exam': 40,
      'cursor': 'xyz',
    });
    expect(page.nextCursor, 'abc');
    final q = page.items.single;
    expect(q.exam.title, 'สอบปลายภาค 2568');
    expect(q.section.typeSummary, 'ปรนัย 4 ตัวเลือก');
    expect(q.question.options, hasLength(4));
  });

  test(
    'copyQuestions answers the created count, skipped and the exam',
    () async {
      status = 201;
      body = {
        'data': {
          'created': 1,
          'question_ids': [90],
          'skipped': [
            {
              'question_id': 8,
              'reason': 'option_count_mismatch',
              'reason_th': null,
            },
          ],
          'exam': examJson(),
        },
      };
      final r = await repo.copyQuestions(40, questionIds: [7, 8], sectionId: 1);
      expect(last().uri.path, '/api/v1/exams/40/copy-questions');
      expect(last().data, {
        'question_ids': [7, 8],
        'section_id': 1,
      });
      expect(r.created, 1);
      expect(r.questionIds, [90]);
      expect(r.skipped.single.label, 'จำนวนตัวเลือกไม่ตรงกับตอนปลายทาง');
      expect(r.exam.questionCount, 4);

      await repo.copyQuestions(40, questionIds: [7]);
      expect(last().data, {
        'question_ids': [7],
      });
    },
  );

  group('models', () {
    test('GET /exams/{id} of build 5 carries figures and page images', () {
      final d = ExamDetail.fromJson(
        importedExamJson(
          cropped: false,
          pages: [
            {
              'id': 801,
              'source_document_id': 501,
              'page_no': 1,
              'width_px': 0,
              'height_px': 0,
              'available': true,
            },
            {
              'id': 802,
              'source_document_id': 501,
              'page_no': 3,
              'width_px': 800,
              'height_px': 1000,
              'available': false,
            },
          ],
        ),
      );
      final q = d.question(41)!;
      expect(q.fromDocument, isTrue);
      expect(q.figurePending, isTrue);
      expect(q.figureSource!.box, [100, 200, 400, 800]);
      expect(q.figureSource!.pageImageId, isNull);
      expect(q.options[1].figurePending, isTrue);
      expect(q.anyFigurePending, isTrue);
      expect(d.unapprovedDrafts.map((q) => q.id), [41, 42]);
      expect(d.pageImages, hasLength(2));
      expect(d.availablePages.map((p) => p.id), [801]);
      expect(d.pageImages.first.aspectRatio, closeTo(210 / 297, 1e-9));
      expect(d.pageImages.last.aspectRatio, 0.8);
      expect(d.figuresPending.single.pageNo, 2);
    });

    test('an exam without build 5 fields has none', () {
      final d = ExamDetail.fromJson(examJson());
      expect(d.pageImages, isEmpty);
      expect(d.figuresPending, isEmpty);
      expect(d.unapprovedDrafts, isEmpty);
      expect(d.question(11)!.figureSource, isNull);
    });

    test('FigureBox spans, moves inside the page and validates size', () {
      final b = FigureBox.span(800, 900, 200, 100);
      expect(b.toList(), [100, 200, 900, 800]);
      expect(b.valid, isTrue);
      expect(b.contains(500, 500), isTrue);
      expect(b.contains(100, 500), isFalse);
      expect(b.moved(500, -500).toList(), [0, 400, 800, 1000]);
      expect(FigureBox.span(-50, 10, 3, 1200).toList(), [10, 0, 1000, 3]);
      expect(FigureBox.span(10, 10, 14, 400).valid, isFalse);
      expect(FigureBox.fromList([1, 2, 3, 4]), const FigureBox(1, 2, 3, 4));
      expect(FigureBox.fromList([1, 2]), isNull);
    });

    test('CopySkipped uses the server text first', () {
      expect(
        const CopySkipped(questionId: 1, reason: 'type_mismatch').label,
        'ชนิดของข้อไม่ตรงกับตอนปลายทาง',
      );
      expect(
        const CopySkipped(
          questionId: 1,
          reason: 'type_mismatch',
          reasonTh: 'ชนิดไม่ตรง',
        ).label,
        'ชนิดไม่ตรง',
      );
    });
  });
}
