import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_detail_screen.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/assignments/question_form_screen.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
    ),
  ];
}

class _Assignments extends Fake implements AssignmentsRepository {
  _Assignments(this.assignment);

  Assignment assignment;
  final added = <QuestionDraft>[];

  @override
  Future<Assignment> get(int id) async => assignment;

  @override
  Future<Question> addQuestion(int assignmentId, QuestionDraft draft) async {
    added.add(draft);
    return Question(
      id: 900,
      position: 1,
      type: draft.type,
      promptText: draft.promptText,
      maxPoints: draft.maxPoints,
    );
  }
}

const _freeform = Assignment(
  id: 12,
  classroomId: 7,
  subjectId: 1,
  title: 'เรียงความ',
  mode: AssignmentMode.freeform,
  keyOrigin: KeyOrigin.aiDraft,
  scoreOnly: true,
  acceptLate: false,
  questions: [
    Question(
      id: 501,
      position: 1,
      type: QuestionType.short,
      promptText: '6 × 7 เท่ากับเท่าไร',
      maxPoints: 1,
      keyComplete: false,
    ),
  ],
);

void main() {
  Future<_Assignments> pumpForm(
    WidgetTester tester, {
    bool? freeform = true,
  }) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final repo = _Assignments(_freeform);
    await pumpScreen(
      tester,
      QuestionFormScreen(assignmentId: 12, subjectId: 1, freeform: freeform),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(repo),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
      ],
    );
    return repo;
  }

  testWidgets('a freeform question may be saved without its answer', (
    tester,
  ) async {
    final repo = await pumpForm(tester);
    await tester.tap(find.text('เติมคำตอบสั้น'));
    await tester.pumpAndSettle();
    expect(find.textContaining('เว้นว่างได้'), findsOneWidget);
    await tester.enterText(
      find.widgetWithText(TextFormField, 'โจทย์'),
      '6 × 7 เท่ากับเท่าไร',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มข้อ'));
    await tester.pumpAndSettle();

    final draft = repo.added.single;
    expect(draft.type, QuestionType.short);
    expect(draft.answerKey, isNull);
    expect(draft.toJson()['answer_key'], isNull);
  });

  testWidgets('a freeform question deep link reads the mode from the '
      'assignment', (tester) async {
    final repo = await pumpForm(tester, freeform: null);
    await tester.enterText(
      find.widgetWithText(TextFormField, 'โจทย์'),
      'ข้อใดถูก',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มข้อ'));
    await tester.pumpAndSettle();
    expect(repo.added.single.answerKey, isNull, reason: 'mcq without a choice');
  });

  testWidgets('a worksheet question still needs its answer', (tester) async {
    final repo = await pumpForm(tester, freeform: false);
    await tester.enterText(
      find.widgetWithText(TextFormField, 'โจทย์'),
      'ข้อใดถูก',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มข้อ'));
    await tester.pumpAndSettle();
    expect(find.text('เลือกข้อที่ถูก'), findsOneWidget);
    expect(repo.added, isEmpty);
  });

  testWidgets('an open question carries the teacher\'s model answer', (
    tester,
  ) async {
    final repo = await pumpForm(tester);
    await tester.tap(find.text('อัตนัย'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextFormField, 'โจทย์'),
      'ทำไมใบไม้จึงมีสีเขียว',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'คำตอบตัวอย่างของครู (ไม่บังคับ)'),
      '  ใบไม้มีคลอโรฟิลล์ซึ่งสะท้อนแสงสีเขียว  ',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เพิ่มข้อ'));
    await tester.pumpAndSettle();

    final json = repo.added.single.toJson();
    expect(json['type'], 'open');
    expect(json['model_answer'], 'ใบไม้มีคลอโรฟิลล์ซึ่งสะท้อนแสงสีเขียว');
    expect(json['answer_lines'], 3);
  });

  testWidgets('a freeform assignment shows its key card, not the worksheet', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await pumpScreen(
      tester,
      const AssignmentDetailScreen(assignmentId: 12),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(
          _Assignments(_freeform),
        ),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        googleClassroomEnabledProvider.overrideWithValue(false),
      ],
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id/answer-key',
          builder: (_, state) =>
              Text('answer key ${state.pathParameters['id']}'),
        ),
      ],
    );

    expect(find.text('ไม่ใช้ใบงานของแอป'), findsOneWidget);
    expect(find.text('เฉพาะคะแนน'), findsOneWidget);
    expect(find.text('ไม่รับงานส่งช้า'), findsOneWidget);
    expect(find.text('ใบงาน'), findsNothing);
    expect(find.text('พิมพ์ใบงาน'), findsNothing);
    expect(find.text('AI ร่าง ไม่มีคำตอบของครู'), findsOneWidget);
    expect(find.text('ยังไม่มีเฉลย'), findsOneWidget);
    expect(find.textContaining('ระบบเริ่มตรวจหลังอนุมัติเฉลย'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('answer_key_card')));
    await tester.pumpAndSettle();
    expect(find.text('answer key 12'), findsOneWidget);
  });

  testWidgets('a worksheet assignment keeps its worksheet card and key card', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await pumpScreen(
      tester,
      const AssignmentDetailScreen(assignmentId: 12),
      overrides: [
        assignmentsRepositoryProvider.overrideWithValue(
          _Assignments(
            Assignment(
              id: 12,
              classroomId: 7,
              subjectId: 1,
              title: 'เศษส่วน',
              status: 'ready',
              currentLayoutVersion: 1,
              keyApprovedAt: DateTime.utc(2026, 9, 29, 3),
            ),
          ),
        ),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        googleClassroomEnabledProvider.overrideWithValue(false),
      ],
    );
    expect(find.text('ใบงาน'), findsOneWidget);
    expect(find.text('ใบงานของแอป'), findsOneWidget);
    expect(find.textContaining('อนุมัติเฉลยแล้ว'), findsOneWidget);
  });
}
