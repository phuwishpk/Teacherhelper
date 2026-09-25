// Pigeon input for the native scan pipeline (DESIGN §6.2).
//
// This file lives outside lib/ because it imports the dev-only `pigeon`
// package. Regenerate the Dart and Kotlin bindings after editing it:
//
//   cd app && dart run pigeon --input pigeons/scan.dart \
//     && dart format lib/platform/pigeons
//
// Generated files (committed, do not edit by hand):
//   lib/platform/pigeons/scan_api.g.dart
//   android/app/src/main/kotlin/com/eduvision/eduvision/scan/ScanApi.g.kt
import 'package:pigeon/pigeon.dart';

@ConfigurePigeon(
  PigeonOptions(
    dartOut: 'lib/platform/pigeons/scan_api.g.dart',
    dartOptions: DartOptions(),
    kotlinOut:
        'android/app/src/main/kotlin/com/eduvision/eduvision/scan/ScanApi.g.kt',
    kotlinOptions: KotlinOptions(package: 'com.eduvision.eduvision.scan'),
    dartPackageName: 'eduvision',
  ),
)
/// Result of looking at a full photo of a worksheet page.
class PageDetection {
  PageDetection({
    required this.markerCorners,
    required this.missingMarkerIds,
    required this.blurScore,
  });

  /// Raw QR text, or null when no QR could be read.
  String? qrPayload;

  /// Centres of the ArUco markers id 0..3 (top-left, top-right,
  /// bottom-right, bottom-left) in pixel coordinates of the photo as decoded
  /// with its EXIF orientation applied: [x0, y0, x1, y1, x2, y2, x3, y3].
  /// Both values of a marker that was not found are null.
  List<double?> markerCorners;

  /// Ids (0..3) of the markers that were not found, so the UI can say which
  /// corner is out of the frame.
  List<int> missingMarkerIds;

  /// Variance of the Laplacian. Measured on the marker frame warped to
  /// 200 DPI when all four markers were found, otherwise on the downscaled
  /// photo. Higher is sharper.
  double blurScore;
}

/// One crop cut from the warped page.
class RegionCrop {
  RegionCrop({
    required this.regionId,
    required this.imagePath,
    required this.inkRatio,
  });

  /// `region_id` from the layout JSON. The final-answer box of a `lines`
  /// region comes back as its own crop with the id `<region_id>_final`.
  String regionId;

  /// WebP (quality 80) in the app cache directory.
  String imagePath;

  /// Share of handwriting pixels inside the rect, with printed borders and
  /// ruling lines removed (0..1). 0 for `mcq` regions.
  double inkRatio;

  /// `mcq` only: option -> share of dark pixels inside 70% of the bubble
  /// radius (Otsu threshold), e.g. {"A": 0.04, "B": 0.83}.
  Map<String, double>? bubbleFill;

  /// Numeric boxes only: 32 x 128 grayscale bytes (row-major, paper = 255,
  /// ink dark) prepared as in ml/train/preprocess.py (tight crop + fit to
  /// canvas); the Dart side normalises and runs the digit reader.
  Uint8List? cnnInput;
}

/// Everything cut from one page.
class PageCrops {
  PageCrops({required this.warpedPagePath, required this.regions});

  /// The marker frame warped flat, WebP about 1600 px on the long side.
  String warpedPagePath;
  List<RegionCrop> regions;
}

@HostApi()
abstract class ScanPipelineApi {
  /// Finds the markers, reads the QR and measures blur on a full photo.
  @async
  PageDetection detectPage(String imagePath);

  /// Warps the marker frame to ~200 DPI and crops every region of
  /// [layoutJson] (one page of the layout JSON, DESIGN §5.3).
  @async
  PageCrops cropPage(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  );
}
