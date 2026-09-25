import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/assignments/rubric_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

const _oldCriteria = [
  RubricCriterion(
    id: 1,
    position: 1,
    description: 'ตั้งสมการจากโจทย์ได้ถูกต้องครบทุกเงื่อนไข',
    points: 2,
    isCore: true,
    source: 'ai',
  ),
  RubricCriterion(
    id: 2,
    position: 2,
    description: 'แสดงวิธีทำและได้คำตอบ 125',
    points: 3,
    source: 'ai',
  ),
];

const _newCriteria = [
  RubricCriterion(
    id: 3,
    position: 1,
    description: 'เขียนสมการได้',
    points: 2,
    isCore: true,
    source: 'ai',
  ),
  RubricCriterion(
    id: 4,
    position: 2,
    description: 'คำนวณถูกต้อง',
    points: 2,
    source: 'ai',
  ),
  RubricCriterion(
    id: 5,
    position: 3,
    description: 'สรุปคำตอบพร้อมหน่วย',
    points: 1,
    source: 'ai',
  ),
];

/// Fake whose `requestRubricDraft` is a no-op (DraftRubricJob still queued)
/// until the test flips [criteria], mirroring the once-a-minute queue.
class _FakeAssignments extends Fake implements AssignmentsRepository {
  _FakeAssignments({required this.criteria, this.status = RubricStatus.draft});

  List<RubricCriterion> criteria;
  RubricStatus status;
  int draftRequests = 0;
  int gets = 0;

  @override
  Future<Assignment> get(int id) async {
    gets++;
    return Assignment(
      id: id,
      classroomId: 7,
      subjectId: 1,
      title: 'เศษส่วน ชุดที่ 3',
      questions: [
        Question(
          id: 501,
          position: 1,
          type: QuestionType.showWork,
          promptText: 'จงหาค่า x เมื่อ 2x + 5 = 255 และแสดงวิธีทำ',
          maxPoints: 5,
          rubricStatus: status,
          rubricCriteria: criteria,
          answerKey: const {
            'reference_steps': ['2x = 250', 'x = 125'],
          },
        ),
      ],
    );
  }

  @override
  Future<void> requestRubricDraft(int questionId) async {
    draftRequests++;
  }
}

Future<void> _setPhoneSize(WidgetTester tester) async {
  tester.view.physicalSize = const Size(360, 800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('renders at phone width (360 dp) without overflow', (
    tester,
  ) async {
    await _setPhoneSize(tester);
    final fake = _FakeAssignments(criteria: _oldCriteria);
    await pumpScreen(
      tester,
      const RubricScreen(assignmentId: 1, questionId: 501),
      overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
    );

    expect(tester.takeException(), isNull);
    expect(find.text('ร่างแล้ว รออนุมัติ'), findsOneWidget);
    expect(find.text('ให้ AI ร่าง rubric'), findsOneWidget);
    expect(find.text('ร่างโดย AI'), findsNWidgets(2));
    expect(find.text('เกณฑ์หลัก (แก่นของคำตอบ)'), findsNWidgets(2));
  });

  testWidgets('renders at tablet width (1024 dp) without overflow', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1024, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final fake = _FakeAssignments(criteria: _oldCriteria);
    await pumpScreen(
      tester,
      const RubricScreen(assignmentId: 1, questionId: 501),
      overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    're-drafting a question that already has a rubric waits for new criteria',
    (tester) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(criteria: _oldCriteria);
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );

      await tester.tap(find.text('ให้ AI ร่าง rubric'));
      await tester.pump();
      expect(fake.draftRequests, 1);
      expect(find.text('รอ AI ร่าง…'), findsOneWidget);

      // Two polls with the server still returning the OLD criteria: the
      // screen must keep waiting instead of announcing success.
      final getsBefore = fake.gets;
      await tester.pump(_RubricScreenTestHooks.pollInterval);
      await tester.pump();
      await tester.pump(_RubricScreenTestHooks.pollInterval);
      await tester.pump();
      expect(fake.gets, greaterThan(getsBefore), reason: 'polling happened');
      expect(
        find.text('AI ร่าง rubric แล้ว ตรวจและอนุมัติได้เลย'),
        findsNothing,
      );
      expect(find.text('รอ AI ร่าง…'), findsOneWidget);
      expect(find.text('แสดงวิธีทำและได้คำตอบ 125'), findsOneWidget);

      // DraftRubricJob finally ran: the next poll sees different criteria.
      fake.criteria = _newCriteria;
      await tester.pump(_RubricScreenTestHooks.pollInterval);
      await tester.pump();
      await tester.pump();
      expect(
        find.text('AI ร่าง rubric แล้ว ตรวจและอนุมัติได้เลย'),
        findsOneWidget,
      );
      expect(find.text('ให้ AI ร่าง rubric'), findsOneWidget);
      expect(find.text('สรุปคำตอบพร้อมหน่วย'), findsOneWidget);
      expect(find.text('แสดงวิธีทำและได้คำตอบ 125'), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpAndSettle();
    },
  );

  testWidgets('first draft on a question with no rubric shows up', (
    tester,
  ) async {
    await _setPhoneSize(tester);
    final fake = _FakeAssignments(
      criteria: const [],
      status: RubricStatus.notNeeded,
    );
    await pumpScreen(
      tester,
      const RubricScreen(assignmentId: 1, questionId: 501),
      overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
    );
    await tester.tap(find.text('ให้ AI ร่าง rubric'));
    await tester.pump();

    fake
      ..criteria = _newCriteria
      ..status = RubricStatus.draft;
    await tester.pump(_RubricScreenTestHooks.pollInterval);
    await tester.pump();
    await tester.pump();
    expect(
      find.text('AI ร่าง rubric แล้ว ตรวจและอนุมัติได้เลย'),
      findsOneWidget,
    );
    expect(find.text('เขียนสมการได้'), findsOneWidget);
    await tester.pumpAndSettle();
  });

  test('rubricFingerprint changes with any criterion field', () {
    const base = Question(
      id: 1,
      position: 1,
      type: QuestionType.open,
      promptText: 'p',
      maxPoints: 5,
      rubricStatus: RubricStatus.draft,
      rubricCriteria: _oldCriteria,
    );
    final same = rubricFingerprint(base);
    expect(rubricFingerprint(base), same);
    expect(rubricFingerprint(null), isNull);
    expect(
      rubricFingerprint(
        const Question(
          id: 1,
          position: 1,
          type: QuestionType.open,
          promptText: 'p',
          maxPoints: 5,
          rubricStatus: RubricStatus.approved,
          rubricCriteria: _oldCriteria,
        ),
      ),
      isNot(same),
      reason: 'status is part of the fingerprint',
    );
    expect(
      rubricFingerprint(
        Question(
          id: 1,
          position: 1,
          type: QuestionType.open,
          promptText: 'p',
          maxPoints: 5,
          rubricStatus: RubricStatus.draft,
          rubricCriteria: [
            _oldCriteria[0].copyWith(points: 3),
            _oldCriteria[1],
          ],
        ),
      ),
      isNot(same),
    );
  });
}

abstract final class _RubricScreenTestHooks {
  /// Slightly more than the screen's poll interval so the periodic timer
  /// fires exactly once per pump.
  static const pollInterval = Duration(seconds: 5);
}
