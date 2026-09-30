import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A camera failure with a Thai message for the teacher.
class ScanCameraException implements Exception {
  const ScanCameraException(this.message, {this.permissionDenied = false});

  final String message;
  final bool permissionDenied;

  @override
  String toString() => 'ScanCameraException($message)';
}

/// The still camera of the scan screen, behind an interface so widget tests
/// can run the screen without the camera plugin.
abstract class ScanCamera {
  Future<void> initialize();

  /// Width / height of the preview in portrait.
  double get previewAspectRatio;

  Widget buildPreview();

  /// Takes a full-resolution JPEG and returns its path (cache directory).
  Future<String> takePicture();

  Future<void> setTorch(bool on);

  Future<void> dispose();
}

/// `package:camera` (CameraX on Android).
class PluginScanCamera implements ScanCamera {
  PluginScanCamera({this.preset = ResolutionPreset.max});

  /// [ResolutionPreset.max] for worksheets; a teacher's key photo only needs
  /// [ResolutionPreset.veryHigh] (1080p: at most 2,000 px, DESIGN §21.9).
  final ResolutionPreset preset;

  CameraController? _controller;

  @override
  Future<void> initialize() async {
    try {
      final cameras = await availableCameras();
      if (cameras.isEmpty) {
        throw const ScanCameraException('ไม่พบกล้องในเครื่องนี้');
      }
      final back = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cameras.first,
      );
      final controller = CameraController(
        back,
        // Worksheets: highest still resolution, the page is warped to
        // 200 DPI (DESIGN §6.2), which needs about 8 MP (KICKOFF 2b).
        preset,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );
      _controller = controller;
      await controller.initialize();
      await controller.setFlashMode(FlashMode.off);
    } on CameraException catch (e) {
      throw _map(e);
    }
  }

  @override
  double get previewAspectRatio {
    final c = _controller;
    if (c == null || !c.value.isInitialized) return 3 / 4;
    // The plugin reports landscape width / height.
    return 1 / c.value.aspectRatio;
  }

  @override
  Widget buildPreview() {
    final c = _controller;
    if (c == null || !c.value.isInitialized) return const SizedBox.shrink();
    return CameraPreview(c);
  }

  @override
  Future<String> takePicture() async {
    final c = _controller;
    if (c == null || !c.value.isInitialized) {
      throw const ScanCameraException('กล้องยังไม่พร้อม');
    }
    try {
      return (await c.takePicture()).path;
    } on CameraException catch (e) {
      throw _map(e);
    }
  }

  @override
  Future<void> setTorch(bool on) async {
    try {
      await _controller?.setFlashMode(on ? FlashMode.torch : FlashMode.off);
    } on CameraException catch (e) {
      throw _map(e);
    }
  }

  @override
  Future<void> dispose() async {
    final c = _controller;
    _controller = null;
    await c?.dispose();
  }

  static ScanCameraException _map(CameraException e) {
    if (e.code.startsWith('CameraAccess')) {
      return const ScanCameraException(
        'แอปยังไม่ได้รับอนุญาตให้ใช้กล้อง เปิดสิทธิ์กล้องให้ EduVision '
        'ในการตั้งค่าของเครื่อง แล้วลองใหม่',
        permissionDenied: true,
      );
    }
    return ScanCameraException('เปิดกล้องไม่ได้ (${e.description ?? e.code})');
  }
}

/// A fresh camera per scan screen; tests override it with a fake.
final scanCameraFactoryProvider = Provider<ScanCamera Function()>(
  (ref) => PluginScanCamera.new,
);

/// The camera of "ถ่ายรูปเฉลย" (DESIGN §19.5): 1080p is plenty for Gemini
/// at the document resolution and keeps several photos under the upload
/// limit of one read.
final keyPhotoCameraFactoryProvider = Provider<ScanCamera Function()>(
  (ref) =>
      () => PluginScanCamera(preset: ResolutionPreset.veryHigh),
);
