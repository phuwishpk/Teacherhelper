import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'pigeons/scan_api.g.dart';

/// Why an attachment could not be turned into pages. [message] is Thai.
class RasterizeException implements Exception {
  const RasterizeException(this.code, [this.detail]);

  final String code;
  final String? detail;

  String get message => switch (code) {
    'format_unsupported' =>
      'ไฟล์ชนิดนี้เปิดในเครื่องนี้ไม่ได้ ให้นักเรียนส่งเป็น JPEG หรือ PDF',
    'image_unreadable' => 'เปิดรูปนี้ไม่ได้ ไฟล์อาจเสีย',
    'pdf_unreadable' => 'เปิด PDF นี้ไม่ได้ (ไฟล์อาจเสียหรือมีรหัสผ่าน)',
    'storage_failed' => 'บันทึกไฟล์ภาพไม่ได้ พื้นที่ในเครื่องอาจเต็ม',
    'unsupported' => 'แปลงไฟล์ได้เฉพาะในแอป Android',
    _ => 'แปลงไฟล์เป็นภาพไม่สำเร็จ',
  };

  @override
  String toString() => 'RasterizeException($code, $detail)';
}

/// The JPEG pages made from one attachment.
class RasterizedPages {
  const RasterizedPages(this.paths, {int? totalPages})
    : totalPages = totalPages ?? paths.length;

  /// One per rendered page, in the pipeline's cache folder.
  final List<String> paths;

  /// Pages in the file. A PDF longer than the plugin's limit (20) is cut,
  /// so this can be more than [paths].
  final int totalPages;

  /// Pages of the file that were not rendered.
  int get skippedPages =>
      totalPages > paths.length ? totalPages - paths.length : 0;
}

/// Turns a Google Classroom attachment (PDF, HEIC, WebP, ...) into JPEG
/// pages for the scan pipeline (DESIGN §18.2): `rasterize` of the Kotlin
/// plugin (PdfRenderer / ImageDecoder).
abstract class AttachmentRasterizer {
  bool get isSupported;

  Future<RasterizedPages> rasterize(String path, String mimeType);
}

class NativeAttachmentRasterizer implements AttachmentRasterizer {
  NativeAttachmentRasterizer([ScanPipelineApi? api])
    : _api = api ?? ScanPipelineApi();

  final ScanPipelineApi _api;

  @override
  bool get isSupported => true;

  @override
  Future<RasterizedPages> rasterize(String path, String mimeType) async {
    try {
      final result = await _api.rasterize(path, mimeType);
      return RasterizedPages(result.pagePaths, totalPages: result.totalPages);
    } on PlatformException catch (e) {
      throw RasterizeException(e.code, e.message);
    }
  }
}

class UnsupportedAttachmentRasterizer implements AttachmentRasterizer {
  const UnsupportedAttachmentRasterizer();

  @override
  bool get isSupported => false;

  @override
  Future<RasterizedPages> rasterize(String path, String mimeType) =>
      Future.error(const RasterizeException('unsupported'));
}

final attachmentRasterizerProvider = Provider<AttachmentRasterizer>(
  (ref) => !kIsWeb && Platform.isAndroid
      ? NativeAttachmentRasterizer()
      : const UnsupportedAttachmentRasterizer(),
);
