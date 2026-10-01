import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import 'pigeons/scan_api.g.dart';

/// Longest side of a rendered page (DESIGN §22.4: at most 2,000 px).
const kPageRenderLongSide = 2000;

/// A failure of the native page renderer with its Pigeon error code
/// (`document_unreadable`, `page_out_of_range`, `unsupported`,
/// `storage_failed`).
class PageRenderException implements Exception {
  const PageRenderException(this.code, [this.detail]);

  final String code;
  final String? detail;

  /// Thai text for the teacher.
  String get message => switch (code) {
    'page_out_of_range' => 'ไม่มีหน้านี้ในไฟล์',
    'unsupported' => 'เครื่องนี้เปิดไฟล์ชนิดนี้ไม่ได้ แนบรูปภาพประกอบเอง',
    'storage_failed' => 'บันทึกภาพหน้าไม่ได้ พื้นที่ในเครื่องอาจเต็ม',
    _ => 'เปิดไฟล์นี้ไม่ได้ (ไฟล์เสียหรือมีรหัสผ่าน)',
  };

  @override
  String toString() => 'PageRenderException($code, $detail)';
}

/// Renders pages of the teacher's exam file on the phone (DESIGN §22.4):
/// the server cannot render a PDF or decode HEIC, so the app uploads the
/// pages its figures are cropped from. Behind an interface so screens and
/// tests do not depend on the platform channel.
abstract class DocumentPageRenderer {
  /// False where pages cannot be rendered (the Chrome preview): figures
  /// stay "ยังไม่มีภาพประกอบ" and the teacher attaches pictures.
  bool get isSupported;

  /// A JPEG of page [pageNo] (1-based) of the file at [path].
  Future<String> renderPage(String path, String mimeType, int pageNo);

  /// Writes a file that only exists as bytes (downloaded, or picked
  /// without a path) to the cache so it can be rendered.
  Future<String> saveTemp(String name, Uint8List bytes);
}

/// `PdfRenderer`/`ImageDecoder` in Kotlin through Pigeon (Android only).
class NativeDocumentPageRenderer implements DocumentPageRenderer {
  NativeDocumentPageRenderer([DocumentPageApi? api])
    : _api = api ?? DocumentPageApi();

  final DocumentPageApi _api;
  int _serial = 0;

  @override
  bool get isSupported => true;

  @override
  Future<String> renderPage(String path, String mimeType, int pageNo) async {
    try {
      return await _api.renderDocumentPage(
        path,
        mimeType,
        pageNo,
        kPageRenderLongSide,
      );
    } on PlatformException catch (e) {
      throw PageRenderException(e.code, e.message);
    }
  }

  @override
  Future<String> saveTemp(String name, Uint8List bytes) async {
    final dir = Directory('${(await getTemporaryDirectory()).path}/exam_files');
    await dir.create(recursive: true);
    final safe = name.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');
    final file = File(
      '${dir.path}/${DateTime.now().microsecondsSinceEpoch}-${_serial++}-$safe',
    );
    await file.writeAsBytes(bytes, flush: true);
    return file.path;
  }
}

class UnsupportedDocumentPageRenderer implements DocumentPageRenderer {
  const UnsupportedDocumentPageRenderer();

  @override
  bool get isSupported => false;

  @override
  Future<String> renderPage(String path, String mimeType, int pageNo) =>
      Future.error(const PageRenderException('unsupported'));

  @override
  Future<String> saveTemp(String name, Uint8List bytes) =>
      Future.error(const PageRenderException('unsupported'));
}

final documentPageRendererProvider = Provider<DocumentPageRenderer>(
  (ref) => !kIsWeb && Platform.isAndroid
      ? NativeDocumentPageRenderer()
      : const UnsupportedDocumentPageRenderer(),
);
