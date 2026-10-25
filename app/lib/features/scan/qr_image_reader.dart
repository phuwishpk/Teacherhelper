import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:zxing2/qrcode.dart';

/// Reads the QR of a still photo in pure Dart (`zxing2`), for the web
/// camera scan (DESIGN §25.6): a browser has neither ML Kit nor Vision, and
/// the web app's CSP loads no script from elsewhere.
abstract class QrImageReader {
  /// The text of the QR in [image] (JPEG or PNG bytes), or null when none
  /// is found.
  Future<String?> read(Uint8List image);
}

class ZxingQrImageReader implements QrImageReader {
  /// Longest side the photo is decoded at. A worksheet QR on a 1080p page
  /// photo stays far above zxing's minimum module size, and a browser
  /// decodes it in well under a second.
  static const maxSide = 1600;

  @override
  Future<String?> read(Uint8List image) async {
    final pixels = await _rgba(image);
    if (pixels == null) return null;
    final (width, height, rgba) = pixels;
    // RGBA bytes read as little-endian ints swap red and blue, which the
    // luminance (r + 2g + b) does not see.
    final source = RGBLuminanceSource(width, height, rgba.buffer.asInt32List());
    final hints = DecodeHints()..put(DecodeHintType.tryHarder);
    for (final binarizer in [
      HybridBinarizer(source),
      GlobalHistogramBinarizer(source),
    ]) {
      try {
        return QRCodeReader()
            .decode(BinaryBitmap(binarizer), hints: hints)
            .text;
      } on ReaderException {
        continue;
      }
    }
    return null;
  }

  static Future<(int, int, Uint8List)?> _rgba(Uint8List image) async {
    ui.Codec? codec;
    ui.Image? frame;
    try {
      final buffer = await ui.ImmutableBuffer.fromUint8List(image);
      // ImageDescriptor is not available in a browser; this works on both.
      codec = await ui.instantiateImageCodecWithSize(
        buffer,
        getTargetSize: (width, height) {
          final longest = width > height ? width : height;
          if (longest <= maxSide) return ui.TargetImageSize();
          final scale = maxSide / longest;
          return ui.TargetImageSize(
            width: (width * scale).round(),
            height: (height * scale).round(),
          );
        },
      );
      frame = (await codec.getNextFrame()).image;
      final data = await frame.toByteData(format: ui.ImageByteFormat.rawRgba);
      if (data == null) return null;
      return (frame.width, frame.height, data.buffer.asUint8List());
    } catch (_) {
      return null;
    } finally {
      frame?.dispose();
      codec?.dispose();
    }
  }
}

final qrImageReaderProvider = Provider<QrImageReader>(
  (ref) => ZxingQrImageReader(),
);
