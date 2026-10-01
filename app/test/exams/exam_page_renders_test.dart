import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_page_renders.dart';
import 'package:eduvision/platform/document_page_renderer.dart';
import 'package:eduvision/platform/pigeons/scan_api.g.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_import_fakes.dart';

/// The app renders the pages the server cannot (DESIGN §22.4) and uploads
/// them; one bad page or file does not stop the others.
void main() {
  FigurePending page(int doc, int no, {String reason = 'needs_render'}) =>
      FigurePending(
        sourceDocumentId: doc,
        pageNo: no,
        originalName: 'doc$doc.pdf',
        mimeType: doc == 503 ? 'image/heic' : 'application/pdf',
        figures: 1,
        reason: reason,
      );

  test('renders and uploads every page that needs it', () async {
    final repo = FakeExamImportRepository()..afterUpload = const [];
    final renderer = FakePageRenderer();
    final progress = <(int, int)>[];
    final report = await renderPendingPages(
      repo: repo,
      renderer: renderer,
      examId: 40,
      pending: [
        page(501, 1),
        page(501, 3),
        page(502, 1),
        page(503, 1),
        page(504, 1, reason: 'document_missing'),
      ],
      localFiles: {
        501: const PickedDocument(name: 'a.pdf', path: '/picked/a.pdf'),
        502: PickedDocument(name: 'b.pdf', bytes: Uint8List(3)),
      },
      onProgress: (done, total) => progress.add((done, total)),
    );

    expect(report.uploaded, 4);
    expect(report.failures, isEmpty);
    expect(report.remaining, isEmpty);
    expect(renderer.renders, [
      ('/picked/a.pdf', 'application/pdf', 1),
      ('/picked/a.pdf', 'application/pdf', 3),
      ('/cache/files/doc502.pdf', 'application/pdf', 1),
      ('/cache/files/doc503.pdf', 'image/heic', 1),
    ]);
    // Only the file the phone does not have is downloaded, once.
    expect(repo.args('documentFile'), [503]);
    expect(renderer.saved, ['doc502.pdf', 'doc503.pdf']);
    expect(repo.args('uploadPageImage'), [
      (501, 1, '/cache/page-1.jpg'),
      (501, 3, '/cache/page-3.jpg'),
      (502, 1, '/cache/page-1.jpg'),
      (503, 1, '/cache/page-1.jpg'),
    ]);
    expect(progress.first, (0, 4));
    expect(progress.last, (4, 4));
  });

  test('a page that fails is reported and the rest go on', () async {
    final repo = FakeExamImportRepository()
      ..afterUpload = [page(501, 3)]
      ..failures['documentFile'] = DioException(
        requestOptions: RequestOptions(path: '/x'),
        response: Response(
          requestOptions: RequestOptions(path: '/x'),
          statusCode: 404,
          data: {'message': 'ไฟล์ถูกลบแล้ว', 'code': 'document_missing'},
        ),
        type: DioExceptionType.badResponse,
      );
    final renderer = FakePageRenderer()
      ..failPages[3] = const PageRenderException('page_out_of_range');
    final report = await renderPendingPages(
      repo: repo,
      renderer: renderer,
      examId: 40,
      pending: [page(501, 1), page(501, 3), page(502, 1), page(502, 2)],
      localFiles: const {
        501: PickedDocument(name: 'a.pdf', path: '/picked/a.pdf'),
      },
    );

    expect(report.uploaded, 1);
    expect(report.remaining.single.pageNo, 3);
    expect(report.failures, [
      'doc501.pdf หน้า 3: ไม่มีหน้านี้ในไฟล์',
      'doc502.pdf หน้า 1: ไฟล์ถูกลบแล้ว',
      'doc502.pdf หน้า 2: ไฟล์ถูกลบแล้ว',
    ]);
    // The missing file is asked for once, not per page.
    expect(repo.args('documentFile'), [502]);
  });

  test('figureStatus tells cropped, cropping and missing apart', () {
    final d = ExamDetail.fromJson(importedExamJson(cropped: false));
    final q = d.question(41)!;
    // Page 1 has an image: the server is cropping it.
    expect(
      figureStatus(
        d,
        source: q.figureSource,
        pending: q.figurePending,
        hasImage: q.hasPromptImage,
      ),
      FigureStatus.cropping,
    );
    final o = q.options[1];
    expect(
      figureStatus(
        d,
        source: o.figureSource,
        pending: o.figurePending,
        hasImage: o.hasImage,
      ),
      FigureStatus.missing,
    );
    expect(
      figureStatus(d, source: null, pending: false, hasImage: true),
      FigureStatus.ready,
    );
    expect(
      figureStatus(d, source: null, pending: false, hasImage: false),
      FigureStatus.none,
    );
    final cropped = ExamDetail.fromJson(importedExamJson()).question(41)!;
    expect(
      figureStatus(
        d,
        source: cropped.figureSource,
        pending: false,
        hasImage: true,
      ),
      FigureStatus.ready,
    );
  });

  test(
    'the native renderer asks for 2,000 px and maps platform errors',
    () async {
      final ok = _ThrowingApi(null);
      expect(
        await NativeDocumentPageRenderer(
          ok,
        ).renderPage('/a.pdf', 'application/pdf', 3),
        '/cache/out.jpg',
      );
      expect(ok.calls.single, ('/a.pdf', 'application/pdf', 3, 2000));
      expect(NativeDocumentPageRenderer(ok).isSupported, isTrue);

      final bad = NativeDocumentPageRenderer(
        _ThrowingApi(
          PlatformException(code: 'page_out_of_range', message: 'x'),
        ),
      );
      await expectLater(
        bad.renderPage('/a.pdf', 'application/pdf', 9),
        throwsA(
          isA<PageRenderException>().having(
            (e) => e.code,
            'code',
            'page_out_of_range',
          ),
        ),
      );
      await expectLater(
        const UnsupportedDocumentPageRenderer().renderPage('/a', 'x', 1),
        throwsA(isA<PageRenderException>()),
      );
      await expectLater(
        const UnsupportedDocumentPageRenderer().saveTemp('a', Uint8List(1)),
        throwsA(isA<PageRenderException>()),
      );
    },
  );

  test('PageRenderException has Thai messages', () {
    expect(
      const PageRenderException('unsupported').message,
      contains('แนบรูปภาพประกอบเอง'),
    );
    expect(
      const PageRenderException('document_unreadable').message,
      contains('เปิดไฟล์นี้ไม่ได้'),
    );
    expect(
      const PageRenderException('storage_failed').message,
      contains('พื้นที่'),
    );
    expect(const UnsupportedDocumentPageRenderer().isSupported, isFalse);
  });
}

class _ThrowingApi extends DocumentPageApi {
  _ThrowingApi(this.error);

  final Object? error;
  final calls = <(String, String, int, int)>[];

  @override
  Future<String> renderDocumentPage(
    String path,
    String mimeType,
    int pageNo,
    int maxLongSide,
  ) async {
    calls.add((path, mimeType, pageNo, maxLongSide));
    final e = error;
    if (e != null) throw e;
    return '/cache/out.jpg';
  }
}
