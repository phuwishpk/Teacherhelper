import 'dart:io';
import 'dart:typed_data';

import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:path/path.dart' as p;

/// The layout page of DESIGN §5.3 (one mcq, one numeric box, one
/// show_work lines region with a numeric final answer).
Map<String, dynamic> sampleLayoutPage({
  int assignmentId = 123,
  int version = 2,
  int page = 1,
}) => {
  'assignment_id': assignmentId,
  'version': version,
  'page': page,
  'page_count': 2,
  'marker': {
    'dictionary': 'DICT_4X4_50',
    'ids': [0, 1, 2, 3],
    'size_mm': 12,
  },
  'frame_mm': {'x': 16, 'y': 16, 'w': 178, 'h': 265},
  'regions': [
    {
      'region_id': 'q501',
      'question_id': 501,
      'kind': 'mcq',
      'rect': {'x': 0.08, 'y': 0.18, 'w': 0.60, 'h': 0.05},
      'bubbles': [
        {'option': 'A', 'cx': 0.12, 'cy': 0.205, 'r': 0.012},
        {'option': 'B', 'cx': 0.24, 'cy': 0.205, 'r': 0.012},
        {'option': 'C', 'cx': 0.36, 'cy': 0.205, 'r': 0.012},
        {'option': 'D', 'cx': 0.48, 'cy': 0.205, 'r': 0.012},
      ],
    },
    {
      'region_id': 'q502',
      'question_id': 502,
      'kind': 'box',
      'numeric': true,
      'rect': {'x': 0.55, 'y': 0.27, 'w': 0.30, 'h': 0.05},
    },
    {
      'region_id': 'q503',
      'question_id': 503,
      'kind': 'lines',
      'rect': {'x': 0.06, 'y': 0.38, 'w': 0.88, 'h': 0.18},
      'line_count': 3,
      'final_answer': {
        'rect': {'x': 0.62, 'y': 0.58, 'w': 0.30, 'h': 0.05},
        'numeric': true,
      },
    },
  ],
};

const sampleQr = 'EV1.123.4567.1.2.K7Q3M2PA';

PageDetection goodDetection({
  String? qr = sampleQr,
  List<int> missing = const [],
  double blur = 182.4,
}) => PageDetection(
  qrPayload: qr,
  markerCorners: [
    for (var id = 0; id < 4; id++)
      ...(missing.contains(id)
          ? const <double?>[null, null]
          : <double?>[100.0 + id, 200.0 + id]),
  ],
  missingMarkerIds: missing,
  blurScore: blur,
);

/// Writes small files like the native pipeline would into [dir] and
/// returns the matching [PageCrops] for [sampleLayoutPage].
Future<PageCrops> writeSampleCrops(Directory dir) async {
  Future<String> file(String name) async {
    final f = File(p.join(dir.path, name));
    await f.create(recursive: true);
    await f.writeAsBytes([1, 2, 3]);
    return f.path;
  }

  return PageCrops(
    warpedPagePath: await file('page.webp'),
    regions: [
      RegionCrop(
        regionId: 'q501',
        imagePath: await file('q501.webp'),
        inkRatio: 0,
        bubbleFill: {'A': 0.04, 'B': 0.83, 'C': 0.06, 'D': 0.05},
      ),
      RegionCrop(
        regionId: 'q502',
        imagePath: await file('q502.webp'),
        inkRatio: 0.08,
        cnnInput: Uint8List(32 * 128),
      ),
      RegionCrop(
        regionId: 'q503',
        imagePath: await file('q503.webp'),
        inkRatio: 0.12,
      ),
      RegionCrop(
        regionId: 'q503_final',
        imagePath: await file('q503_final.webp'),
        inkRatio: 0.05,
        cnnInput: Uint8List(32 * 128),
      ),
    ],
  );
}

/// Scripted [ScanPipeline]: returns [detection] for every photo and writes
/// the sample crops into `[cropDir]/scan_pipeline/page<n>/`, the same folder
/// shape as ScanPipelineImpl.kt.
class FakeScanPipeline implements ScanPipeline {
  FakeScanPipeline({required this.cropDir, PageDetection? detection})
    : detection = detection ?? goodDetection();

  final Directory cropDir;
  PageDetection detection;

  /// Answered one per call before falling back to [detection] (e.g. the
  /// pages of a PDF).
  final nextDetections = <PageDetection>[];
  Object? detectError;
  Object? cropError;
  final detectCalls = <String>[];
  final cropCalls = <String>[];

  @override
  bool get isSupported => true;

  @override
  Future<PageDetection> detectPage(String imagePath) async {
    detectCalls.add(imagePath);
    if (detectError case final e?) throw e;
    return nextDetections.isEmpty ? detection : nextDetections.removeAt(0);
  }

  @override
  Future<PageCrops> cropPage(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) async {
    cropCalls.add(layoutJson);
    if (cropError case final e?) throw e;
    final dir = await Directory(
      p.join(cropDir.path, 'scan_pipeline', 'page${cropCalls.length}'),
    ).create(recursive: true);
    return writeSampleCrops(dir);
  }
}
