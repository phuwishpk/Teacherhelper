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
/// until the test flips [criteria] / [referenceSteps], mirroring the
/// once-a-minute queue.
class _FakeAssignments extends Fake implements AssignmentsRepository {
  _FakeAssignments({
    this.type = QuestionType.open,
    this.criteria = const [],
    this.referenceSteps = const [],
    this.status = RubricStatus.draft,
  });

  final QuestionType type;
  List<RubricCriterion> criteria;
  List<String> referenceSteps;
  RubricStatus status;
  int draftRequests = 0;
  int gets = 0;
  final saved = <Map<String, Object?>>[];

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
          type: type,
          promptText: type == QuestionType.showWork
              ? 'จงหาค่า x เมื่อ 2x + 5 = 255 และแสดงวิธีทำ'
              : 'อธิบายว่าทำไม 2x + 5 = 255 จึงได้ x = 125',
          maxPoints: 5,
          rubricStatus: status,
          rubricCriteria: criteria,
          answerKey: type == QuestionType.showWork
              ? {
                  'final': {
                    'accepted': ['x = 125'],
                  },
                  'reference_steps': referenceSteps,
                }
              : null,
        ),
      ],
    );
  }

  @override
  Future<void> requestRubricDraft(int questionId) async {
    draftRequests++;
  }

  @override
  Future<Question> saveRubric(
    int questionId, {
    required List<RubricCriterion> criteria,
    List<String>? referenceSteps,
  }) async {
    saved.add({
      'question_id': questionId,
      'criteria': [for (final c in criteria) c.toJson()],
      'reference_steps': referenceSteps,
    });
    this.criteria = criteria;
    if (referenceSteps != null) this.referenceSteps = referenceSteps;
    status = RubricStatus.approved;
    return (await get(1)).questions.single;
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

  group('show_work: reference steps, no criteria', () {
    testWidgets('a draft that returns reference steps only is picked up', (
      tester,
    ) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(
        type: QuestionType.showWork,
        status: RubricStatus.notNeeded,
      );
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );
      expect(find.text('เกณฑ์การให้คะแนน'), findsNothing);
      expect(find.text('เพิ่มเกณฑ์'), findsNothing);
      expect(find.text('คำตอบสุดท้ายตามเฉลย: x = 125'), findsOneWidget);

      await tester.tap(find.text('ให้ AI ร่างขั้นตอนอ้างอิง'));
      await tester.pump();
      expect(fake.draftRequests, 1);

      // Job still queued: keep waiting.
      await tester.pump(_RubricScreenTestHooks.pollInterval);
      await tester.pump();
      expect(find.text('รอ AI ร่าง…'), findsOneWidget);

      // DraftRubricJob writes only reference_steps for show_work (§10.4).
      fake
        ..referenceSteps = ['2x = 250', 'x = 125']
        ..status = RubricStatus.draft;
      await tester.pump(_RubricScreenTestHooks.pollInterval);
      await tester.pump();
      await tester.pump();
      expect(
        find.text('AI ร่างขั้นตอนอ้างอิงแล้ว ตรวจและอนุมัติได้เลย'),
        findsOneWidget,
      );
      expect(find.text('ยังไม่ได้ร่างจาก AI'), findsNothing);
      final steps = tester.widget<TextField>(
        find.widgetWithText(TextField, 'ขั้นตอนอ้างอิง (บรรทัดละขั้น)'),
      );
      expect(steps.controller!.text, '2x = 250\nx = 125');
      expect(fake.criteria, isEmpty);
      expect(tester.takeException(), isNull);
      await tester.pumpAndSettle();
    });

    testWidgets('approves with zero criteria and the edited steps', (
      tester,
    ) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(
        type: QuestionType.showWork,
        referenceSteps: ['2x = 250', 'x = 125'],
      );
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'ขั้นตอนอ้างอิง (บรรทัดละขั้น)'),
        '2x + 5 = 255\n 2x = 250\n\nx = 125\n',
      );
      await tester.ensureVisible(find.text('บันทึกและอนุมัติ'));
      await tester.tap(find.text('บันทึกและอนุมัติ'));
      await tester.pumpAndSettle();

      expect(find.text('ต้องมีเกณฑ์อย่างน้อย 1 ข้อ'), findsNothing);
      expect(fake.saved, [
        {
          'question_id': 501,
          'criteria': <Object>[],
          'reference_steps': ['2x + 5 = 255', '2x = 250', 'x = 125'],
        },
      ]);
      expect(find.text('อนุมัติแล้ว'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('approving without any step asks first', (tester) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(type: QuestionType.showWork);
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );
      await tester.ensureVisible(find.text('บันทึกและอนุมัติ'));
      await tester.tap(find.text('บันทึกและอนุมัติ'));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่มีขั้นตอนอ้างอิง'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(fake.saved, isEmpty);

      await tester.tap(find.text('บันทึกและอนุมัติ'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'อนุมัติ'));
      await tester.pumpAndSettle();
      expect(fake.saved.single['criteria'], isEmpty);
      expect(fake.saved.single['reference_steps'], isEmpty);
    });
  });

  group('open: criteria', () {
    testWidgets('ticking a core criterion clears the previous one', (
      tester,
    ) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(criteria: _oldCriteria);
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );
      final boxes = find.byType(Checkbox);
      expect(tester.widget<Checkbox>(boxes.at(0)).value, isTrue);
      expect(tester.widget<Checkbox>(boxes.at(1)).value, isFalse);

      await tester.ensureVisible(boxes.at(1));
      await tester.tap(boxes.at(1));
      await tester.pump();
      expect(tester.widget<Checkbox>(boxes.at(0)).value, isFalse);
      expect(tester.widget<Checkbox>(boxes.at(1)).value, isTrue);
    });

    testWidgets('approving with no criteria is refused', (tester) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(status: RubricStatus.notNeeded);
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );
      await tester.ensureVisible(find.text('บันทึกและอนุมัติ'));
      await tester.tap(find.text('บันทึกและอนุมัติ'));
      await tester.pumpAndSettle();
      expect(find.text('ต้องมีเกณฑ์อย่างน้อย 1 ข้อ'), findsOneWidget);
      expect(fake.saved, isEmpty);
    });

    testWidgets('approving without a core criterion asks first', (
      tester,
    ) async {
      await _setPhoneSize(tester);
      final fake = _FakeAssignments(
        criteria: [_oldCriteria[0].copyWith(isCore: false), _oldCriteria[1]],
      );
      await pumpScreen(
        tester,
        const RubricScreen(assignmentId: 1, questionId: 501),
        overrides: [assignmentsRepositoryProvider.overrideWithValue(fake)],
      );
      await tester.ensureVisible(find.text('บันทึกและอนุมัติ'));
      await tester.tap(find.text('บันทึกและอนุมัติ'));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่ได้เลือกเกณฑ์หลัก'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'อนุมัติ'));
      await tester.pumpAndSettle();

      final sent = fake.saved.single;
      expect(sent['reference_steps'], isNull);
      expect(sent['criteria'], [
        {
          'position': 1,
          'description': 'ตั้งสมการจากโจทย์ได้ถูกต้องครบทุกเงื่อนไข',
          'points': 2.0,
          'is_core': false,
        },
        {
          'position': 2,
          'description': 'แสดงวิธีทำและได้คำตอบ 125',
          'points': 3.0,
          'is_core': false,
        },
      ]);
    });
  });

  test('hasRubricDraft: steps for show_work, criteria for open', () {
    const showWork = Question(
      id: 1,
      position: 1,
      type: QuestionType.showWork,
      promptText: 'p',
      maxPoints: 5,
      answerKey: {
        'reference_steps': ['x = 5'],
      },
    );
    expect(hasRubricDraft(showWork), isTrue);
    const open = Question(
      id: 1,
      position: 1,
      type: QuestionType.open,
      promptText: 'p',
      maxPoints: 5,
    );
    expect(hasRubricDraft(open), isFalse);
    expect(
      hasRubricDraft(
        const Question(
          id: 1,
          position: 1,
          type: QuestionType.open,
          promptText: 'p',
          maxPoints: 5,
          rubricCriteria: _oldCriteria,
        ),
      ),
      isTrue,
    );
  });

  test('rubricFingerprint changes with the reference steps', () {
    Question q(List<String> steps) => Question(
      id: 1,
      position: 1,
      type: QuestionType.showWork,
      promptText: 'p',
      maxPoints: 5,
      rubricStatus: RubricStatus.draft,
      answerKey: {'reference_steps': steps},
    );
    expect(rubricFingerprint(q(['a'])), rubricFingerprint(q(['a'])));
    expect(rubricFingerprint(q(['a'])), isNot(rubricFingerprint(q(['b']))));
    expect(rubricFingerprint(q([])), isNot(rubricFingerprint(q(['a']))));
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
