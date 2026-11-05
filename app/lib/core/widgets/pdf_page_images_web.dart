import 'dart:js_interop';
import 'dart:typed_data';

import '../../platform/document_page_renderer.dart' show kPageRenderLongSide;
import 'pdf_page_images.dart';

extension type _Rendered._(JSObject _) implements JSObject {
  external JSUint8Array get png;
  external int get pages;
  external int get page;
}

@JS('kruRenderPdfPage')
external JSPromise<_Rendered> _renderPdfPage(
  JSUint8Array bytes,
  int pageNo,
  int maxLongSide,
);

/// pdf.js through `kruRenderPdfPage` of web/pdf_render.js (DESIGN §46).
PdfPageImages? platformPdfPageImages() => const _PdfJsPages();

class _PdfJsPages implements PdfPageImages {
  const _PdfJsPages();

  @override
  Future<PdfPageImage> render(Uint8List pdf, int pageNo) async {
    final rendered = await _renderPdfPage(
      pdf.toJS,
      pageNo,
      kPageRenderLongSide,
    ).toDart;
    return PdfPageImage(
      bytes: rendered.png.toDart,
      page: rendered.page,
      pages: rendered.pages,
    );
  }
}
