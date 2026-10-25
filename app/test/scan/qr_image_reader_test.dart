import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:eduvision/features/scan/qr_image_reader.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zxing2/qrcode.dart';

/// A PNG of [payload] as a QR code: [module] pixels per module, a quiet
/// zone of 4 modules, placed at ([left], [top]) on a white page of [size].
Future<Uint8List> _qrPng(
  String payload, {
  int module = 8,
  ui.Size size = const ui.Size(1200, 1600),
  double left = 900,
  double top = 60,
}) async {
  final matrix = Encoder.encode(payload, ErrorCorrectionLevel.m).matrix!;
  final recorder = ui.PictureRecorder();
  final canvas = ui.Canvas(recorder)
    ..drawRect(
      ui.Offset.zero & size,
      ui.Paint()..color = const ui.Color(0xFFFFFFFF),
    );
  final black = ui.Paint()..color = const ui.Color(0xFF000000);
  for (var y = 0; y < matrix.height; y++) {
    for (var x = 0; x < matrix.width; x++) {
      if (matrix.get(x, y) == 1) {
        canvas.drawRect(
          ui.Rect.fromLTWH(
            left + (x + 4) * module,
            top + (y + 4) * module,
            module.toDouble(),
            module.toDouble(),
          ),
          black,
        );
      }
    }
  }
  final image = await recorder.endRecording().toImage(
    size.width.toInt(),
    size.height.toInt(),
  );
  final png = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();
  return png!.buffer.asUint8List();
}

void main() {
  const payload = 'EV1.11.40.2.3.c2lnbmF0dXJl';

  testWidgets('reads the worksheet QR on a page photo', (tester) async {
    final text = await tester.runAsync(
      () async => ZxingQrImageReader().read(await _qrPng(payload)),
    );
    expect(text, payload);
  });

  testWidgets('a page larger than maxSide is scaled down and still read', (
    tester,
  ) async {
    final text = await tester.runAsync(
      () async => ZxingQrImageReader().read(
        await _qrPng(
          payload,
          module: 12,
          size: const ui.Size(2400, 3200),
          left: 1800,
          top: 120,
        ),
      ),
    );
    expect(text, payload);
  });

  testWidgets('a page without a QR, or bytes that are no image, is null', (
    tester,
  ) async {
    final results = await tester.runAsync(() async {
      final recorder = ui.PictureRecorder();
      ui.Canvas(recorder).drawRect(
        const ui.Rect.fromLTWH(0, 0, 400, 400),
        ui.Paint()..color = const ui.Color(0xFFFFFFFF),
      );
      final image = await recorder.endRecording().toImage(400, 400);
      final blank = await image.toByteData(format: ui.ImageByteFormat.png);
      image.dispose();
      final reader = ZxingQrImageReader();
      return [
        await reader.read(blank!.buffer.asUint8List()),
        await reader.read(Uint8List.fromList([1, 2, 3])),
      ];
    });
    expect(results, [null, null]);
  });
}
