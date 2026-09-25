import 'dart:convert';
import 'dart:math' as math;

/// Resolution of the warped marker frame (DESIGN §6.2 step 3).
const worksheetDpi = 200.0;
const mmPerInch = 25.4;

/// Crops grow by 2% of the frame on every side (DESIGN §5.3), so
/// handwriting that spills slightly over a box is not cut off.
const cropMargin = 0.02;

/// Long side of the uploaded page image (DESIGN §6.2 `warpedPagePath`).
const warpedPageLongSide = 1600;

/// Crop id of a `lines` region's final-answer box: `<region_id>_final`.
const finalCropSuffix = '_final';

/// `kind` of a layout region (DESIGN §5.3).
enum RegionKind {
  mcq('mcq'),
  box('box'),
  lines('lines');

  const RegionKind(this.jsonValue);

  final String jsonValue;

  static RegionKind parse(Object? value) => values.firstWhere(
    (k) => k.jsonValue == value,
    orElse: () => throw FormatException('unknown region kind $value'),
  );
}

/// Frame-relative rectangle, every value 0..1 of the marker frame.
class NormRect {
  const NormRect(this.x, this.y, this.w, this.h);

  factory NormRect.fromJson(Object? json) {
    if (json is! Map) throw const FormatException('rect must be an object');
    final r = NormRect(
      _num(json, 'x'),
      _num(json, 'y'),
      _num(json, 'w'),
      _num(json, 'h'),
    );
    if (r.w <= 0 || r.h <= 0) {
      throw const FormatException('rect must have a positive size');
    }
    return r;
  }

  final double x, y, w, h;
}

/// Half-open pixel rectangle: columns [left, right), rows [top, bottom).
class PixelRect {
  const PixelRect(this.left, this.top, this.right, this.bottom);

  final int left, top, right, bottom;

  int get width => right - left;
  int get height => bottom - top;
  bool get isEmpty => width <= 0 || height <= 0;

  @override
  bool operator ==(Object other) =>
      other is PixelRect &&
      other.left == left &&
      other.top == top &&
      other.right == right &&
      other.bottom == bottom;

  @override
  int get hashCode => Object.hash(left, top, right, bottom);

  @override
  String toString() => 'PixelRect($left, $top, $right, $bottom)';
}

class PixelSize {
  const PixelSize(this.width, this.height);

  final int width, height;

  @override
  bool operator ==(Object other) =>
      other is PixelSize && other.width == width && other.height == height;

  @override
  int get hashCode => Object.hash(width, height);

  @override
  String toString() => 'PixelSize($width, $height)';
}

class PixelCircle {
  const PixelCircle(this.cx, this.cy, this.r);

  final double cx, cy, r;
}

class LayoutBubble {
  const LayoutBubble({
    required this.option,
    required this.cx,
    required this.cy,
    required this.r,
  });

  factory LayoutBubble.fromJson(Map<String, dynamic> json) => LayoutBubble(
    option: json['option'] as String,
    cx: _num(json, 'cx'),
    cy: _num(json, 'cy'),
    r: _num(json, 'r'),
  );

  final String option;

  /// Centre (frame-relative) and radius (share of the frame WIDTH).
  final double cx, cy, r;
}

class FinalAnswerBox {
  const FinalAnswerBox({required this.rect, required this.numeric});

  final NormRect rect;
  final bool numeric;
}

/// One answer region of a layout page (DESIGN §5.3).
class LayoutRegion {
  const LayoutRegion({
    required this.regionId,
    required this.questionId,
    required this.kind,
    required this.rect,
    this.numeric = false,
    this.bubbles = const [],
    this.lineCount,
    this.finalAnswer,
  });

  factory LayoutRegion.fromJson(Map<String, dynamic> json) {
    final kind = RegionKind.parse(json['kind']);
    final fa = json['final_answer'];
    final region = LayoutRegion(
      regionId: json['region_id'] as String,
      questionId: (json['question_id'] as num).toInt(),
      kind: kind,
      rect: NormRect.fromJson(json['rect']),
      numeric: json['numeric'] == true,
      bubbles: [
        for (final b in (json['bubbles'] as List?) ?? const [])
          LayoutBubble.fromJson((b as Map).cast<String, dynamic>()),
      ],
      lineCount: (json['line_count'] as num?)?.toInt(),
      finalAnswer: fa is Map
          ? FinalAnswerBox(
              rect: NormRect.fromJson(fa['rect']),
              numeric: fa['numeric'] == true,
            )
          : null,
    );
    if (kind == RegionKind.mcq && region.bubbles.isEmpty) {
      throw FormatException('mcq region ${region.regionId} has no bubbles');
    }
    return region;
  }

  final String regionId;
  final int questionId;
  final RegionKind kind;
  final NormRect rect;

  /// `box` only: the digit reader reads this box too.
  final bool numeric;
  final List<LayoutBubble> bubbles;
  final int? lineCount;

  /// `lines` of a show_work question: the separate final-answer box.
  final FinalAnswerBox? finalAnswer;

  /// Crop id of the final-answer box, when there is one.
  String? get finalCropId =>
      finalAnswer == null ? null : '$regionId$finalCropSuffix';

  /// Ids of every crop the pipeline returns for this region.
  List<String> get cropIds => [regionId, ?finalCropId];
}

/// One page of a layout version (DESIGN §5.3), as cached in drift.
class PageLayout {
  const PageLayout({
    required this.assignmentId,
    required this.version,
    required this.page,
    required this.pageCount,
    required this.frameWidthMm,
    required this.frameHeightMm,
    required this.regions,
    required this.json,
  });

  /// Throws [FormatException] when the page is not usable for cropping.
  factory PageLayout.fromJson(Map<String, dynamic> json) {
    try {
      final frame = json['frame_mm'];
      if (frame is! Map) throw const FormatException('frame_mm missing');
      final regions = [
        for (final r in (json['regions'] as List))
          LayoutRegion.fromJson((r as Map).cast<String, dynamic>()),
      ];
      final ids = <String>{};
      for (final r in regions) {
        if (!ids.add(r.regionId)) {
          throw FormatException('duplicate region_id ${r.regionId}');
        }
      }
      final layout = PageLayout(
        assignmentId: (json['assignment_id'] as num).toInt(),
        version: (json['version'] as num).toInt(),
        page: (json['page'] as num).toInt(),
        pageCount: (json['page_count'] as num?)?.toInt() ?? 1,
        frameWidthMm: _num(frame, 'w'),
        frameHeightMm: _num(frame, 'h'),
        regions: regions,
        json: json,
      );
      if (layout.frameWidthMm <= 0 || layout.frameHeightMm <= 0) {
        throw const FormatException('frame_mm must be positive');
      }
      return layout;
    } on TypeError catch (e) {
      throw FormatException('layout page is malformed: $e');
    }
  }

  final int assignmentId;
  final int version;
  final int page;
  final int pageCount;
  final double frameWidthMm;
  final double frameHeightMm;
  final List<LayoutRegion> regions;

  /// The page exactly as received; handed to the native side unchanged.
  final Map<String, dynamic> json;

  String encode() => jsonEncode(json);

  PixelSize get warpedSize => warpedSizeOf(frameWidthMm, frameHeightMm);

  /// Every crop id the native pipeline must return for this page.
  List<String> get expectedCropIds => [for (final r in regions) ...r.cropIds];
}

// ---------------------------------------------------------------------------
// Region math. The Kotlin twin is RegionMath.kt; both must agree to the pixel
// (see RegionMathTest.kt and test/scan/page_layout_test.dart).

/// Pixel size of a frame of [widthMm] x [heightMm] at [dpi].
PixelSize warpedSizeOf(
  double widthMm,
  double heightMm, {
  double dpi = worksheetDpi,
}) => PixelSize(
  roundHalfEven(widthMm / mmPerInch * dpi),
  roundHalfEven(heightMm / mmPerInch * dpi),
);

/// Crop rectangle of [rect] on a [width] x [height] warped frame, grown by
/// [margin] (share of the frame) and clamped to the frame.
PixelRect cropRectOf(
  NormRect rect,
  int width,
  int height, {
  double margin = cropMargin,
}) {
  final left = ((rect.x - margin) * width).floor().clamp(0, width);
  final top = ((rect.y - margin) * height).floor().clamp(0, height);
  final right = ((rect.x + rect.w + margin) * width).ceil().clamp(0, width);
  final bottom = ((rect.y + rect.h + margin) * height).ceil().clamp(0, height);
  return PixelRect(left, top, math.max(left, right), math.max(top, bottom));
}

/// Bubble centre and radius in warped pixels (radius by frame width).
PixelCircle bubbleCircleOf(LayoutBubble b, int width, int height) =>
    PixelCircle(b.cx * width, b.cy * height, b.r * width);

/// Size with the long side at most [longSide], keeping the aspect ratio.
PixelSize scaleToLongSide(int width, int height, int longSide) {
  final long = math.max(width, height);
  if (long <= longSide) return PixelSize(width, height);
  final s = longSide / long;
  return PixelSize(
    math.max(1, roundHalfEven(width * s)),
    math.max(1, roundHalfEven(height * s)),
  );
}

/// Rounds like Kotlin's `round()` and Python's `round()` (ties to even), so
/// the Dart and Kotlin sides compute identical sizes.
int roundHalfEven(double v) {
  final f = v.floorToDouble();
  final diff = v - f;
  final i = f.toInt();
  if (diff > 0.5) return i + 1;
  if (diff < 0.5) return i;
  return i.isEven ? i : i + 1;
}

double _num(Map<dynamic, dynamic> json, String key) {
  final v = json[key];
  if (v is num) return v.toDouble();
  throw FormatException('$key must be a number');
}
