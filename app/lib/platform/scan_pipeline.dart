import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'pigeons/scan_api.g.dart';

export 'pigeons/scan_api.g.dart' show PageCrops, PageDetection, RegionCrop;

/// A failure of the native pipeline, with the Pigeon error code
/// (`image_unreadable`, `markers_missing`, `layout_invalid`,
/// `storage_failed`, `opencv_unavailable`, `pipeline_failed`, ...).
class ScanPipelineException implements Exception {
  const ScanPipelineException(this.code, [this.detail]);

  final String code;
  final String? detail;

  /// Thai text for the teacher.
  String get message => switch (code) {
    'image_unreadable' => 'เปิดไฟล์ภาพไม่ได้ ลองถ่ายใหม่',
    'markers_missing' => 'ต้องเห็นสัญลักษณ์ครบทั้ง 4 มุมจึงจะตัดภาพได้',
    'layout_invalid' =>
      'layout ของใบงานนี้ไม่ถูกต้อง ให้สร้าง layout ใหม่แล้วพิมพ์ใบงานอีกครั้ง',
    'storage_failed' => 'บันทึกไฟล์ภาพไม่ได้ พื้นที่ในเครื่องอาจเต็ม',
    'opencv_unavailable' => 'เครื่องนี้โหลดตัวประมวลผลภาพไม่ได้',
    'unsupported' => 'สแกนใบงานได้เฉพาะในแอป Android',
    _ => 'ประมวลผลภาพไม่สำเร็จ ลองถ่ายใหม่',
  };

  @override
  String toString() => 'ScanPipelineException($code, $detail)';
}

/// The native scan pipeline of DESIGN §6.2 behind an interface, so screens
/// and tests do not depend on the platform channel.
abstract class ScanPipeline {
  /// False on platforms without a native implementation (web preview,
  /// desktop test runs).
  bool get isSupported;

  /// Markers, QR and blur of a full photo.
  Future<PageDetection> detectPage(String imagePath);

  /// Warp + crops of one layout page ([layoutJson] is DESIGN §5.3).
  Future<PageCrops> cropPage(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  );
}

/// Kotlin + OpenCV + ML Kit implementation (Android only).
class NativeScanPipeline implements ScanPipeline {
  NativeScanPipeline([ScanPipelineApi? api]) : _api = api ?? ScanPipelineApi();

  final ScanPipelineApi _api;

  @override
  bool get isSupported => true;

  @override
  Future<PageDetection> detectPage(String imagePath) =>
      _guard(() => _api.detectPage(imagePath));

  @override
  Future<PageCrops> cropPage(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) => _guard(() => _api.cropPage(imagePath, detection, layoutJson));

  Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on PlatformException catch (e) {
      throw ScanPipelineException(e.code, e.message);
    }
  }
}

class UnsupportedScanPipeline implements ScanPipeline {
  const UnsupportedScanPipeline();

  @override
  bool get isSupported => false;

  @override
  Future<PageDetection> detectPage(String imagePath) =>
      Future.error(const ScanPipelineException('unsupported'));

  @override
  Future<PageCrops> cropPage(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) => Future.error(const ScanPipelineException('unsupported'));
}

bool get _nativePipelineAvailable => !kIsWeb && Platform.isAndroid;

final scanPipelineProvider = Provider<ScanPipeline>(
  (ref) => _nativePipelineAvailable
      ? NativeScanPipeline()
      : const UnsupportedScanPipeline(),
);
