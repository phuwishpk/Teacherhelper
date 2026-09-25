import 'package:eduvision/features/appeals/appeals_screen.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'review_fixtures.dart';

Map<String, dynamic> _appeal(int id, {int responseId = 11}) => {
  'id': id,
  'response_id': responseId,
  'status': 'open',
  'reason': 'หนูหารได้ 5 ค่ะ',
  'created_at': '2026-10-02T04:00:00Z',
  'student': {'id': 4012, 'name': 'ด.ญ. สมหญิง', 'student_number': 12},
  'assignment': {'id': 5, 'title': 'บวกเลข'},
  'question': {'position': 2, 'max_points': 3},
  'final_score': 1.5,
};

void main() {
  testWidgets('teacher accepts one appeal with a new score and rejects '
      'another with a note', (tester) async {
    final repo = FakeReviewRepository()
      ..appealRows = [_appeal(3), _appeal(4, responseId: 12)];
    await pumpScreen(
      tester,
      const AppealsScreen(),
      overrides: [reviewRepositoryProvider.overrideWithValue(repo)],
    );
    expect(find.text('บวกเลข · ข้อ 2'), findsNWidgets(2));
    expect(find.text('คะแนนตอนนี้ 1.5/3'), findsNWidgets(2));
    expect(find.text('เหตุผลของนักเรียน: "หนูหารได้ 5 ค่ะ"'), findsNWidgets(2));

    await tester.tap(find.text('ตอบคำขอ').first);
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('เพิ่มคะแนน'));
    await tester.pump();
    await tester.tap(find.byTooltip('เพิ่มคะแนน'));
    await tester.pumpAndSettle();
    expect(find.text('2.5 / 3'), findsOneWidget);
    await tester.tap(find.text('ส่งคำตอบ'));
    await tester.pumpAndSettle();
    expect(repo.resolved, [(3, 'accepted', null, 2.5)]);
    expect(find.text('บวกเลข · ข้อ 2'), findsOneWidget);

    await tester.tap(find.text('ตอบคำขอ'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ยืนยันคะแนนเดิม'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ส่งคำตอบ'));
    await tester.pumpAndSettle();
    expect(
      find.text('บอกเหตุผลให้นักเรียนทราบว่าทำไมยืนยันคะแนนเดิม'),
      findsOneWidget,
    );
    await tester.enterText(find.byType(TextField), 'บรรทัดที่ 3 ยังหารผิด');
    await tester.tap(find.text('ส่งคำตอบ'));
    await tester.pumpAndSettle();
    expect(repo.resolved.last, (4, 'rejected', 'บรรทัดที่ 3 ยังหารผิด', null));
    expect(find.text('ไม่มีคำขอที่รอตอบ'), findsOneWidget);
  });
}
