import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/review/review_detail_screen.dart';
import 'package:eduvision/features/review/review_labels.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:eduvision/features/review/review_queue_screen.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'review_fixtures.dart';

class _OneAssignment extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 1,
    subjectId: 1,
    title: 'บวกเลข',
    status: 'ready',
  );
}

/// Phone-sized but tall, so every section of the detail list is built.
void _phone(WidgetTester tester) {
  tester.view.physicalSize = const Size(800, 3200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  group('review detail (DESIGN §13)', () {
    testWidgets('shows what was read and why, and requires a reason when '
        'the score differs from the AI', (tester) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [queueRow(id: 11), queueRow(id: 12, position: 2)],
      );
      await pumpScreen(
        tester,
        const ReviewDetailScreen(
          assignmentId: 5,
          responseId: 11,
          band: PriorityBand.check,
        ),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          cropLoaderProvider.overrideWithValue(NoCropLoader()),
        ],
      );

      expect(find.text('ต้องตรวจ 1/2'), findsOneWidget);
      expect(find.text('100 + 25 = ?'), findsOneWidget);
      expect(find.text('คำตอบที่ยอมรับ: 125'), findsOneWidget);
      expect(find.text('ไม่มีภาพของข้อนี้'), findsOneWidget);
      // What Gemini and the CNN read.
      expect(find.textContaining('คำตอบที่อ่านได้'), findsOneWidget);
      expect(find.text('เทียบกับเฉลย: ตรงกับเฉลย'), findsOneWidget);
      expect(find.textContaining('CNN อ่านตัวเลขได้: 126'), findsOneWidget);
      // "เหตุผลของคะแนน" from the fuzzy trace: only the fired rule, the
      // computation and the review-priority reasons.
      expect(find.text('เหตุผลของคะแนน'), findsOneWidget);
      expect(find.text('คำตอบตรงกับเฉลย'), findsOneWidget);
      expect(find.text('คำตอบไม่ตรงกับเฉลย'), findsNothing);
      expect(find.textContaining('= 2.00 → ปัดเป็น 2'), findsOneWidget);
      expect(
        find.textContaining('CNN กับ Gemini อ่านไม่ตรงกัน'),
        findsOneWidget,
      );
      expect(find.text('ทำได้ดีมาก'), findsOneWidget);
      expect(find.text('2 / 2'), findsOneWidget);

      // Lower the score: saving without a reason is refused locally.
      await tester.tap(find.byTooltip('ลดคะแนน'));
      await tester.pumpAndSettle();
      expect(find.text('1.5 / 2'), findsOneWidget);
      expect(find.text('เหตุผลที่แก้คะแนน (จำเป็น)'), findsOneWidget);
      await tester.tap(find.text('บันทึก'));
      await tester.pumpAndSettle();
      expect(
        find.text('คะแนนต่างจากที่ AI ให้ ต้องเลือกเหตุผลก่อนบันทึก'),
        findsOneWidget,
      );
      expect(repo.saved, isEmpty);

      // "เหตุผลอื่น" needs text.
      await tester.tap(find.byKey(const ValueKey('reason_picker')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('เหตุผลอื่น').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('บันทึก'));
      await tester.pumpAndSettle();
      expect(find.text('พิมพ์เหตุผลที่แก้คะแนน'), findsOneWidget);
      expect(repo.saved, isEmpty);

      await tester.tap(find.byKey(const ValueKey('reason_picker')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('AI อ่านลายมือผิด').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('reason_text')),
        'เลขท้ายคือ 6',
      );
      await tester.tap(find.text('เข้าใจบางส่วน'));
      await tester.tap(find.text('คำนวณพลาด'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('บันทึกและข้อถัดไป'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('บันทึกผลข้อนี้แล้ว'), findsOneWidget);
      await tester.pumpAndSettle();

      expect(repo.saved.single.$1, 11);
      expect(repo.saved.single.$2, {
        'final_score': 1.5,
        'final_understanding': 'partial',
        'final_error_types': ['calculation'],
        'reason': 'AI อ่านลายมือผิด: เลขท้ายคือ 6',
      });
      // Moved on to the next response of the tab.
      expect(find.text('ต้องตรวจ 2/2'), findsOneWidget);
    });

    testWidgets('changing a score of a total taken from Classroom warns '
        'first (§19.3)', (tester) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [queueRow(id: 11)],
        responses: {
          11: {
            ...responseJson(id: 11, reviewedAt: '2026-10-01T01:00:00Z'),
            'submission_status': 'published',
            'total_overridden': true,
          },
        },
      );
      await pumpScreen(
        tester,
        const ReviewDetailScreen(assignmentId: 5, responseId: 11),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          cropLoaderProvider.overrideWithValue(NoCropLoader()),
        ],
      );
      await tester.tap(find.byTooltip('ลดคะแนน'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('reason_picker')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('AI อ่านลายมือผิด').last);
      await tester.pumpAndSettle();

      await tester.tap(find.text('บันทึกการแก้ไข'));
      await tester.pumpAndSettle();
      expect(find.text('เปลี่ยนคะแนนข้อนี้?'), findsOneWidget);
      expect(find.text(totalOverrideClearWarning), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(repo.saved, isEmpty);

      await tester.tap(find.text('บันทึกการแก้ไข'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'บันทึก'));
      await tester.pumpAndSettle();
      expect(repo.saved.single.$2['final_score'], 1.5);
    });

    testWidgets('accepting the AI score needs no reason; an edited '
        'explanation is sent', (tester) async {
      _phone(tester);
      final repo = FakeReviewRepository(rows: [queueRow(id: 11)]);
      await pumpScreen(
        tester,
        const ReviewDetailScreen(assignmentId: 5, responseId: 11),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          cropLoaderProvider.overrideWithValue(NoCropLoader()),
        ],
      );
      await tester.enterText(
        find.byKey(const ValueKey('explanation_field')),
        'ทำได้ดีมาก ลองตรวจคำตอบด้วยการลบกลับ',
      );
      await tester.tap(find.text('บันทึก'));
      await tester.pumpAndSettle();

      expect(repo.saved.single.$2, {
        'final_score': 2.0,
        'final_understanding': 'good',
        'final_error_types': <String>[],
        'explanation': 'ทำได้ดีมาก ลองตรวจคำตอบด้วยการลบกลับ',
      });
      expect(find.text('ตรวจทานแล้ว'), findsOneWidget);
    });

    testWidgets('a manual response (no key) is scored by hand without a '
        'reason', (tester) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [
          queueRow(id: 11, state: 'manual', manualReason: 'ai_key_missing'),
        ],
        responses: {
          11: responseJson(
            aiScore: null,
            state: 'manual',
            manualReason: 'ai_key_missing',
          ),
        },
      );
      await pumpScreen(
        tester,
        const ReviewDetailScreen(assignmentId: 5, responseId: 11),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          cropLoaderProvider.overrideWithValue(NoCropLoader()),
        ],
      );
      expect(find.textContaining('ไม่มี Gemini API key'), findsOneWidget);
      expect(find.text('AI ยังไม่ได้ให้คะแนนข้อนี้'), findsOneWidget);

      await tester.tap(find.text('บันทึก'));
      await tester.pumpAndSettle();
      expect(find.text('ให้คะแนนก่อนบันทึก'), findsOneWidget);

      await tester.tap(find.widgetWithText(TextButton, 'เต็ม'));
      await tester.tap(find.text('เข้าใจดี'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('บันทึก'));
      await tester.pumpAndSettle();
      expect(repo.saved.single.$2['final_score'], 2.0);
      expect(repo.saved.single.$2.containsKey('reason'), isFalse);
    });
    testWidgets('warns about a prompt injection that only the extraction '
        'reports (§10.3)', (tester) async {
      _phone(tester);
      final json = responseJson();
      (json['extraction'] as Map)['suspicious_instruction'] = true;
      final repo = FakeReviewRepository(
        rows: [queueRow(id: 11)],
        responses: {11: json},
      );
      await pumpScreen(
        tester,
        const ReviewDetailScreen(assignmentId: 5, responseId: 11),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          cropLoaderProvider.overrideWithValue(NoCropLoader()),
        ],
      );
      expect(find.textContaining('พยายามสั่งผู้ตรวจ'), findsOneWidget);
      expect(find.text('น่าสงสัย'), findsWidgets);
    });
  });

  group('review queue', () {
    testWidgets('missing-key banner opens settings and requeues', (
      tester,
    ) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [
          queueRow(id: 11, state: 'manual', manualReason: 'ai_key_missing'),
          queueRow(id: 12, position: 2, band: 'look'),
        ],
        meta: {'missing_ai_key_count': 1},
      );
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: false)),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: '/settings',
            builder: (_, _) => const Scaffold(body: Text('settings-page')),
          ),
        ],
      );

      final banner = find.byKey(const ValueKey('missing_key_banner'));
      expect(banner, findsOneWidget);
      expect(find.text('ยังไม่ได้ใส่ Gemini API key'), findsOneWidget);
      expect(
        find.textContaining('มี 1 ข้อที่ AI ยังไม่ได้ตรวจ'),
        findsOneWidget,
      );
      expect(find.text('ตรวจทาน: บวกเลข'), findsOneWidget);
      expect(find.text('ตรวจเอง · ไม่มี key'), findsOneWidget);

      await tester.tap(find.text('ตรวจข้อที่ค้างใหม่'));
      await tester.pumpAndSettle();
      expect(repo.requeued, [5]);
      expect(
        find.textContaining('ส่ง 3 ข้อกลับไปให้ AI ตรวจแล้ว'),
        findsOneWidget,
      );
      expect(banner, findsNothing, reason: 'the refreshed queue has none');

      repo.meta = {'missing_ai_key_count': 2};
      await tester.tap(find.byTooltip('ดึงรายการใหม่'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ไปใส่ key'));
      await tester.pumpAndSettle();
      expect(find.text('settings-page'), findsOneWidget);
    });

    testWidgets('no banner when nothing waits for a key', (tester) async {
      _phone(tester);
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(
            FakeReviewRepository(rows: [queueRow(id: 11)]),
          ),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
        ],
      );
      expect(find.byKey(const ValueKey('missing_key_banner')), findsNothing);
      expect(find.text('ต้องตรวจ (1)'), findsOneWidget);
    });

    testWidgets('tabs split by band; confident rows are bulk-approved, '
        'submissions publish after a confirmation', (tester) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [
          queueRow(id: 11, band: 'check', suspicious: true),
          queueRow(id: 12, band: 'look', position: 2),
          queueRow(id: 13, band: 'confident', position: 3),
          queueRow(id: 14, band: 'confident', position: 4, suspicious: true),
        ],
        meta: {
          'submissions': [
            {
              'id': 70,
              'status': 'reviewed',
              'response_count': 4,
              'reviewed_count': 4,
              'total_score': 7,
              'student': {
                'id': 4012,
                'name': 'ด.ญ. สมหญิง',
                'student_number': 12,
              },
            },
          ],
        },
      );
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
        ],
      );
      // A suspicious row is on "ต้องตรวจ" whatever its band (§10.7).
      expect(find.text('ต้องตรวจ (2)'), findsOneWidget);
      expect(find.text('ควรดู (1)'), findsOneWidget);
      expect(find.text('มั่นใจ (1)'), findsOneWidget);
      expect(find.text('น่าสงสัย'), findsNWidgets(2));

      await tester.tap(find.text('มั่นใจ (1)'));
      await tester.pumpAndSettle();
      // Suspicious rows never go through bulk approval (§10.7).
      await tester.tap(find.text('อนุมัติทั้งกลุ่มมั่นใจ (1 ข้อ)'));
      await tester.pumpAndSettle();
      expect(find.text('อนุมัติ 1 ข้อในกลุ่มมั่นใจ?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'อนุมัติ'));
      await tester.pumpAndSettle();
      expect(repo.approved, [5]);

      await tester.tap(find.text('รายคน (1)'));
      await tester.pumpAndSettle();
      expect(find.text('ตรวจทานแล้ว 4/4 ข้อ · รวม 7 คะแนน'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'เผยแพร่'));
      await tester.pumpAndSettle();
      expect(find.text('เผยแพร่ผลของ เลขที่ 12 ด.ญ. สมหญิง?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'เผยแพร่').last);
      await tester.pumpAndSettle();
      expect(repo.publishedSubmissions, [70]);

      await tester.tap(find.byTooltip('เผยแพร่ทั้งการบ้าน'));
      await tester.pumpAndSettle();
      expect(find.text('เผยแพร่ผลทั้งการบ้าน?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'เผยแพร่').last);
      await tester.pumpAndSettle();
      expect(repo.published, [5]);
    });

    testWidgets('a Classroom scan whose QR names another student waits on '
        '"ต้องตรวจ" and stays out of bulk approval (§18.3)', (tester) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [
          queueRow(id: 21, band: 'check', position: 1),
          queueRow(
            id: 22,
            band: 'confident',
            position: 2,
            identityMismatch: true,
          ),
          queueRow(id: 23, band: 'confident', position: 3),
        ],
      );
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
        ],
      );
      expect(find.text('ต้องตรวจ (2)'), findsOneWidget);
      expect(find.text('มั่นใจ (1)'), findsOneWidget);
      // The flagged row comes before a plain row with a higher priority.
      expect(find.text('ตัวตนไม่ตรง'), findsOneWidget);
      expect(
        tester.getTopLeft(find.text('ตัวตนไม่ตรง')).dy,
        lessThan(tester.getTopLeft(find.textContaining('ข้อ 1 ·')).dy),
      );

      await tester.tap(find.text('มั่นใจ (1)'));
      await tester.pumpAndSettle();
      expect(find.text('ตัวตนไม่ตรง'), findsNothing);
      await tester.tap(find.text('อนุมัติทั้งกลุ่มมั่นใจ (1 ข้อ)'));
      await tester.pumpAndSettle();
      expect(find.text('อนุมัติ 1 ข้อในกลุ่มมั่นใจ?'), findsOneWidget);
    });

    testWidgets('rescans of published pages are confirmed from the queue', (
      tester,
    ) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [queueRow(id: 11)],
        meta: {
          'pending_confirm_scans': [
            {
              'scan_id': 90,
              'submission_id': 70,
              'page_no': 1,
              'scanned_at': '2026-10-01T02:15:00Z',
              'student': {
                'id': 4012,
                'name': 'ด.ญ. สมหญิง',
                'student_number': 12,
              },
            },
          ],
        },
      );
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
        ],
      );
      await tester.tap(find.text('สแกนใหม่ของผลที่เผยแพร่แล้ว (1)'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ใช้สแกนใหม่'));
      await tester.pumpAndSettle();
      expect(find.text('ใช้สแกนใหม่แทนผลเดิม?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'ใช้สแกนใหม่'));
      await tester.pumpAndSettle();
      expect(repo.confirmed, [90]);
    });

    testWidgets('a rescan of a total taken from Classroom warns (§19.3)', (
      tester,
    ) async {
      _phone(tester);
      final repo = FakeReviewRepository(
        rows: [queueRow(id: 11)],
        meta: {
          'pending_confirm_scans': [
            {'scan_id': 90, 'submission_id': 70, 'page_no': 1},
          ],
          'submissions': [
            {
              'id': 70,
              'status': 'published',
              'response_count': 1,
              'reviewed_count': 1,
              'total_score': 2,
              'total_override': 1.5,
            },
          ],
        },
      );
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
        ],
      );
      await tester.tap(find.text('สแกนใหม่ของผลที่เผยแพร่แล้ว (1)'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ใช้สแกนใหม่'));
      await tester.pumpAndSettle();
      expect(find.textContaining(totalOverrideClearWarning), findsOneWidget);
    });

    testWidgets('tablets show list and detail side by side', (tester) async {
      tester.view.physicalSize = const Size(1280, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final repo = FakeReviewRepository(
        rows: [queueRow(id: 11), queueRow(id: 12, position: 2)],
      );
      await pumpScreen(
        tester,
        const ReviewQueueScreen(assignmentId: 5),
        overrides: [
          reviewRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
          cropLoaderProvider.overrideWithValue(NoCropLoader()),
        ],
      );
      expect(find.text('1 จาก 2'), findsOneWidget, reason: 'detail pane open');
      expect(find.text('เหตุผลของคะแนน'), findsOneWidget);
      await tester.tap(find.byTooltip('ข้อถัดไป'));
      await tester.pumpAndSettle();
      expect(find.text('2 จาก 2'), findsOneWidget);
    });
  });
}
