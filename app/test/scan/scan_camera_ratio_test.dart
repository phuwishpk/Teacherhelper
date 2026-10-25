import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Camera implements ScanCamera {
  _Camera(this.previewAspectRatio);

  @override
  final double previewAspectRatio;

  @override
  Future<void> initialize() async {}

  @override
  Widget buildPreview() => const SizedBox();

  @override
  Future<String> takePicture() async => '';

  @override
  Future<void> setTorch(bool on) async {}

  @override
  Future<void> dispose() async {}
}

void main() {
  test('the web preview ratio follows the screen, however the stream reports '
      'its size (DESIGN §25.6)', () {
    for (final reported in [16 / 9, 9 / 16]) {
      final camera = _Camera(reported);
      expect(
        previewAspectFor(camera, Orientation.portrait),
        closeTo(9 / 16, 1e-9),
      );
      expect(
        previewAspectFor(camera, Orientation.landscape),
        closeTo(16 / 9, 1e-9),
      );
    }
    // A stream without a size yet gets a page-like box.
    expect(
      previewAspectFor(_Camera(double.nan), Orientation.portrait),
      closeTo(3 / 4, 1e-9),
    );
  });
}
