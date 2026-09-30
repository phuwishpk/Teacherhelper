import 'package:eduvision/core/api/scan_pages.dart';
import 'package:eduvision/core/widgets/submission_page_image.dart';
import 'package:eduvision/features/exams/exam_results_screen.dart';
import 'package:eduvision/features/exams/exam_scan_repository.dart';
import 'package:eduvision/features/exams/exam_version_picker_screen.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../review/review_fixtures.dart' hide apiError;
import 'exam_fakes.dart';
import 'exam_scan_fakes.dart';
import 'exam_test_helpers.dart';

class _NoPages implements ScanPageLoader {
  @override
  Future<Never> page(int scanId) async => throw apiError(404, 'not_found');
}

Map<String, dynamic> _row(
  int id,
  int number,
  String status, {
  List<int> pages = const [1, 2],
  List<Map<String, dynamic>> pageRows = const [],
  double? score,
  int doubts = 0,
  bool needsVersion = false,
  int? version = 2,
}) => {
  'student_id': id,
  'student_number': number,
  'name': 'นักเรียน $number',
  'pages_received': pages,
  'page_count': 2,
  'version_no': version,
  'score': score,
  'status': status,
  'doubt_count': doubts,
  'needs_version': needsVersion,
  'pages': pageRows,
};

/// Four students: 1 ready (8/10), 2 waiting for a version on page 1,
/// 3 missing page 2 with a doubt, 4 without a sheet.
Map<String, dynamic> _status({bool published = false}) => {
  'data': [
    _row(
      11,
      1,
      published ? 'published' : 'reviewed',
      score: 8,
      pageRows: [
        {'scan_id': 80, 'page_no': 1, 'version_no': 2},
        {'scan_id': 81, 'page_no': 2, 'version_no': 2},
      ],
    ),
    if (!published) ...[
      _row(
        12,
        2,
        'needs_review',
        needsVersion: true,
        version: null,
        pageRows: [
          {
            'scan_id': 90,
            'page_no': 1,
            'version_no': null,
            'version_doubtful': false,
          },
          {'scan_id': 91, 'page_no': 2, 'version_no': null},
        ],
      ),
      _row(
        13,
        3,
        'needs_review',
        pages: [1],
        score: 4,
        doubts: 1,
        pageRows: [
          {'scan_id': 95, 'page_no': 1, 'version_no': 1},
        ],
      ),
    ],
    _row(14, 4, 'missing', pages: [], version: null),
  ],
  'summary': {
    'scanned': published ? 1 : 2,
    'total': 4,
    'missing_numbers': published ? [4] : [3, 4],
    'page_count': 2,
    'max_score': 10,
    'published': published ? 1 : 0,
    'ready_to_publish': published ? 0 : 1,
    'waiting_review': published ? 0 : 2,
  },
};

class _Env {
  _Env(this.scans, this.review, this.kits);

  final FakeExamScanRepository scans;
  final _PublishingReview review;
  final FakeKitCache kits;
}

/// Publishing flips the sheet status to "all published".
class _PublishingReview extends FakeReviewRepository {
  _PublishingReview(this.scans);

  final FakeExamScanRepository scans;

  @override
  Future<PublishResult> publishAssignment(int assignmentId) async {
    published.add(assignmentId);
    scans.status = _status(published: true);
    return const PublishResult(published: 1, skipped: 2);
  }
}

Future<_Env> _pump(WidgetTester tester) async {
  final scans = FakeExamScanRepository()..status = _status();
  final review = _PublishingReview(scans);
  final kits = FakeKitCache();
  await pumpExamScreen(
    tester,
    const ExamResultsScreen(examId: 40),
    repo: FakeExamsRepository(
      detail: examJson(
        status: 'ready',
        keyApprovedAt: '2026-10-01T02:00:00Z',
        keyComplete: true,
      ),
    ),
    overrides: [
      examScanRepositoryProvider.overrideWithValue(scans),
      reviewRepositoryProvider.overrideWithValue(review),
      examKitCacheProvider.overrideWithValue(kits),
      scanPageLoaderProvider.overrideWithValue(_NoPages()),
      pageDecoderProvider.overrideWithValue(
        (_) async => throw Exception('no decode'),
      ),
    ],
    stubs: false,
  );
  await tester.pumpAndSettle();
  return _Env(scans, review, kits);
}

void main() {
  group('ตรวจทานและประกาศผล (DESIGN §22.11)', () {
    testWidgets('shows who is ready, waiting or missing, and their state', (
      tester,
    ) async {
      await _pump(tester);

      expect(find.text('ผลสอบ: สอบกลางภาค'), findsOneWidget);
      expect(find.text('สแกนครบ 2/4 คน'), findsOneWidget);
      expect(find.text('ยังขาด เลขที่ 3, 4'), findsOneWidget);
      Text countOf(String key) => tester.widget<Text>(
        find
            .descendant(
              of: find.byKey(ValueKey(key)),
              matching: find.byType(Text),
            )
            .first,
      );
      expect(countOf('count_ready').data, '1');
      expect(countOf('count_waiting').data, '2');
      expect(countOf('count_published').data, '0');
      expect(find.text('ประกาศผลทั้งห้อง (1)'), findsOneWidget);

      expect(find.text('8/10'), findsOneWidget);
      expect(find.text('พร้อมประกาศ'), findsWidgets);
      expect(find.text('ให้ครูเลือกชุด'), findsOneWidget);
      expect(find.text('ยังขาดหน้า 2'), findsOneWidget);
      expect(
        find.text('หน้า 1 จาก 2 · ชุด ข · ต้องตรวจ 1 ข้อ'),
        findsOneWidget,
      );
      expect(find.text('ยังไม่ได้สแกน'), findsOneWidget);
    });

    testWidgets('"ประกาศผลทั้งห้อง" shows the counts, publishes and drops '
        'the cached key once everyone scanned is published', (tester) async {
      final env = await _pump(tester);

      await tapVisible(tester, find.byKey(const ValueKey('exam_publish_all')));
      expect(find.text('ประกาศผลทั้งห้อง?'), findsOneWidget);
      expect(
        find.textContaining('นักเรียน 1 คนที่ตรวจทานครบแล้ว'),
        findsOneWidget,
      );
      expect(
        find.textContaining(
          'อีก 2 คนยังไม่ประกาศ (รอตรวจทาน เลือกชุด หรือขาดหน้า)',
        ),
        findsOneWidget,
      );
      expect(find.textContaining('ยังไม่มีกระดาษคำตอบ 1 คน'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(env.review.published, isEmpty);

      await tapVisible(tester, find.byKey(const ValueKey('exam_publish_all')));
      await tester.tap(find.text('ประกาศผล'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('ประกาศผลแล้ว 1 คน (ยังไม่ครบ 2 คน)'), findsOneWidget);
      await tester.pumpAndSettle();

      expect(env.review.published, [40]);
      expect(find.byKey(const ValueKey('all_published')), findsOneWidget);
      expect(env.kits.removed, [40]);
      final button = tester.widget<ButtonStyleButton>(
        find.byKey(const ValueKey('exam_publish_all')),
      );
      expect(button.onPressed, isNull);
    });

    testWidgets('a page without a version opens the picker; the choice is '
        'scored by the server', (tester) async {
      final env = await _pump(tester);

      await tapVisible(tester, find.byKey(const ValueKey('exam_student_12')));
      expect(find.text('ไม่ทราบชุด'), findsNWidgets(2));
      await tester.tap(find.byKey(const ValueKey('sheet_page_90')));
      await tester.pumpAndSettle();

      expect(find.byType(ExamVersionPickerScreen), findsOneWidget);
      expect(
        find.text('วงชุดว่างหรือฝนหลายวง ดูภาพหน้าแล้วเลือกชุดที่นักเรียนทำ'),
        findsOneWidget,
      );
      expect(find.text('ไม่พบภาพหน้ากระดาษนี้'), findsOneWidget);

      await tapVisible(tester, find.byKey(const ValueKey('version_save')));
      expect(find.text('เลือกชุดก่อนบันทึก'), findsOneWidget);
      expect(env.scans.versionChoices, isEmpty);

      env.scans.versionError = apiError(
        422,
        'validation_failed',
        'ข้อสอบนี้มีชุด 1–2 เท่านั้น',
      );
      await tester.tap(find.byKey(const ValueKey('version_choice_2')));
      await tester.pumpAndSettle();
      await tapVisible(tester, find.byKey(const ValueKey('version_save')));
      expect(find.byKey(const ValueKey('version_error')), findsOneWidget);

      env.scans.versionError = null;
      await tapVisible(tester, find.byKey(const ValueKey('version_save')));
      expect(env.scans.versionChoices.last, (scanId: 90, versionNo: 2));
      expect(find.byType(ExamVersionPickerScreen), findsNothing);
      expect(find.text('ใช้ชุด ข แล้ว หน้านี้ได้ 4/5 คะแนน'), findsOneWidget);
    });

    testWidgets('page 2 follows page 1 and says so', (tester) async {
      await _pump(tester);
      await tapVisible(tester, find.byKey(const ValueKey('exam_student_11')));
      await tester.tap(find.byKey(const ValueKey('sheet_page_81')));
      await tester.pumpAndSettle();
      expect(
        find.text(
          'หน้า 1 ของนักเรียนคนนี้เป็นชุด ข ทุกหน้าต้องเป็นชุดเดียวกัน '
          'ถ้าชุดผิด ให้เลือกชุดที่หน้า 1',
        ),
        findsOneWidget,
      );
      final chip = tester.widget<ChoiceChip>(
        find.byKey(const ValueKey('version_choice_2')),
      );
      expect(chip.selected, isTrue);
    });
  });

  testWidgets('the picker explains every version state', (tester) async {
    Future<void> open(ExamVersionPickerScreen screen) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [scanPageLoaderProvider.overrideWithValue(_NoPages())],
          child: MaterialApp(home: screen),
        ),
      );
      await tester.pumpAndSettle();
    }

    await open(
      const ExamVersionPickerScreen(scanId: 1, pageNo: 2, versionCount: 2),
    );
    expect(find.textContaining('ยังไม่ได้สแกนหน้า 1'), findsOneWidget);
    await open(
      const ExamVersionPickerScreen(
        scanId: 1,
        pageNo: 1,
        versionCount: 2,
        currentVersion: 1,
        versionDoubtful: true,
      ),
    );
    expect(find.textContaining('วงชุดไม่ชัด ระบบใช้ชุด ก'), findsOneWidget);
    await open(
      const ExamVersionPickerScreen(
        scanId: 1,
        pageNo: 1,
        versionCount: 2,
        currentVersion: 2,
      ),
    );
    expect(find.textContaining('ระบบอ่านได้ชุด ข'), findsOneWidget);
  });

  test('versionChosenMessage', () {
    expect(
      versionChosenMessage(
        ExamSheetPageResult.fromJson({
          'scan_id': 1,
          'page_no': 2,
          'version_no': null,
          'needs_version': true,
        }),
      ),
      'บันทึกแล้ว',
    );
  });
}
