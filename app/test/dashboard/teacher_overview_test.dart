import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/dashboard/teacher_overview.dart';
import 'package:eduvision/features/home/dashboard_page.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../practice/practice_fixtures.dart';
import '../review/review_fixtures.dart';
import '../helpers/home_fakes.dart';

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 1,
      name: 'ป.4/1',
      gradeLevel: 4,
      academicYear: 2569,
      classCode: 'AAAAAA',
      studentCount: 30,
    ),
    Classroom(
      id: 2,
      name: 'ป.4/2',
      gradeLevel: 4,
      academicYear: 2569,
      classCode: 'BBBBBB',
      studentCount: 28,
    ),
  ];
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.items);

  final List<Assignment> items;

  @override
  Future<List<Assignment>> list({int? classroomId}) async => items;
}

const _open = Assignment(
  id: 5,
  classroomId: 1,
  subjectId: 1,
  title: 'บวกเลข',
  status: 'ready',
);
const _draft = Assignment(id: 6, classroomId: 1, subjectId: 1, title: 'ร่าง');
const _closed = Assignment(
  id: 7,
  classroomId: 2,
  subjectId: 1,
  title: 'ปิดแล้ว',
  status: 'closed',
);

void main() {
  group('awaiting review count', () {
    test('uses needs_review_count when the list has it', () async {
      final review = FakeReviewRepository(rows: [queueRow(id: 1)]);
      final c = ProviderContainer(
        overrides: [
          assignmentsRepositoryProvider.overrideWithValue(
            _Assignments([
              Assignment.fromJson({
                'id': 5,
                'classroom_id': 1,
                'subject_id': 1,
                'title': 'a',
                'status': 'ready',
                'needs_review_count': 4,
              }),
              Assignment.fromJson({
                'id': 8,
                'classroom_id': 1,
                'subject_id': 1,
                'title': 'b',
                'status': 'ready',
                'needs_review_count': 3,
              }),
            ]),
          ),
          reviewRepositoryProvider.overrideWithValue(review),
        ],
      );
      addTearDown(c.dispose);
      final sub = c.listen(awaitingReviewCountProvider, (_, _) {});
      addTearDown(sub.close);
      expect(await c.read(awaitingReviewCountProvider.future), 7);
      expect(review.queueCalls, 0);
    });

    test('otherwise counts unreviewed rows of open assignments only', () async {
      final review = FakeReviewRepository(
        rows: [
          queueRow(id: 1),
          queueRow(id: 2, reviewedAt: '2026-10-01T02:00:00Z'),
          queueRow(id: 3, band: 'confident'),
        ],
      );
      final c = ProviderContainer(
        overrides: [
          assignmentsRepositoryProvider.overrideWithValue(
            _Assignments([_open, _draft, _closed]),
          ),
          reviewRepositoryProvider.overrideWithValue(review),
        ],
      );
      addTearDown(c.dispose);
      final sub = c.listen(awaitingReviewCountProvider, (_, _) {});
      addTearDown(sub.close);
      expect(await c.read(awaitingReviewCountProvider.future), 2);
      expect(review.queueCalls, 1, reason: 'draft and closed are skipped');
    });
  });

  testWidgets('home shows live counts and the Phase 6 shortcuts', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final review = FakeReviewRepository(rows: [queueRow(id: 1)])
      ..appealRows = [
        {'id': 1, 'status': 'open', 'response_id': 11},
        {'id': 2, 'status': 'open', 'response_id': 12},
      ];
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
          assignmentsRepositoryProvider.overrideWithValue(
            _Assignments([_open, _draft]),
          ),
          reviewRepositoryProvider.overrideWithValue(review),
          practiceBankRepositoryProvider.overrideWithValue(
            FakePracticeBank([
              bankItemJson(id: 31),
              bankItemJson(id: 32),
              bankItemJson(id: 33, status: 'approved'),
            ]),
          ),
          aiKeyRepositoryProvider.overrideWithValue(
            FakeAiKeyRepository(const AiKeyStatus(configured: true)),
          ),
          ...homeOverrides(),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: DashboardPage(
              user: const User(id: 1, name: 'สมศรี', role: 'teacher'),
              onNavigate: (_) {},
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    String valueOf(String key) => tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(ValueKey(key)),
            matching: find.byType(Text),
          ),
        )
        .first
        .data!;
    expect(valueOf('stat_classrooms'), '2');
    expect(find.text('นักเรียน 58 คน'), findsOneWidget);
    expect(valueOf('stat_assignments'), '2');
    expect(find.text('เปิดอยู่ 1'), findsOneWidget);
    expect(valueOf('stat_review'), '1');
    expect(valueOf('stat_appeals'), '2');
    expect(valueOf('stat_practice_drafts'), '2');
    expect(find.byKey(const ValueKey('ai_key_card')), findsOneWidget);
    expect(find.text('คลังแบบฝึกและลิงก์ทบทวน'), findsOneWidget);
    expect(find.text('ทักษะของห้อง ป.4/1'), findsOneWidget);
    expect(find.text('ทักษะของห้อง ป.4/2'), findsOneWidget);
  });
}
