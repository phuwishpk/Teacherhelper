import 'dart:math' as math;

import '../../platform/scan_pipeline.dart';
import 'page_layout.dart';

/// Multipart field of the warped page image (DESIGN §9.4).
const pageFileField = 'page';

/// Local-only field of the untouched photo of a `needs_layout` scan. Such a
/// row is never uploaded; it is replaced by the page and crops once the
/// layout is available.
const rawFileField = 'raw';

/// Multipart field (and meta `file` / `final_file` value) of a crop.
String cropFileField(String cropId) => 'crop_$cropId';

/// Where a scanned page came from (DESIGN §18.2, §18.6).
class ScanSource {
  /// The teacher's camera. `meta` carries no `source` (the server default).
  const ScanSource.camera() : googleSubmissionId = null;

  /// An attachment of a Google Classroom submission.
  const ScanSource.classroom({required String this.googleSubmissionId});

  /// Reads the source back from a queued scan's `meta`.
  factory ScanSource.fromMeta(Map<String, dynamic> meta) {
    final id = meta['google_submission_id'];
    if (meta['source'] == 'classroom' && id is String && id.isNotEmpty) {
      return ScanSource.classroom(googleSubmissionId: id);
    }
    return const ScanSource.camera();
  }

  final String? googleSubmissionId;

  bool get isClassroom => googleSubmissionId != null;

  /// The anonymous spare worksheet (`student_id = 0`) is accepted only with
  /// a Classroom submission, which tells the server who sent it (§18.3).
  bool get allowsSpareWorksheet => isClassroom;

  /// Extra `meta` fields of `POST /scans` (§18.6).
  Map<String, Object?> toMeta() => isClassroom
      ? {'source': 'classroom', 'google_submission_id': googleSubmissionId}
      : const {};

  @override
  bool operator ==(Object other) =>
      other is ScanSource && other.googleSubmissionId == googleSubmissionId;

  @override
  int get hashCode => googleSubmissionId.hashCode;

  @override
  String toString() => isClassroom
      ? 'ScanSource.classroom($googleSubmissionId)'
      : 'ScanSource.camera()';
}

/// A digit-reader result attached as `cnn` (DESIGN §9.4, §12).
class CnnReading {
  const CnnReading({required this.text, required this.confidence});

  final String text;
  final double confidence;

  Map<String, dynamic> toJson() => {
    'text': text,
    'confidence': _round(confidence, 3),
  };
}

/// `meta` and the files of one `POST /scans` (DESIGN §9.4).
class ScanUpload {
  const ScanUpload({required this.meta, required this.files});

  final Map<String, dynamic> meta;

  /// Multipart field -> local path.
  final Map<String, String> files;
}

/// The pipeline did not return a crop the layout asks for.
class MissingCropException implements Exception {
  const MissingCropException(this.cropId);

  final String cropId;

  @override
  String toString() => 'MissingCropException($cropId)';
}

/// Builds the upload of one cropped page. Regions follow the layout order;
/// `mcq` regions carry `mcq_fill`, the others `ink_ratio`, a `lines` region
/// with a final-answer box also `final_file`, and numeric boxes the digit
/// reader's answer in `cnn` when [cnn] has one (keyed by crop id).
ScanUpload buildScanUpload({
  required String clientScanId,
  required String qrPayload,
  required DateTime scannedAt,
  required double blurScore,
  required PageLayout layout,
  required PageCrops crops,
  Map<String, CnnReading> cnn = const {},
  Map<String, Object?> extraMeta = const {},
}) {
  final byId = {for (final c in crops.regions) c.regionId: c};
  RegionCrop crop(String id) => byId[id] ?? (throw MissingCropException(id));

  final files = <String, String>{pageFileField: crops.warpedPagePath};
  final regions = <Map<String, dynamic>>[];

  for (final region in layout.regions) {
    final main = crop(region.regionId);
    final field = cropFileField(region.regionId);
    files[field] = main.imagePath;
    final entry = <String, dynamic>{
      'region_id': region.regionId,
      'question_id': region.questionId,
      'file': field,
    };
    switch (region.kind) {
      case RegionKind.mcq:
        final fill = main.bubbleFill ?? const <String, double>{};
        entry['mcq_fill'] = {
          for (final b in region.bubbles)
            b.option: _round(fill[b.option] ?? 0, 3),
        };
      case RegionKind.box:
        entry['ink_ratio'] = _round(main.inkRatio, 4);
        if (cnn[region.regionId] case final reading?) {
          entry['cnn'] = reading.toJson();
        }
      case RegionKind.lines:
        entry['ink_ratio'] = _round(main.inkRatio, 4);
        if (region.finalCropId case final finalId?) {
          final finalCrop = crop(finalId);
          final finalField = cropFileField(finalId);
          files[finalField] = finalCrop.imagePath;
          entry['final_file'] = finalField;
          if (cnn[finalId] case final reading?) {
            entry['cnn'] = reading.toJson();
          }
        }
    }
    regions.add(entry);
  }

  return ScanUpload(
    meta: {
      ...scanMetaHeader(
        clientScanId: clientScanId,
        qrPayload: qrPayload,
        scannedAt: scannedAt,
        blurScore: blurScore,
      ),
      'regions': regions,
      ...extraMeta,
    },
    files: files,
  );
}

/// The fields every scan has, including a `needs_layout` one.
Map<String, dynamic> scanMetaHeader({
  required String clientScanId,
  required String qrPayload,
  required DateTime scannedAt,
  required double blurScore,
}) => {
  'client_scan_id': clientScanId,
  'qr': qrPayload,
  // Stored and sent in UTC; the server and the app display Asia/Bangkok.
  'scanned_at': scannedAt.toUtc().toIso8601String(),
  'blur_score': _round(blurScore, 1),
};

double _round(double v, int digits) {
  if (!v.isFinite) return 0;
  final f = math.pow(10, digits);
  return (v * f).round() / f;
}
