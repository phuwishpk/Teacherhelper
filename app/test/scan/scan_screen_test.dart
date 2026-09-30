import 'dart:typed_data';

import 'package:drift/drift.dart' show DatabaseConnection;
import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/core/db/database_provider.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/hand_in/teacher_upload_screen.dart';
import 'package:eduvision/features/scan/page_layout.dart';
import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:eduvision/features/scan/scan_file_picks.dart';
import 'package:eduvision/features/scan/scan_meta.dart';
import 'package:eduvision/features/scan/scan_processor.dart';
import 'package:eduvision/features/scan/scan_quality.dart';
import 'package:eduvision/features/scan/scan_screen.dart';
import 'package:eduvision/features/upload_queue/queued_scan.dart';
import 'package:eduvision/platform/scan_pipeline.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/pump_screen.dart';
import 'scan_fixtures.dart';

class FakeCamera implements ScanCamera {
  FakeCamera({this.initError});

  final ScanCameraException? initError;
  int shots = 0;
  bool torch = false;
  bool disposed = false;

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
  Future<void> dispose() async => disposed = true;
}

/// Screen-level fake: canned analyses, no file or network IO (the real
/// processor is covered by scan_processor_test.dart).
class FakeProcessor extends Fake implements ScanProcessor {
  FakeProcessor({this.supported = true});

  final bool supported;
  final results = <ScanAnalysis Function(String path)>[];
  final analyzed = <String>[];
  final confirmed = <ScanReady>[];
  final kept = <ScanNeedsLayout>[];
  final discarded = <ScanAnalysis>[];
  int needsLayoutRuns = 0;
  ScanAnalysis Function(ScanRejected)? onAcceptBlur;

  @override
  bool get isSupported => supported;

  @override
  Future<ScanAnalysis> analyze(
    String imagePath, {
    ScanSource source = const ScanSource.camera(),
  }) async {
    expect(source, const ScanSource.camera(), reason: 'the camera screen');
    analyzed.add(imagePath);
    return results.removeAt(0)(imagePath);
  }

  @override
  Future<ScanAnalysis> acceptDespiteBlur(ScanRejected rejected) async =>
      onAcceptBlur!(rejected);

  @override
  Future<String> confirm(ScanReady ready) async {
    confirmed.add(ready);
    return 'id-${confirmed.length}';
  }

  @override
  Future<String> keepForLater(ScanNeedsLayout scan) async {
    kept.add(scan);
    return 'kept-${kept.length}';
  }

  @override
  Future<void> discard(ScanAnalysis analysis) async => discarded.add(analysis);

  @override
  Future<NeedsLayoutRun> processNeedsLayout() async {
    needsLayoutRuns++;
    return const NeedsLayoutRun(processed: 0, waiting: 0, failed: 0);
  }
}

final _qr = WorksheetQr.tryParse(sampleQr)!;
final _layout = PageLayout.fromJson(sampleLayoutPage());
final _capturedAt = DateTime.utc(2026, 10, 1, 2, 15);

PageCrops _crops() => PageCrops(
  warpedPagePath: '/cache/p/page.webp',
  regions: [
    RegionCrop(
      regionId: 'q501',
      imagePath: '/cache/p/q501.webp',
      inkRatio: 0,
      bubbleFill: {'A': 0.04, 'B': 0.83, 'C': 0.06, 'D': 0.05},
    ),
    RegionCrop(regionId: 'q502', imagePath: '/cache/p/q502.webp', inkRatio: 0),
    RegionCrop(
      regionId: 'q503',
      imagePath: '/cache/p/q503.webp',
      inkRatio: 0.12,
    ),
    RegionCrop(
      regionId: 'q503_final',
      imagePath: '/cache/p/q503_final.webp',
      inkRatio: 0.05,
    ),
  ],
);

ScanReady _ready(String path, {bool alreadyQueued = false}) => ScanReady(
  imagePath: path,
  capturedAt: _capturedAt,
  detection: goodDetection(),
  qr: _qr,
  layout: _layout,
  crops: _crops(),
  student: const RosterStudent(
    studentId: 4567,
    studentNumber: 12,
    name: 'ด.ญ. สมหญิง',
  ),
  alreadyQueued: alreadyQueued,
);

void main() {
  late AppDatabase db;
  late FakeCamera camera;
  late FakeProcessor processor;

  setUp(() {
    // See upload_queue_screen_test.dart: no zero-length drift timer left in
    // the FakeAsync zone when a query stream is cancelled.
    db = AppDatabase(
      DatabaseConnection(
        NativeDatabase.memory(),
        closeStreamsSynchronously: true,
      ),
    );
    camera = FakeCamera();
    processor = FakeProcessor();
  });

  tearDown(() => db.close());

  Future<void> pump(
    WidgetTester tester, {
    List<PickedDocument> picked = const [],
  }) async {
    tester.view.physicalSize = const Size(1080, 2200);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await pumpScreen(
      tester,
      const ScanScreen(),
      overrides: [
        appDatabaseProvider.overrideWithValue(db),
        scanProcessorProvider.overrideWithValue(processor),
        scanCameraFactoryProvider.overrideWithValue(() => camera),
        documentFilePickerProvider.overrideWithValue(
          FakeDocumentPicker(picked),
        ),
        pickedFileStagerProvider.overrideWithValue(
          (f) async => f.name.startsWith('lost') ? null : '/picks/${f.name}',
        ),
      ],
      extraRoutes: [
        GoRoute(
          path: '/hand-ins/upload',
          builder: (_, state) => Text(
            'upload ${(state.extra as TeacherUploadArgs?)?.files.map((f) => f.name).join(',') ?? ''}',
          ),
        ),
      ],
    );
  }

  PickedDocument picked(String name) =>
      PickedDocument(name: name, bytes: Uint8List(4));

  ScanRejected noMarkers(String p) => ScanRejected(
    imagePath: p,
    capturedAt: _capturedAt,
    detection: goodDetection(missing: [0, 1, 2, 3], qr: null),
    issues: const [
      MarkersMissing([0, 1, 2, 3]),
      QrUnreadable(),
    ],
  );

  testWidgets(
    'picked photos with markers are scanned, the rest go whole-page',
    (tester) async {
      processor.results
        ..add(_ready)
        ..add(noMarkers);
      await pump(
        tester,
        picked: [
          picked('sheet.jpg'),
          picked('photo.jpg'),
          picked('work.pdf'),
          picked('lost.png'),
        ],
      );

      await tester.tap(find.byKey(const Key('scan-pick-files')));
      await tester.pumpAndSettle();

      // The first photo had markers and QR: the usual confirm view.
      expect(processor.analyzed, ['/picks/sheet.jpg']);
      expect(find.text('ด.ญ. สมหญิง'), findsOneWidget);
      expect(find.text('ข้ามไฟล์นี้'), findsOneWidget);
      await tester.tap(find.byKey(const Key('scan-confirm')));
      await tester.pumpAndSettle();
      expect(processor.confirmed.single.imagePath, '/picks/sheet.jpg');

      // No markers, a PDF and an unreadable file: whole-page.
      expect(processor.analyzed, ['/picks/sheet.jpg', '/picks/photo.jpg']);
      expect(find.text('ไม่พบสัญลักษณ์หรือ QR ใน 3 ไฟล์'), findsOneWidget);
      expect(find.text('เลือกแล้ว 3/5 ไฟล์'), findsOneWidget);
      // Let the "บันทึก … แล้ว" SnackBar go away from the action bar.
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('scan-whole-page-2')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 2/5 ไฟล์'), findsOneWidget);
      await tester.tap(find.byKey(const Key('scan-whole-page-send')));
      await tester.pumpAndSettle();
      expect(find.text('upload work.pdf,photo.jpg'), findsOneWidget);

      Navigator.of(tester.element(find.textContaining('upload '))).pop();
      await tester.pumpAndSettle();
      // The file left out waits for the next round.
      expect(find.text('ไม่พบสัญลักษณ์หรือ QR ใน 1 ไฟล์'), findsOneWidget);
      await tester.tap(find.byKey(const Key('scan-whole-page-drop')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('fake-preview')), findsOneWidget);
      await unmountScreen(tester);
    },
  );

  testWidgets('a blurry picked photo can be skipped', (tester) async {
    processor.results.add(
      (p) => ScanRejected(
        imagePath: p,
        capturedAt: _capturedAt,
        detection: goodDetection(),
        issues: const [TooBlurry(10, 80)],
      ),
    );
    await pump(tester, picked: [picked('blurry.jpg')]);

    await tester.tap(find.byKey(const Key('scan-pick-files')));
    await tester.pumpAndSettle();
    expect(find.text('ภาพในไฟล์นี้ไม่คมชัด'), findsOneWidget);
    expect(find.text('ใช้ภาพนี้ต่อ'), findsOneWidget);

    await tester.tap(find.byKey(const Key('scan-retake')));
    await tester.pumpAndSettle();
    expect(processor.discarded.single.imagePath, '/picks/blurry.jpg');
    expect(find.byKey(const Key('fake-preview')), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('capture -> confirm -> back to the camera for the next page', (
    tester,
  ) async {
    processor.results.add(_ready);
    await pump(tester);

    expect(find.byKey(const Key('fake-preview')), findsOneWidget);
    expect(processor.needsLayoutRuns, 1, reason: 'waiting scans retried');

    await tester.tap(find.byKey(const Key('scan-shutter')));
    await tester.pumpAndSettle();

    expect(processor.analyzed, ['/cache/shot1.jpg']);
    expect(find.text('ด.ญ. สมหญิง'), findsOneWidget);
    expect(find.textContaining('การบ้าน #123'), findsOneWidget);
    expect(find.textContaining('หน้า 1/2'), findsWidgets);
    expect(find.textContaining('ฝน B'), findsOneWidget);
    expect(find.textContaining('กรอบที่ 2\nว่าง'), findsOneWidget);
    expect(find.textContaining('คำตอบสุดท้าย'), findsOneWidget);

    await tester.tap(find.byKey(const Key('scan-confirm')));
    await tester.pumpAndSettle();

    expect(processor.confirmed.single.imagePath, '/cache/shot1.jpg');
    expect(processor.discarded, isEmpty);
    expect(find.byKey(const Key('fake-preview')), findsOneWidget);
    expect(find.textContaining('บันทึกแล้ว 1 หน้า'), findsOneWidget);
    expect(
      find.textContaining('บันทึก ด.ญ. สมหญิง (เลขที่ 12) หน้า 1 แล้ว'),
      findsOneWidget,
    );

    await unmountScreen(tester);
    expect(camera.disposed, isTrue);
  });

  testWidgets('a page scanned twice is flagged before confirming', (
    tester,
  ) async {
    processor.results.add((p) => _ready(p, alreadyQueued: true));
    await pump(tester);

    await tester.tap(find.byKey(const Key('scan-shutter')));
    await tester.pumpAndSettle();

    expect(find.textContaining('หน้านี้สแกนไว้แล้ว'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('retake shows the Thai reasons and discards the photo', (
    tester,
  ) async {
    processor.results.add(
      (p) => ScanRejected(
        imagePath: p,
        capturedAt: _capturedAt,
        detection: goodDetection(missing: [1], qr: null),
        issues: const [
          MarkersMissing([1]),
          QrUnreadable(),
        ],
      ),
    );
    await pump(tester);

    await tester.tap(find.byKey(const Key('scan-shutter')));
    await tester.pumpAndSettle();

    expect(find.text('ถ่ายใหม่อีกครั้ง'), findsOneWidget);
    expect(find.textContaining('มุมบนขวา'), findsOneWidget);
    expect(find.textContaining('อ่าน QR'), findsOneWidget);
    expect(find.text('ใช้ภาพนี้ต่อ'), findsNothing);

    await tester.tap(find.byKey(const Key('scan-retake')));
    await tester.pumpAndSettle();

    expect(processor.discarded.single.imagePath, '/cache/shot1.jpg');
    expect(find.byKey(const Key('fake-preview')), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('a blurry page can still be used', (tester) async {
    processor.results.add(
      (p) => ScanRejected(
        imagePath: p,
        capturedAt: _capturedAt,
        detection: goodDetection(blur: 12),
        issues: const [TooBlurry(12, defaultMinBlurScore)],
      ),
    );
    processor.onAcceptBlur = (r) => _ready(r.imagePath);
    await pump(tester);

    await tester.tap(find.byKey(const Key('scan-shutter')));
    await tester.pumpAndSettle();
    expect(find.textContaining('ภาพไม่คมชัด'), findsOneWidget);

    await tester.tap(find.text('ใช้ภาพนี้ต่อ'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('scan-confirm')), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('without a cached layout the photo can wait in the queue', (
    tester,
  ) async {
    processor.results.add(
      (p) => ScanNeedsLayout(
        imagePath: p,
        capturedAt: _capturedAt,
        detection: goodDetection(),
        qr: _qr,
        student: null,
        reason: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ (connectionError)',
      ),
    );
    await pump(tester);

    await tester.tap(find.byKey(const Key('scan-shutter')));
    await tester.pumpAndSettle();

    expect(find.text('ยังไม่มี layout ของใบงานนี้ในเครื่อง'), findsOneWidget);
    expect(find.textContaining('นักเรียนรหัส 4567'), findsOneWidget);

    await tester.tap(find.byKey(const Key('scan-keep')));
    await tester.pumpAndSettle();

    expect(processor.kept, hasLength(1));
    expect(find.byKey(const Key('fake-preview')), findsOneWidget);
    expect(find.textContaining('เก็บไว้ในคิวแล้ว'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('a denied camera permission is explained', (tester) async {
    camera = FakeCamera(
      initError: const ScanCameraException(
        'แอปยังไม่ได้รับอนุญาตให้ใช้กล้อง',
        permissionDenied: true,
      ),
    );
    await pump(tester);

    expect(find.text('เปิดกล้องไม่ได้'), findsOneWidget);
    expect(find.textContaining('ไม่ได้รับอนุญาต'), findsOneWidget);
    expect(find.byKey(const Key('scan-shutter')), findsNothing);
    await unmountScreen(tester);
  });

  testWidgets('off Android the screen says scanning needs the app', (
    tester,
  ) async {
    processor = FakeProcessor(supported: false);
    await pump(tester);

    expect(find.text('สแกนใบงานได้เฉพาะในแอป Android'), findsOneWidget);
    expect(processor.needsLayoutRuns, 0);
    expect(camera.shots, 0);

    // The whole-page upload is the way on from here (§19.6).
    await tester.tap(find.byKey(const ValueKey('scan_teacher_upload')));
    await tester.pumpAndSettle();
    expect(find.text('upload '), findsOneWidget);
    await unmountScreen(tester);
  });
}
