import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../platform/document_page_renderer.dart';
import 'pdf_page_images_stub.dart'
    if (dart.library.js_interop) 'pdf_page_images_web.dart';

/// One page of a PDF drawn as an image, so the review pane marks the answer
/// on a handed-in PDF with the red box as it does on a photo (DESIGN §46).
class PdfPageImage {
  const PdfPageImage({required this.bytes, required this.page, this.pages});

  /// PNG or JPEG.
  final Uint8List bytes;

  /// The page drawn (1-based).
  final int page;

  /// Pages in the file, when the renderer says.
  final int? pages;
}

abstract class PdfPageImages {
  /// Page [pageNo] (1-based) of [pdf]; throws when it cannot be drawn.
  Future<PdfPageImage> render(Uint8List pdf, int pageNo);
}

/// pdf.js on the web (web/pdf_render.js), PdfRenderer on the phone (the
/// renderer of exam files, DESIGN §22.4); null where neither exists.
final pdfPageImagesProvider = Provider<PdfPageImages?>((ref) {
  final web = platformPdfPageImages();
  if (web != null) return web;
  final native = ref.watch(documentPageRendererProvider);
  return native.isSupported ? NativePdfPageImages(native) : null;
});

/// Through a file in the cache: the native renderer reads a path.
class NativePdfPageImages implements PdfPageImages {
  NativePdfPageImages(this._renderer);

  final DocumentPageRenderer _renderer;

  @override
  Future<PdfPageImage> render(Uint8List pdf, int pageNo) async {
    final path = await _renderer.saveTemp('answer-page.pdf', pdf);
    try {
      final out = await _renderer.renderPage(path, 'application/pdf', pageNo);
      final bytes = await File(out).readAsBytes();
      await _delete(out);
      return PdfPageImage(bytes: bytes, page: pageNo);
    } finally {
      await _delete(path);
    }
  }

  static Future<void> _delete(String path) async {
    try {
      await File(path).delete();
    } on FileSystemException {
      // The cache is cleaned by the system anyway.
    }
  }
}
