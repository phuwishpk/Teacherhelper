import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/exams/exam_scan_camera.dart';
import 'package:eduvision/features/exams/exam_scan_models.dart';
import 'package:eduvision/features/exams/exam_scan_repository.dart';
import 'package:eduvision/features/exams/exam_sheet_scanner.dart';
import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:eduvision/platform/answer_sheet_pipeline.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

const examId = 301;

/// A signed-looking answer-sheet QR of exam 301 (the phone never checks
/// the signature).
String sheetQr({int student = 11, int page = 1, int layout = 1}) =>
    'EVX1.$examId.$student.$page.$layout.Q2M7K3PA';

/// One layout page with three 4-option rows (s1–s3), a 2-column digit
/// block (s4) and, with [versions] > 1, the version bubbles.
Map<String, dynamic> layoutPage({
  int page = 1,
  int pageCount = 1,
  int versions = 2,
}) {
  List<Map<String, dynamic>> bubbles(int n) => [
    for (var i = 1; i <= n; i++)
      {'value': i, 'label': '$i', 'cx': 0.1 * i, 'cy': 0.3, 'r': 0.012},
  ];
  final first = (page - 1) * 4;
  return {
    'assignment_id': examId,
    'version': 1,
    'page': page,
    'page_count': pageCount,
    'sheet': 'exam',
    'frame_mm': {'x': 16, 'y': 16, 'w': 178, 'h': 265},
    'answer_area': {'x': 0.01, 'y': 0.12, 'w': 0.98, 'h': 0.84},
    'regions': [
      if (page == 1 && versions > 1)
        {
          'region_id': 'version',
          'kind': 'version_bubbles',
          'bubbles': bubbles(versions),
        },
      for (var s = first + 1; s <= first + 3; s++)
        {
          'region_id': 's$s',
          'kind': 'omr_row',
          'sheet_no': s,
          'bubbles': bubbles(4),
        },
      {
        'region_id': 's${first + 4}',
        'kind': 'digit_block',
        'sheet_no': first + 4,
        'sign': null,
        'columns': [
          for (var c = 1; c <= 2; c++)
            {
              'col': c,
              'bubbles': [
                for (final v in ['0', '1', '2', '3'])
                  {'value': v, 'cx': 0.5, 'cy': 0.7, 'r': 0.011},
              ],
            },
        ],
      },
    ],
  };
}

Map<String, dynamic> _key(int no, List<Object> accepted, {int page = 1}) {
  final first = (page - 1) * 4;
  return {
    'version_no': no,
    'label': no == 1 ? 'ก' : 'ข',
    'key': [
      for (var s = 1; s <= 3; s++)
        {
          'sheet_no': first + s,
          'question_id': 500 + first + s,
          'type': 'mcq',
          'points': 1,
          'accepted_options': [accepted[s - 1]],
        },
      {
        'sheet_no': first + 4,
        'question_id': 500 + first + 4,
        'type': 'numeric',
        'points': 2,
        'accepted_values': [accepted[3]],
      },
    ],
  };
}

/// The scan kit of exam 301: three students, [versions] versions (ก: 3 1 2
/// "12", ข: 1 2 3 "12"), [pageCount] pages of [layoutPage].
Map<String, dynamic> kitJson({
  int versions = 2,
  int pageCount = 1,
  int? layoutVersion = 1,
}) {
  final keys = <Map<String, dynamic>>[];
  for (var v = 1; v <= versions; v++) {
    final accepted = v == 1 ? <Object>[3, 1, 2, '12'] : <Object>[1, 2, 3, '12'];
    final merged = <Map<String, dynamic>>[];
    for (var p = 1; p <= pageCount; p++) {
      merged.addAll((_key(v, accepted, page: p)['key'] as List).cast());
    }
    keys.add({'version_no': v, 'label': v == 1 ? 'ก' : 'ข', 'key': merged});
  }
  return {
    'kit_hash': 'hash-$versions-$pageCount',
    'assignment_id': examId,
    'title': 'สอบกลางภาค',
    'layout_version': layoutVersion,
    'page_count': layoutVersion == null ? 0 : pageCount,
    'version_count': versions,
    'versions': keys,
    'layouts': layoutVersion == null
        ? []
        : [
            for (var p = 1; p <= pageCount; p++)
              layoutPage(page: p, pageCount: pageCount, versions: versions),
          ],
    'roster': [
      {'student_id': 11, 'student_number': 1, 'name': 'ด.ญ. หนึ่ง'},
      {'student_id': 12, 'student_number': 2, 'name': 'ด.ช. สอง'},
      {'student_id': 13, 'student_number': 3, 'name': 'ด.ญ. สาม'},
    ],
  };
}

ExamScanKit sampleKit({int versions = 2, int pageCount = 1}) =>
    ExamScanKit.fromJson(kitJson(versions: versions, pageCount: pageCount));

/// Readings as `readAnswerSheet` returns them: [marks] sheet_no -> option
/// (int) or the digits of the block (String); [version] marks a version bubble.
Map<String, dynamic> readingJson(
  String warped, {
  Map<int, Object> marks = const {},
  int? version,
  int page = 1,
  int versions = 2,
}) {
  final first = (page - 1) * 4;
  return {
    'warped_page_path': warped,
    'blur_score': 150.0,
    'baseline': 0.03,
    'version_fill': page == 1 && versions > 1
        ? {for (var v = 1; v <= versions; v++) '$v': v == version ? 0.9 : 0.0}
        : null,
    'rows': {
      for (var s = first + 1; s <= first + 3; s++)
        '$s': {for (var o = 1; o <= 4; o++) '$o': marks[s] == o ? 0.9 : 0.0},
    },
    'digits': {
      '${first + 4}': {
        'sign': null,
        'columns': [
          for (var c = 0; c < 2; c++)
            {
              for (final v in ['0', '1', '2', '3'])
                v:
                    (marks[first + 4] as String? ?? '').length > c &&
                        (marks[first + 4] as String)[c] == v
                    ? 0.9
                    : 0.0,
            },
        ],
      },
    },
  };
}

PageDetection detectionFor(
  String? qr, {
  double blur = 150,
  List<int> missing = const [],
}) => PageDetection(
  qrPayload: qr,
  markerCorners: [
    for (var i = 0; i < 8; i++) missing.contains(i ~/ 2) ? null : 10.0 * i,
  ],
  missingMarkerIds: missing,
  blurScore: blur,
);

/// Scripted pipeline. [readings] are answered in order (the last repeats);
/// each call writes a warped page file under [dir] when given.
class FakeAnswerSheetPipeline implements AnswerSheetPipeline {
  FakeAnswerSheetPipeline({this.dir, this.supported = true});

  final Directory? dir;
  final bool supported;
  PageDetection detection = detectionFor(sheetQr());
  final readings = <Map<String, dynamic> Function(String warped)>[];
  final frames = <FrameDetection>[];
  Object? readError;
  final layoutsRead = <String>[];
  int frameCalls = 0;

  @override
  bool get isSupported => supported;

  @override
  Future<PageDetection> detectPage(String imagePath) async => detection;

  @override
  Future<AnswerSheetReading> readAnswerSheet(
    String imagePath,
    PageDetection detection,
    String layoutJson,
  ) async {
    layoutsRead.add(layoutJson);
    if (readError case final e?) throw e;
    var warped = '/cache/scan_pipeline/x/page.webp';
    if (dir case final d?) {
      final f = File(
        '${d.path}/scan_pipeline/w${layoutsRead.length}/page.webp',
      );
      await f.create(recursive: true);
      await f.writeAsBytes([1, 2, 3]);
      warped = f.path;
    }
    final make = readings.length > 1 ? readings.removeAt(0) : readings.single;
    return AnswerSheetReading.fromJson(make(warped));
  }

  @override
  Future<FrameDetection> detectFrame(
    Uint8List yPlane,
    int width,
    int height,
    int bytesPerRow,
    int rotation,
  ) async {
    frameCalls++;
    return frames.isEmpty
        ? FrameDetection(markersFound: 0, blurScore: 0)
        : (frames.length > 1 ? frames.removeAt(0) : frames.single);
  }
}

FrameDetection goodFrame({int student = 11, int page = 1}) => FrameDetection(
  markersFound: 4,
  qrPayload: sheetQr(student: student, page: page),
  blurScore: 150,
);

class FakeFrameCamera implements FrameScanCamera {
  FakeFrameCamera({this.initError});

  final ScanCameraException? initError;
  void Function(CameraFrame frame)? onFrame;
  int shots = 0;
  bool disposed = false;
  bool torch = false;

  bool get streaming => onFrame != null;

  /// Sends one frame to the screen.
  void emit() => onFrame?.call(
    CameraFrame(
      yPlane: Uint8List(16),
      width: 4,
      height: 4,
      bytesPerRow: 4,
      rotation: 90,
    ),
  );

  @override
  Future<void> initialize() async {
    if (initError case final e?) throw e;
  }

  @override
  double get previewAspectRatio => 3 / 4;

  @override
  Widget buildPreview() =>
      const ColoredBox(key: Key('fake-preview'), color: Colors.grey);

  @override
  Future<String> takePicture() async => '/cache/shot${++shots}.jpg';

  @override
  Future<void> setTorch(bool on) async => torch = on;

  @override
  Future<void> startFrames(void Function(CameraFrame frame) onFrame) async =>
      this.onFrame = onFrame;

  @override
  Future<void> stopFrames() async => onFrame = null;

  @override
  Future<void> dispose() async => disposed = true;
}

class FakeExamScanRepository implements ExamScanRepository {
  Map<String, dynamic> kit = kitJson();
  Object? kitError;
  Map<String, dynamic> status = {
    'data': [],
    'summary': {'max_score': 5},
  };
  Object? statusError;
  KeySheetProposal Function(String qr, int? versionNo)? proposal;
  int kitCalls = 0;
  int statusCalls = 0;
  final keyReads = <({String qr, int? versionNo})>[];

  /// Thrown by keySheetRead when set.
  Object? keyReadError;

  /// `POST /exam-sheets/{id}/version` calls; [versionError] is thrown.
  final versionChoices = <({int scanId, int versionNo})>[];
  Object? versionError;

  @override
  Future<ExamScanKit> scanKit(int examId) async {
    kitCalls++;
    if (kitError case final e?) throw e;
    return ExamScanKit.fromJson(kit);
  }

  @override
  Future<ExamSheetStatus> sheetStatus(int examId) async {
    statusCalls++;
    if (statusError case final e?) throw e;
    return ExamSheetStatus.fromJson(status);
  }

  @override
  Future<KeySheetProposal> keySheetRead(
    int examId, {
    required String qr,
    required AnswerSheetReading reading,
    int? versionNo,
  }) async {
    keyReads.add((qr: qr, versionNo: versionNo));
    if (keyReadError case final e?) throw e;
    return proposal!(qr, versionNo);
  }

  @override
  Future<ExamSheetPageResult> chooseVersion(int scanId, int versionNo) async {
    versionChoices.add((scanId: scanId, versionNo: versionNo));
    if (versionError case final e?) throw e;
    return ExamSheetPageResult.fromJson({
      'scan_id': scanId,
      'submission_id': 70,
      'state': 'active',
      'page_no': 1,
      'page_count': 1,
      'version_no': versionNo,
      'score': 4,
      'max_score': 5,
      'doubts': [],
      'needs_version': false,
    });
  }
}

/// A DioException with the API error body of DESIGN §9.
DioException apiError(int status, String code, [String message = 'ผิดพลาด']) {
  final options = RequestOptions(path: '/x');
  return DioException(
    requestOptions: options,
    response: Response(
      requestOptions: options,
      statusCode: status,
      data: {'message': message, 'errors': {}, 'code': code},
    ),
    type: DioExceptionType.badResponse,
  );
}

DioException offlineError() => DioException(
  requestOptions: RequestOptions(path: '/x'),
  type: DioExceptionType.connectionError,
);

class FakeKitCache extends Fake implements ExamKitCache {
  final kits = <int, ({ExamScanKit kit, DateTime fetchedAt})>{};
  final removed = <int>[];

  @override
  Future<void> save(ExamScanKit kit) async => kits[kit.assignmentId] = (
    kit: kit,
    fetchedAt: DateTime.utc(2026, 10, 1, 1),
  );

  @override
  Future<({ExamScanKit kit, DateTime fetchedAt})?> load(int examId) async =>
      kits[examId];

  @override
  Future<void> remove(int examId) async {
    removed.add(examId);
    kits.remove(examId);
  }
}

/// Screen-level fake: canned outcomes, no file or database IO (the real
/// scanner is covered by exam_sheet_scanner_test.dart).
class FakeExamSheetScanner extends Fake implements ExamSheetScanner {
  FakeExamSheetScanner(this.fakePipeline);

  final FakeAnswerSheetPipeline fakePipeline;
  final outcomes = <ExamSheetOutcome Function(String path, bool acceptBlur)>[];
  final scanned = <String>[];
  final keyOutcomes = <KeySheetOutcome>[];
  final keyVersions = <int?>[];
  final pageOneAsked = <int?>[];

  @override
  bool get isSupported => fakePipeline.isSupported;

  @override
  AnswerSheetPipeline get pipeline => fakePipeline;

  @override
  Future<ExamSheetOutcome> scan(
    String imagePath,
    ExamScanKit kit, {
    required int? Function(int studentId) pageOneVersion,
    bool acceptBlur = false,
  }) async {
    scanned.add(imagePath);
    pageOneAsked.add(pageOneVersion(11));
    return outcomes.removeAt(0)(imagePath, acceptBlur);
  }

  @override
  Future<KeySheetOutcome> readKeySheet(
    String imagePath,
    int examId, {
    required Future<List<Map<String, dynamic>>> Function(int layoutVersion)
    layoutPages,
    required ExamScanRepository repository,
    int? versionNo,
  }) async {
    keyVersions.add(versionNo);
    return keyOutcomes.removeAt(0);
  }
}

/// A queued outcome for [student] page [page] with [score] of [max].
ExamSheetQueued queued({
  int student = 11,
  int page = 1,
  double? score = 4,
  double max = 5,
  int review = 0,
  int? version = 2,
  int pageCount = 1,
  DateTime? at,
}) {
  final kit = sampleKit(pageCount: pageCount);
  return ExamSheetQueued(
    clientScanId: 'id-$student-$page',
    qr: ExamQr.tryParse(sheetQr(student: student, page: page))!,
    student: kit.student(student),
    pageCount: pageCount,
    version: version == null
        ? const ExamVersionDecision.unknown()
        : ExamVersionDecision(version, 'bubble'),
    versionLabel: version == null ? null : (version == 1 ? 'ก' : 'ข'),
    score: score == null
        ? null
        : ExamPageScore(
            items: [
              for (var i = 0; i < review; i++)
                ExamItemScore(
                  sheetNo: i + 1,
                  selected: const [1, 2],
                  value: null,
                  score: 0,
                  max: 1,
                  doubts: const ['double_mark'],
                ),
            ],
            score: score,
            maxScore: max,
          ),
    scannedAt: at ?? DateTime.now(),
  );
}

/// Lets fake async work (futures started from callbacks) finish.
Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 5; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
  await tester.pumpAndSettle();
}
