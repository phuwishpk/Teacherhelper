import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../scan/scan_camera.dart';

/// The Y plane of one camera frame (YUV_420), for continuous scanning.
class CameraFrame {
  const CameraFrame({
    required this.yPlane,
    required this.width,
    required this.height,
    required this.bytesPerRow,
    required this.rotation,
  });

  final Uint8List yPlane;
  final int width;
  final int height;
  final int bytesPerRow;

  /// Sensor orientation in degrees (the native side does not need it).
  final int rotation;
}

/// The camera of the answer-sheet scan screen: still photos like
/// [ScanCamera], plus a frame stream for "สแกนต่อเนื่อง" (DESIGN §22.10).
abstract class FrameScanCamera implements ScanCamera {
  Future<void> startFrames(void Function(CameraFrame frame) onFrame);
  Future<void> stopFrames();
}

/// `package:camera` with YUV frames. 1080p ([ResolutionPreset.veryHigh])
/// keeps the frame stream light; a portrait photo of an A4 sheet at 1080p
/// is about 150 DPI, plenty for bubbles 4.2 mm wide (§22.7).
class PluginFrameScanCamera implements FrameScanCamera {
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
        ResolutionPreset.veryHigh,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.yuv420,
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
  Future<void> startFrames(void Function(CameraFrame frame) onFrame) async {
    final c = _controller;
    if (c == null || !c.value.isInitialized || c.value.isStreamingImages) {
      return;
    }
    final rotation = c.description.sensorOrientation;
    try {
      await c.startImageStream((image) {
        if (image.planes.isEmpty) return;
        final y = image.planes.first;
        onFrame(
          CameraFrame(
            yPlane: y.bytes,
            width: image.width,
            height: image.height,
            bytesPerRow: y.bytesPerRow,
            rotation: rotation,
          ),
        );
      });
    } on CameraException catch (e) {
      throw _map(e);
    }
  }

  @override
  Future<void> stopFrames() async {
    final c = _controller;
    if (c == null || !c.value.isStreamingImages) return;
    try {
      await c.stopImageStream();
    } on CameraException catch (e) {
      debugPrint('stopImageStream: ${e.code}');
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

/// A fresh camera per answer-sheet scan screen; tests override it.
final frameScanCameraFactoryProvider = Provider<FrameScanCamera Function()>(
  (ref) => PluginFrameScanCamera.new,
);
