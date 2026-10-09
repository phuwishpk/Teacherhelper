import 'package:eduvision/features/exams/exam_answer_key_screen.dart';
import 'package:eduvision/features/exams/exam_key_sheet_scan_screen.dart';
import 'package:eduvision/features/exams/exam_scan_camera.dart';
import 'package:eduvision/features/exams/exam_scan_models.dart';
import 'package:eduvision/features/exams/exam_scan_repository.dart';
import 'package:eduvision/features/exams/exam_scan_screen.dart';
import 'package:eduvision/features/exams/exam_sheet_scanner.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:eduvision/features/upload_queue/queued_scan.dart';
import 'package:eduvision/features/upload_queue/upload_queue_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'exam_fakes.dart';
import 'exam_scan_fakes.dart';

class _Feedback extends ScanFeedback {
  int ok = 0;
  int bad = 0;

  @override
  Future<void> success() async => ok++;

  @override
  Future<void> failure() async => bad++;
}

/// DESIGN §22.10 "หน้าสแกนต่อเนื่องด้วย pipeline และกล้องปลอม": the photo is
/// taken when two frames in a row pass, a repeat is skipped for 5 seconds
/// and then offered as a replacement, the bar counts who is still missing.
void main() {
  late FakeAnswerSheetPipeline pipeline;
  late FakeExamSheetScanner scanner;
  late FakeExamScanRepository repo;
  late FakeKitCache cache;
  late FakeFrameCamera camera;
  late _Feedback feedback;
  late DateTime now;

  setUp(() {
    pipeline = FakeAnswerSheetPipeline();
    scanner = FakeExamSheetScanner(pipeline);
    repo = FakeExamScanRepository();
    cache = FakeKitCache();
    camera = FakeFrameCamera();
    feedback = _Feedback();
    now = DateTime.utc(2026, 10, 15, 3);
  });

  List<Override> overrides() => [
    examSheetScannerProvider.overrideWithValue(scanner),
    examScanRepositoryProvider.overrideWithValue(repo),
    examKitCacheProvider.overrideWithValue(cache),
    frameScanCameraFactoryProvider.overrideWithValue(() => camera),
    scanFeedbackProvider.overrideWithValue(feedback),
    uploadQueueProvider.overrideWith(
      (ref) => Stream<List<QueuedScan>>.value(const []),
    ),
  ];

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await pumpScreen(
      tester,
      ExamScanScreen(
        examId: examId,
        clock: () => now,
        frameInterval: Duration.zero,
      ),
      overrides: overrides(),
    );
    await settle(tester);
  }

  Future<void> twoFrames(WidgetTester tester) async {
    camera.emit();
    await settle(tester);
    camera.emit();
    await settle(tester);
  }

  testWidgets('continuous: two good frames take the photo and show the score', (
    tester,
  ) async {
    pipeline.frames.add(goodFrame());
    scanner.outcomes.add((path, _) => queued(at: now, review: 1));
    await pump(tester);

    expect(find.text('สแกนแล้ว 0/3 คน ยังขาด เลขที่ 1, 2, 3'), findsOneWidget);
    expect(camera.streaming, isTrue);
    camera.emit();
    await settle(tester);
    expect(scanner.scanned, isEmpty);
    camera.emit();
    await settle(tester);

    expect(scanner.scanned, ['/cache/shot1.jpg']);
    expect(feedback.ok, 1);
    expect(find.byKey(const ValueKey('exam_scan_result')), findsOneWidget);
    expect(find.text('4/5'), findsOneWidget);
    expect(find.text('เลขที่ 1 ด.ญ. หนึ่ง'), findsOneWidget);
    expect(find.text('หน้า 1/1 · ชุด ข'), findsOneWidget);
    expect(find.text('ต้องตรวจ 1 ข้อ'), findsOneWidget);
    expect(find.text('สแกนแล้ว 1/3 คน ยังขาด เลขที่ 2, 3'), findsOneWidget);
    expect(camera.streaming, isTrue, reason: 'streaming again after the read');
    expect(repo.statusCalls, 1);

    // The same sheet in view: skipped silently for 5 seconds.
    now = now.add(const Duration(seconds: 2));
    await twoFrames(tester);
    expect(scanner.scanned, hasLength(1));

    // Later: offered as a replacement of the page scanned before.
    now = now.add(const Duration(seconds: 6));
    await twoFrames(tester);
    expect(scanner.scanned, hasLength(1));
    expect(find.text('สแกนใบนี้แล้ว'), findsOneWidget);
    scanner.outcomes.add((path, _) => queued(at: now, score: 5));
    await tester.tap(find.byKey(const ValueKey('exam_scan_replace')));
    await settle(tester);
    expect(scanner.scanned, hasLength(2));
    expect(find.text('5/5'), findsOneWidget);
    await unmountScreen(tester);
  });

  group('shared sheets with the student-ID grid (DESIGN §22.19)', () {
    setUp(() => repo.kit = kitJson(codeDigits: 5));

    // While the list of students is open the preview keeps its spinner, so
    // pumpAndSettle would never return: pump a fixed time instead.
    Future<void> pumpOpen(WidgetTester tester) async {
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
    }

    testWidgets('the next photo waits until the sheet left the frame', (
      tester,
    ) async {
      pipeline.frames.add(sharedFrame());
      scanner.outcomes.add(
        (path, _) => queued(
          student: 12,
          at: now,
          identifiedBy: ExamSheetQueued.identifiedByCode,
        ),
      );
      await pump(tester);

      expect(
        find.text(
          'นักเรียน 1 คนไม่มีเลขประจำตัวที่ฝนได้ ต้องเลือกชื่อเองตอนสแกน',
        ),
        findsOneWidget,
      );
      await twoFrames(tester);
      expect(scanner.scanned, hasLength(1));
      expect(scanner.alreadyAsked.single, isFalse);
      expect(find.text('เลขที่ 2 ด.ช. สอง'), findsOneWidget);
      expect(find.text('ยกกระดาษใบนี้ออก แล้ววางใบถัดไป'), findsOneWidget);

      // Every shared sheet has the same QR: the sheet still in view is not
      // photographed again, however long it lies there.
      now = now.add(const Duration(seconds: 30));
      await twoFrames(tester);
      await twoFrames(tester);
      expect(scanner.scanned, hasLength(1));

      // A frame without the sheet, then the next sheet.
      pipeline.frames
        ..clear()
        ..add(emptyFrame());
      camera.emit();
      await settle(tester);
      expect(find.text('ยกกระดาษใบนี้ออก แล้ววางใบถัดไป'), findsNothing);
      pipeline.frames
        ..clear()
        ..add(sharedFrame());
      scanner.outcomes.add(
        (path, _) =>
            queued(at: now, identifiedBy: ExamSheetQueued.identifiedByCode),
      );
      await twoFrames(tester);
      expect(scanner.scanned, hasLength(2));
      expect(find.text('เลขที่ 1 ด.ญ. หนึ่ง'), findsOneWidget);
      expect(find.text('สแกนแล้ว 2/3 คน ยังขาด เลขที่ 3'), findsOneWidget);
      await unmountScreen(tester);
    });

    testWidgets('an ID that cannot be read lets the teacher pick the '
        'student', (tester) async {
      pipeline.frames.add(sharedFrame());
      scanner.outcomes.add(
        (path, _) => ExamSheetNeedsStudent(
          pending: pendingSheet(at: now),
          reason: StudentCodeReader.blank,
        ),
      );
      await pump(tester);
      camera.emit();
      await pumpOpen(tester);
      camera.emit();
      await pumpOpen(tester);

      expect(find.byKey(const ValueKey('exam_scan_pick_student')), findsOne);
      expect(
        find.text('ไม่ได้ฝนเลขประจำตัว เลือกนักเรียนของกระดาษใบนี้'),
        findsOneWidget,
      );
      expect(find.text('10001'), findsOneWidget);
      expect(find.text('ไม่มีเลขประจำตัว'), findsOneWidget);
      expect(feedback.bad, 1);
      expect(camera.streaming, isFalse, reason: 'no photos while choosing');

      await tester.tap(find.byKey(const ValueKey('pick_student_13')));
      await settle(tester);

      expect(scanner.assigned.single.student.studentId, 13);
      expect(scanner.discarded, isEmpty);
      expect(feedback.ok, 1);
      expect(find.text('เลขที่ 3 ด.ญ. สาม'), findsOneWidget);
      expect(find.text('หน้า 1/1 · ชุด ข · ครูเลือกชื่อ'), findsOneWidget);
      expect(find.text('สแกนแล้ว 1/3 คน ยังขาด เลขที่ 1, 2'), findsOneWidget);
      await unmountScreen(tester);
    });

    testWidgets('closing the list drops the photo', (tester) async {
      await pump(tester);
      await tester.tap(find.byKey(const ValueKey('exam_scan_continuous')));
      await settle(tester);
      scanner.outcomes.add(
        (path, _) => ExamSheetNeedsStudent(
          pending: pendingSheet(code: '99999', at: now),
          reason: ExamSheetNeedsStudent.unknown,
          code: '99999',
        ),
      );
      await tester.tap(find.byKey(const ValueKey('exam_scan_shutter')));
      await pumpOpen(tester);
      expect(
        find.text(
          'ไม่พบเลขประจำตัว 99999 ในห้องนี้ เลือกนักเรียนของกระดาษใบนี้',
        ),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('exam_scan_pick_discard')));
      await settle(tester);

      expect(scanner.discarded, hasLength(1));
      expect(scanner.assigned, isEmpty);
      expect(find.byKey(const ValueKey('exam_scan_rejected')), findsOneWidget);
      expect(find.textContaining('ยังไม่ได้เลือกนักเรียน'), findsOneWidget);
      expect(
        find.text('สแกนแล้ว 0/3 คน ยังขาด เลขที่ 1, 2, 3'),
        findsOneWidget,
      );
      await unmountScreen(tester);
    });
  });

  testWidgets('a failed read vibrates twice and waits before trying again', (
    tester,
  ) async {
    pipeline.frames.add(goodFrame(student: 12));
    scanner.outcomes.add(
      (path, _) => const ExamSheetRejected('มองไม่เห็นสัญลักษณ์ที่มุมล่างขวา'),
    );
    await pump(tester);
    await twoFrames(tester);
    expect(feedback.bad, 1);
    expect(find.text('มองไม่เห็นสัญลักษณ์ที่มุมล่างขวา'), findsOneWidget);

    await twoFrames(tester);
    expect(scanner.scanned, hasLength(1), reason: 'cooling down');
    await unmountScreen(tester);
  });

  testWidgets('shutter mode: one photo per tap, a blurry one can be kept', (
    tester,
  ) async {
    await pump(tester);
    await tester.tap(find.byKey(const ValueKey('exam_scan_continuous')));
    await settle(tester);
    expect(camera.streaming, isFalse);

    scanner.outcomes.add(
      (path, _) => ExamSheetRejected('ภาพไม่คมชัด', keptPhoto: path),
    );
    await tester.tap(find.byKey(const ValueKey('exam_scan_shutter')));
    await settle(tester);
    expect(find.text('ภาพไม่คมชัด'), findsOneWidget);

    var accepted = false;
    scanner.outcomes.add((path, blur) {
      accepted = blur;
      return queued(at: now, version: null, score: null);
    });
    await tester.tap(find.byKey(const ValueKey('exam_scan_accept_blur')));
    await settle(tester);
    expect(accepted, isTrue);
    expect(scanner.scanned, ['/cache/shot1.jpg', '/cache/shot1.jpg']);
    expect(find.text('ให้ครูเลือกชุด'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('the bar lists who is missing and the server score shows', (
    tester,
  ) async {
    repo.status = {
      'data': [
        {
          'student_id': 11,
          'pages_received': [1],
          'score': 3.5,
          'status': 'reviewed',
        },
        {
          'student_id': 13,
          'pages_received': [1],
          'score': 2,
          'status': 'reviewed',
        },
      ],
      'summary': {'max_score': 5},
    };
    pipeline.frames.add(goodFrame());
    scanner.outcomes.add((path, _) => queued(at: now));
    await pump(tester);
    expect(find.text('สแกนแล้ว 2/3 คน ยังขาด เลขที่ 2'), findsOneWidget);
    expect(scanner.pageOneAsked, isEmpty);

    await twoFrames(tester);
    expect(find.text('คะแนนจากเซิร์ฟเวอร์ 3.5/5'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('exam_scan_summary')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('missing_12')), findsOneWidget);
    expect(find.text('ด.ช. สอง'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('offline with a prepared kit says so', (tester) async {
    await cache.save(sampleKit());
    repo.kitError = offlineError();
    await pump(tester);
    expect(
      find.textContaining('ออฟไลน์: ใช้เฉลยและรายชื่อที่เตรียมไว้'),
      findsOneWidget,
    );
    await unmountScreen(tester);
  });

  testWidgets(
    'without an approved key or printed sheets there is nothing to scan',
    (tester) async {
      repo.kitError = apiError(
        409,
        'answer_key_not_approved',
        'ต้องอนุมัติเฉลยก่อนเตรียมสแกน',
      );
      await pump(tester);
      expect(find.text('ต้องอนุมัติเฉลยก่อนเตรียมสแกน'), findsOneWidget);
      expect(camera.streaming, isFalse);

      repo
        ..kitError = null
        ..kit = kitJson(layoutVersion: null);
      await tester.tap(find.byKey(const ValueKey('exam_scan_retry')));
      await settle(tester);
      expect(find.textContaining('ยังไม่ได้พิมพ์กระดาษคำตอบ'), findsOneWidget);
      await unmountScreen(tester);
    },
  );

  testWidgets('the camera error is shown', (tester) async {
    camera = FakeFrameCamera(
      initError: const ScanCameraException('แอปยังไม่ได้รับอนุญาตให้ใช้กล้อง'),
    );
    await pump(tester);
    expect(find.text('แอปยังไม่ได้รับอนุญาตให้ใช้กล้อง'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('without the native reader (web) scanning needs Android', (
    tester,
  ) async {
    scanner = FakeExamSheetScanner(FakeAnswerSheetPipeline(supported: false));
    await pump(tester);
    expect(
      find.textContaining('ต้องใช้แอป Krucheck บนโทรศัพท์ Android'),
      findsOneWidget,
    );
    expect(repo.kitCalls, 0);
  });

  group('key sheet', () {
    testWidgets('reads pages of one version and hands them to the grid', (
      tester,
    ) async {
      scanner.keyOutcomes.addAll([
        const KeySheetRejected(
          'นี่คือกระดาษคำตอบของนักเรียน ไม่ใช่กระดาษเฉลยของครู',
        ),
        KeySheetRead(
          KeySheetProposal.fromJson({
            'version_no': 2,
            'page': 1,
            'proposal': [
              {
                'question_id': 11,
                'sheet_no': 1,
                'type': 'mcq',
                'accepted_options': [1],
                'doubtful': false,
                'differs': true,
              },
              {
                'question_id': 12,
                'sheet_no': 2,
                'type': 'mcq',
                'accepted_options': [],
                'doubtful': true,
                'differs': false,
              },
            ],
          }),
        ),
        KeySheetRead(
          KeySheetProposal.fromJson({
            'version_no': 2,
            'page': 2,
            'proposal': [
              {
                'question_id': 31,
                'sheet_no': 101,
                'type': 'numeric',
                'accepted_values': ['0.25'],
                'doubtful': false,
                'differs': true,
              },
            ],
          }),
        ),
      ]);
      await pumpScreen(
        tester,
        const ExamKeySheetScanScreen(examId: 40),
        overrides: overrides(),
      );
      await settle(tester);

      await tester.tap(find.byKey(const ValueKey('key_sheet_shutter')));
      await settle(tester);
      expect(find.byKey(const ValueKey('key_sheet_error')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('key_sheet_shutter')));
      await settle(tester);
      expect(
        find.text(
          'ชุด ข หน้า 1: อ่านได้ 1 ข้อ · ต่างจากเฉลยเดิม 1 ข้อ · อ่านไม่ชัด 1 ข้อ',
        ),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('key_sheet_shutter')));
      await settle(tester);
      expect(scanner.keyVersions, [null, null, 2]);
      expect(find.textContaining('หน้า 1, 2'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('key_sheet_use')));
      await settle(tester);
      expect(find.text('stub-home'), findsOneWidget);
      await unmountScreen(tester);
    });

    testWidgets('the key grid is filled from the scan and marks the rows', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(1080, 3000);
      tester.view.devicePixelRatio = 2.5;
      addTearDown(tester.view.reset);
      final exams = FakeExamsRepository();
      await pumpScreen(
        tester,
        const ExamAnswerKeyScreen(examId: 40),
        overrides: [examsRepositoryProvider.overrideWithValue(exams)],
        extraRoutes: [
          GoRoute(
            path: '/exams/:id/key-sheet-scan',
            builder: (context, state) => Scaffold(
              body: TextButton(
                onPressed: () => context.pop(
                  KeySheetScanResult(
                    versionNo: 2,
                    items: [
                      KeySheetProposalItem.fromJson({
                        'question_id': 11,
                        'type': 'mcq',
                        'accepted_options': [1],
                        'differs': true,
                      }),
                      KeySheetProposalItem.fromJson({
                        'question_id': 12,
                        'type': 'mcq',
                        'accepted_options': [],
                        'doubtful': true,
                      }),
                      KeySheetProposalItem.fromJson({
                        'question_id': 31,
                        'type': 'numeric',
                        'accepted_values': ['0.25'],
                        'differs': true,
                      }),
                      KeySheetProposalItem.fromJson({
                        'question_id': 999,
                        'type': 'mcq',
                        'accepted_options': [1],
                      }),
                    ],
                  ),
                ),
                child: const Text('fake-scan-done'),
              ),
            ),
          ),
        ],
      );
      await tester.tap(find.byKey(const ValueKey('key_grid_scan')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('fake-scan-done'));
      await tester.pumpAndSettle();

      expect(find.text('บันทึก (2 ข้อ)'), findsOneWidget);
      expect(find.text('ต่างจากเฉลยที่บันทึกไว้'), findsNWidgets(2));
      expect(
        find.text('อ่านจากกระดาษเฉลยไม่ได้ ตรวจแล้วกรอกเอง'),
        findsOneWidget,
      );
      final chip = tester.widget<FilterChip>(
        find.byKey(const ValueKey('key_chip_11_1')),
      );
      expect(chip.selected, isTrue);

      await tester.tap(find.byKey(const ValueKey('key_grid_save')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('key_note_11')), findsNothing);
      expect(exams.args('saveAnswerKey').single, hasLength(2));
    });
  });
}
