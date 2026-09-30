import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'pigeons/scan_api.g.dart';
import 'scan_pipeline.dart';

export 'pigeons/scan_api.g.dart' show FrameDetection, PageDetection;

/// Fill of a digit block (DESIGN §22.9): the sign bubble (null when not
/// printed) and one `{value: fill}` map per column.
class DigitFill {
  const DigitFill({required this.sign, required this.columns});

  final double? sign;
  final List<Map<String, double>> columns;

  factory DigitFill.fromJson(Map<String, dynamic> json) => DigitFill(
    sign: (json['sign'] as num?)?.toDouble(),
    columns: [
      for (final c in (json['columns'] as List? ?? const []))
        _fill(c as Map<String, dynamic>),
    ],
  );

  Map<String, Object?> toJson() => {'sign': sign, 'columns': columns};
}

/// What `readAnswerSheet` measured on one answer-sheet page, after the page
/// baseline (DESIGN §22.9): the warped page and every bubble's fill.
class AnswerSheetReading {
  const AnswerSheetReading({
    required this.warpedPagePath,
    required this.blurScore,
    required this.baseline,
    required this.versionFill,
    required this.rows,
    required this.digits,
  });

  /// WebP of the warped page (cache directory), uploaded as `page`.
  final String warpedPagePath;
  final double blurScore;
  final double baseline;

  /// `{"1": 0.03, "2": 0.91}` on page 1 of a multi-version exam, else null.
  final Map<String, double>? versionFill;

  /// Sheet number -> displayed position ("1".."6") -> fill.
  final Map<int, Map<String, double>> rows;

  /// Sheet number -> digit block fill.
  final Map<int, DigitFill> digits;

  factory AnswerSheetReading.fromJson(Map<String, dynamic> json) =>
      AnswerSheetReading(
        warpedPagePath: json['warped_page_path'] as String,
        blurScore: (json['blur_score'] as num?)?.toDouble() ?? 0,
        baseline: (json['baseline'] as num?)?.toDouble() ?? 0,
        versionFill: json['version_fill'] == null
            ? null
            : _fill(json['version_fill'] as Map<String, dynamic>),
        rows: {
          for (final e
              in (json['rows'] as Map<String, dynamic>? ?? const {}).entries)
            int.parse(e.key): _fill(e.value as Map<String, dynamic>),
        },
        digits: {
          for (final e
              in (json['digits'] as Map<String, dynamic>? ?? const {}).entries)
            int.parse(e.key): DigitFill.fromJson(
              e.value as Map<String, dynamic>,
            ),
        },
      );

  /// The reading fields of `POST /exam-sheets` and `key-sheet-read`.
  Map<String, Object?> toApiJson() => {
    if (versionFill != null) 'version_fill': versionFill,
    'rows': {for (final e in rows.entries) '${e.key}': e.value},
    'digits': {for (final e in digits.entries) '${e.key}': e.value.toJson()},
  };
}

Map<String, double> _fill(Map<String, dynamic> json) => {
  for (final e in json.entries) e.key: (e.value as num).toDouble(),
};

/// The native answer-sheet reader (DESIGN §22.9, §22.10) behind an
/// interface, so the scan screens and tests do not depend on the platform
/// channel. Android only; the web preview has no native pipeline.
abstract class AnswerSheetPipeline {
  bool get isSupported;

  /// Markers, QR and blur of a full photo (the worksheet `detectPage`).
  Future<PageDetection> detectPage(String imagePath);

  /// Warps the page and measures every bubble of [layoutJson] (one page).
  Future<AnswerSheetReading> readAnswerSheet(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  );

  /// Markers, QR and blur of one camera frame (Y plane).
  Future<FrameDetection> detectFrame(
    Uint8List yPlane,
    int width,
    int height,
    int bytesPerRow,
    int rotation,
  );
}

class NativeAnswerSheetPipeline implements AnswerSheetPipeline {
  NativeAnswerSheetPipeline([ScanPipelineApi? api])
    : _api = api ?? ScanPipelineApi();

  final ScanPipelineApi _api;

  @override
  bool get isSupported => true;

  @override
  Future<PageDetection> detectPage(String imagePath) =>
      _guard(() => _api.detectPage(imagePath));

  @override
  Future<AnswerSheetReading> readAnswerSheet(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) => _guard(() async {
    final json = await _api.readAnswerSheet(imagePath, detection, layoutJson);
    return AnswerSheetReading.fromJson(
      jsonDecode(json) as Map<String, dynamic>,
    );
  });

  @override
  Future<FrameDetection> detectFrame(
    Uint8List yPlane,
    int width,
    int height,
    int bytesPerRow,
    int rotation,
  ) => _guard(
    () => _api.detectFrame(yPlane, width, height, bytesPerRow, rotation),
  );

  Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on PlatformException catch (e) {
      throw ScanPipelineException(e.code, e.message);
    }
  }
}

class UnsupportedAnswerSheetPipeline implements AnswerSheetPipeline {
  const UnsupportedAnswerSheetPipeline();

  static const _error = ScanPipelineException('unsupported');

  @override
  bool get isSupported => false;

  @override
  Future<PageDetection> detectPage(String imagePath) => Future.error(_error);

  @override
  Future<AnswerSheetReading> readAnswerSheet(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) => Future.error(_error);

  @override
  Future<FrameDetection> detectFrame(
    Uint8List yPlane,
    int width,
    int height,
    int bytesPerRow,
    int rotation,
  ) => Future.error(_error);
}

final answerSheetPipelineProvider = Provider<AnswerSheetPipeline>(
  (ref) => !kIsWeb && Platform.isAndroid
      ? NativeAnswerSheetPipeline()
      : const UnsupportedAnswerSheetPipeline(),
);
