import 'dart:io';

import 'package:eduvision/features/scan/page_layout.dart';
import 'package:eduvision/features/scan/scan_meta.dart';
import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:flutter_test/flutter_test.dart';

import 'scan_fixtures.dart';

void main() {
  late Directory dir;
  late PageCrops crops;
  final layout = PageLayout.fromJson(sampleLayoutPage());

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('scan_meta_test');
    crops = await writeSampleCrops(dir);
  });

  tearDown(() => dir.delete(recursive: true));

  ScanUpload build({Map<String, CnnReading> cnn = const {}}) => buildScanUpload(
    clientScanId: '8f2c1e0a-5b7d-4c3e-9a61-2f0d4b6c8e11',
    qrPayload: sampleQr,
    scannedAt: DateTime.parse('2026-10-01T09:15:00+07:00'),
    blurScore: 182.44,
    layout: layout,
    crops: crops,
    cnn: cnn,
  );

  test('meta has the DESIGN §9.4 shape', () {
    final upload = build(
      cnn: const {
        'q502': CnnReading(text: '125', confidence: 0.9712),
        'q503_final': CnnReading(text: '5', confidence: 0.91),
      },
    );

    expect(upload.meta, {
      'client_scan_id': '8f2c1e0a-5b7d-4c3e-9a61-2f0d4b6c8e11',
      'qr': sampleQr,
      'scanned_at': '2026-10-01T02:15:00.000Z',
      'blur_score': 182.4,
      'regions': [
        {
          'region_id': 'q501',
          'question_id': 501,
          'file': 'crop_q501',
          'mcq_fill': {'A': 0.04, 'B': 0.83, 'C': 0.06, 'D': 0.05},
        },
        {
          'region_id': 'q502',
          'question_id': 502,
          'file': 'crop_q502',
          'ink_ratio': 0.08,
          'cnn': {'text': '125', 'confidence': 0.971},
        },
        {
          'region_id': 'q503',
          'question_id': 503,
          'file': 'crop_q503',
          'ink_ratio': 0.12,
          'final_file': 'crop_q503_final',
          'cnn': {'text': '5', 'confidence': 0.91},
        },
      ],
    });
  });

  test('an abstained reading is an empty cnn; no reading, no cnn', () {
    final upload = build(cnn: const {'q503_final': CnnReading.abstained()});
    final regions = (upload.meta['regions'] as List).cast<Map>();
    expect(regions[1].containsKey('cnn'), isFalse, reason: 'no model');
    expect(regions[2]['cnn'], <String, dynamic>{});
  });

  test('every file named in meta is attached, plus the page', () {
    final upload = build();
    expect(upload.files.keys, [
      'page',
      'crop_q501',
      'crop_q502',
      'crop_q503',
      'crop_q503_final',
    ]);
    expect(upload.files['page'], crops.warpedPagePath);
    expect(upload.files['crop_q503_final'], endsWith('q503_final.webp'));

    final regions = upload.meta['regions'] as List;
    for (final r in regions.cast<Map<String, dynamic>>()) {
      expect(upload.files, contains(r['file']));
      if (r['final_file'] case final String f) {
        expect(upload.files, contains(f));
      }
    }
  });

  test('without digit readings there is no cnn key', () {
    final regions = (build().meta['regions'] as List)
        .cast<Map<String, dynamic>>();
    expect(regions.any((r) => r.containsKey('cnn')), isFalse);
  });

  test('bubbles missing from the fill map are sent as 0', () {
    crops.regions.first.bubbleFill = {'B': 0.9};
    final mcq = (build().meta['regions'] as List).first as Map;
    expect(mcq['mcq_fill'], {'A': 0.0, 'B': 0.9, 'C': 0.0, 'D': 0.0});
  });

  test('a crop the layout needs but the pipeline did not return throws', () {
    crops.regions.removeWhere((c) => c.regionId == 'q503_final');
    expect(
      build,
      throwsA(
        isA<MissingCropException>().having((e) => e.cropId, 'id', 'q503_final'),
      ),
    );
  });

  test('extra meta is appended (e.g. source = classroom, DESIGN §18.2)', () {
    final upload = buildScanUpload(
      clientScanId: 'id',
      qrPayload: sampleQr,
      scannedAt: DateTime.utc(2026),
      blurScore: double.nan,
      layout: layout,
      crops: crops,
      extraMeta: const {'source': 'classroom', 'google_submission_id': 'abc'},
    );
    expect(upload.meta['source'], 'classroom');
    expect(upload.meta['google_submission_id'], 'abc');
    expect(upload.meta['blur_score'], 0);
  });

  test('the header of a needs_layout scan', () {
    expect(
      scanMetaHeader(
        clientScanId: 'id',
        qrPayload: sampleQr,
        scannedAt: DateTime.utc(2026, 10, 1, 2, 15),
        blurScore: 99.96,
      ),
      {
        'client_scan_id': 'id',
        'qr': sampleQr,
        'scanned_at': '2026-10-01T02:15:00.000Z',
        'blur_score': 100.0,
      },
    );
    expect(cropFileField('q9_final'), 'crop_q9_final');
  });
  group('ScanSource', () {
    test('camera adds nothing to meta', () {
      const camera = ScanSource.camera();
      expect(camera.isClassroom, isFalse);
      expect(camera.allowsSpareWorksheet, isFalse);
      expect(camera.toMeta(), isEmpty);
      expect(ScanSource.fromMeta(const {'qr': sampleQr}), camera);
    });

    test('classroom adds source and google_submission_id (DESIGN §18.6)', () {
      const classroom = ScanSource.classroom(googleSubmissionId: 'sub-9');
      expect(classroom.allowsSpareWorksheet, isTrue);
      expect(classroom.toMeta(), {
        'source': 'classroom',
        'google_submission_id': 'sub-9',
      });
      expect(ScanSource.fromMeta(classroom.toMeta()), classroom);
      // Incomplete meta falls back to the camera.
      expect(
        ScanSource.fromMeta(const {'source': 'classroom'}),
        const ScanSource.camera(),
      );
    });
  });
}
