import 'package:eduvision/features/scan/page_layout.dart';
import 'package:flutter_test/flutter_test.dart';

import 'scan_fixtures.dart';

/// The same numbers as android/app/src/test/.../RegionMathTest.kt: the Dart
/// and Kotlin sides must agree to the pixel.
void main() {
  group('PageLayout.fromJson', () {
    test('parses the DESIGN §5.3 example', () {
      final layout = PageLayout.fromJson(sampleLayoutPage());

      expect(layout.assignmentId, 123);
      expect(layout.version, 2);
      expect(layout.page, 1);
      expect(layout.pageCount, 2);
      expect(layout.frameWidthMm, 178);
      expect(layout.frameHeightMm, 265);
      expect(layout.regions.map((r) => r.kind), [
        RegionKind.mcq,
        RegionKind.box,
        RegionKind.lines,
      ]);
      expect(layout.regions[0].bubbles.map((b) => b.option), [
        'A',
        'B',
        'C',
        'D',
      ]);
      expect(layout.regions[1].numeric, isTrue);
      expect(layout.regions[2].lineCount, 3);
      expect(layout.regions[2].finalAnswer!.numeric, isTrue);
      expect(layout.regions[2].finalCropId, 'q503_final');
      expect(layout.expectedCropIds, ['q501', 'q502', 'q503', 'q503_final']);
    });

    test('keeps the original JSON for the native side', () {
      final json = sampleLayoutPage();
      final layout = PageLayout.fromJson(json);
      expect(layout.json, same(json));
      expect(layout.encode(), contains('"frame_mm"'));
    });

    test('rejects unusable pages', () {
      Map<String, dynamic> broken(void Function(Map<String, dynamic>) edit) {
        final json = sampleLayoutPage();
        edit(json);
        return json;
      }

      for (final json in [
        broken((j) => j.remove('frame_mm')),
        broken((j) => j['frame_mm'] = {'x': 0, 'y': 0, 'w': 0, 'h': 265}),
        broken((j) => (j['regions'] as List).first['kind'] = 'circle'),
        broken((j) => (j['regions'] as List).first['bubbles'] = []),
        broken(
          (j) => (j['regions'] as List)[1]['rect'] = {
            'x': 0.1,
            'y': 0.1,
            'w': 0,
            'h': 0.1,
          },
        ),
        broken((j) => (j['regions'] as List)[1]['region_id'] = 'q501'),
        broken((j) => j['regions'] = 'nope'),
      ]) {
        expect(
          () => PageLayout.fromJson(json),
          throwsFormatException,
          reason: '$json',
        );
      }
    });
  });

  group('region math', () {
    test('the A4 frame is warped to 1402 x 2087 px at 200 DPI', () {
      expect(warpedSizeOf(178, 265), const PixelSize(1402, 2087));
      expect(
        PageLayout.fromJson(sampleLayoutPage()).warpedSize,
        const PixelSize(1402, 2087),
      );
    });

    test('crops grow by 2% of the frame', () {
      expect(
        cropRectOf(const NormRect(0.55, 0.27, 0.30, 0.05), 1402, 2087),
        const PixelRect(743, 521, 1220, 710),
      );
    });

    test('crops are clamped to the frame', () {
      expect(
        cropRectOf(const NormRect(0.005, 0.9013, 0.99, 0.2), 1000, 2000),
        const PixelRect(0, 1762, 1000, 2000),
      );
      expect(
        cropRectOf(const NormRect(1.5, 0.1, 0.2, 0.1), 1000, 2000).isEmpty,
        isTrue,
      );
    });

    test('a zero margin gives the printed box', () {
      final r = cropRectOf(
        const NormRect(0.25, 0.5, 0.5, 0.25),
        1000,
        2000,
        margin: 0,
      );
      expect(r, const PixelRect(250, 1000, 750, 1500));
      expect(r.width, 500);
      expect(r.height, 500);
    });

    test('bubble radius is scaled by the frame width', () {
      final c = bubbleCircleOf(
        const LayoutBubble(option: 'A', cx: 0.12, cy: 0.205, r: 0.0157),
        1402,
        2087,
      );
      expect(c.cx, closeTo(168.24, 1e-9));
      expect(c.cy, closeTo(427.835, 1e-9));
      expect(c.r, closeTo(22.0114, 1e-9));
    });

    test('the page image is about 1600 px on the long side', () {
      expect(
        scaleToLongSide(1402, 2087, warpedPageLongSide),
        const PixelSize(1075, 1600),
      );
      expect(scaleToLongSide(800, 600, 1600), const PixelSize(800, 600));
    });

    test('rounding matches Kotlin and Python (ties to even)', () {
      expect(roundHalfEven(2.5), 2);
      expect(roundHalfEven(3.5), 4);
      expect(roundHalfEven(1401.57), 1402);
      expect(roundHalfEven(-0.4), 0);
    });
  });
}
