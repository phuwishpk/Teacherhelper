import 'dart:async';

import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_panel.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../review/review_fixtures.dart';

/// The plan card of "ผู้ช่วย Krucheck" (DESIGN §37.6).
class _Fake implements AssistantRepository {
  final replies = <AssistantReply>[];
  final applied = <(int, List<int>, Map<String, dynamic>?)>[];
  final applyResults = <Object>[];

  @override
  Future<AssistantStatus> status() async =>
      const AssistantStatus(available: true);
  @override
  Future<List<AssistantConversation>> conversations() async => const [];
  @override
  Future<AssistantReply> conversation(int id) => throw UnimplementedError();
  @override
  Future<void> delete(int id) async {}
  @override
  Future<AssistantReply> retry(int id) => throw UnimplementedError();
  @override
  Future<AssistantReply> send(String text, {int? conversationId}) async =>
      replies.removeAt(0);
  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) async {
    applied.add((messageId, selected, target));
    final r = applyResults.removeAt(0);
    if (r is Completer<AssistantMessage>) return r.future;
    if (r is AssistantMessage) return r;
    throw r;
  }
}

class _Assignments extends Fake implements AssignmentsRepository {
  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [
    Assignment(
      id: 9,
      classroomId: 3,
      subjectId: 1,
      title: 'สอบเศษส่วน',
      kind: Assignment.kindExam,
      classroomName: 'ป.5/1',
    ),
    Assignment(
      id: 10,
      classroomId: 3,
      subjectId: 1,
      title: 'งานที่ปิดแล้ว',
      status: 'closed',
    ),
  ];
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 3,
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
    ),
  ];
}

Map<String, dynamic> _question(
  String type,
  String prompt, {
  List<String> options = const [],
  List<int> correct = const [],
  List<String> flags = const [],
  bool figure = false,
}) => {
  'type': type,
  'prompt_text': prompt,
  'options': options,
  'correct_options': correct,
  'accepted_answers': const [],
  'numeric_value': null,
  'reference_steps': const [],
  'model_answer': null,
  'points': 1,
  'explanation': 'เพราะ 2/4 + 1/4 = 3/4',
  'indicator_codes': const ['ค 1.1'],
  'needs_figure': figure,
  'flags': flags,
};

Map<String, dynamic> _plan({Map<String, dynamic>? result}) => {
  'target': {
    'assignment_id': 88,
    'title': 'การบ้านเศษส่วน',
    'kind': 'worksheet',
    'classroom': 'ป.5/1',
    'status': 'ready',
  },
  'questions': [
    _question(
      'mcq',
      '1/2 + 1/4 เท่ากับเท่าไร',
      options: ['1/6', '3/4', '2/6', '1'],
      correct: [2],
    ),
    _question(
      'true_false',
      '2/4 เท่ากับ 1/2',
      correct: [1],
      flags: ['unsupported'],
    ),
    _question(
      'mcq',
      'จากภาพ ส่วนที่แรเงาคือเศษเท่าไร',
      options: ['1/5', '2/5'],
      flags: ['needs_figure', 'incomplete_key'],
      figure: true,
    ),
  ],
  'result': ?result,
};

AssistantMessage _card(
  int id, {
  String status = 'proposed',
  Map<String, dynamic>? result,
}) => AssistantMessage.fromJson({
  'id': id,
  'role': 'assistant',
  'content': 'เสนอ 3 ข้อในการ์ดด้านล่าง',
  'plan': _plan(result: result),
  'plan_status': status,
});

AssistantReply _reply(List<AssistantMessage> messages, {int? applyId}) =>
    AssistantReply(
      const AssistantConversation(id: 7, title: 'ออกโจทย์'),
      messages,
      applyMessageId: applyId,
    );

const _question1 = AssistantMessage(id: 1, role: 'user', content: 'ออกโจทย์');

void main() {
  late _Fake repo;
  late List<String> routes;

  setUp(() {
    repo = _Fake();
    routes = [];
  });

  Future<void> pump(WidgetTester tester, {double width = 1000}) async {
    tester.view.physicalSize = Size(width, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          assistantRepositoryProvider.overrideWithValue(repo),
          assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
          classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          home: Scaffold(
            body: AssistantPanel(onClose: () {}, onNavigate: routes.add),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> ask(
    WidgetTester tester,
    String text, {
    bool settle = true,
  }) async {
    await tester.enterText(find.byKey(const ValueKey('assistant_input')), text);
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    if (settle) {
      await tester.pumpAndSettle();
    } else {
      await tester.pump();
      await tester.pump();
    }
  }

  /// The one apply call as (message id, ticks, target).
  (int, List<int>, Map<String, dynamic>?) applied() {
    final a = repo.applied.single;
    return (a.$1, [...a.$2], a.$3);
  }

  bool? ticked(WidgetTester tester, int index) => tester
      .widget<Checkbox>(
        find.descendant(
          of: find.byKey(ValueKey('assistant_plan_q_2_$index')),
          matching: find.byType(Checkbox),
        ),
      )
      .value;

  testWidgets('the card ticks what fits and «สร้างเลย» creates it', (
    tester,
  ) async {
    repo.replies.add(_reply([_question1, _card(2)]));
    repo.applyResults.add(
      _card(
        2,
        status: 'applied',
        result: {
          'assignment_id': 88,
          'title': 'การบ้านเศษส่วน',
          'kind': 'worksheet',
          'created': 1,
          'skipped': const [],
        },
      ),
    );
    await pump(tester);
    await ask(tester, 'ออกโจทย์');

    expect(find.text('ข้อที่เสนอ 3 ข้อ'), findsOneWidget);
    expect(find.text('ไปที่: «การบ้านเศษส่วน»'), findsOneWidget);
    expect(
      find.textContaining('งานนี้พร้อมแล้ว เพิ่มข้อแล้วงานจะกลับเป็นร่าง'),
      findsOneWidget,
    );
    expect(
      [ticked(tester, 0), ticked(tester, 1), ticked(tester, 2)],
      [true, false, false],
    );
    expect(find.text('B. 3/4'), findsOneWidget);
    expect(find.text('ต้องใส่รูปเอง'), findsOneWidget);
    expect(find.text('เฉลยไม่ครบ'), findsOneWidget);
    expect(
      find.text('การบ้านไม่มีข้อถูก/ผิดหรือเติมตัวเลขแบบข้อสอบ'),
      findsOneWidget,
    );

    // A disabled box does not tick; the first one unticks and ticks again.
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('assistant_plan_q_2_1')),
        matching: find.byType(Checkbox),
      ),
    );
    await tester.pump();
    expect(ticked(tester, 1), isFalse);

    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(applied().$1, 2);
    expect(applied().$2, [0]);
    expect(applied().$3, null);
    expect(find.text('สร้างแล้ว 1 ข้อใน «การบ้านเศษส่วน»'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assistant_plan_open')));
    expect(routes, ['/assignments/88']);
  });

  testWidgets('a typed confirmation creates the open card', (tester) async {
    repo.replies
      ..add(_reply([_question1, _card(2)]))
      ..add(
        _reply([
          const AssistantMessage(id: 3, role: 'user', content: 'โอเค สร้างเลย'),
          const AssistantMessage(
            id: 4,
            role: 'assistant',
            content: 'กำลังสร้างข้อที่ติ๊กไว้ในการ์ด',
          ),
        ], applyId: 2),
      );
    final pending = Completer<AssistantMessage>();
    repo.applyResults.add(pending);
    await pump(tester);
    await ask(tester, 'ออกโจทย์');
    await ask(tester, 'โอเค สร้างเลย', settle: false);

    expect(applied().$1, 2);
    expect(applied().$2, [0]);
    expect(applied().$3, null);
    expect(
      tester
          .widget<FilledButton>(
            find.byKey(const ValueKey('assistant_plan_apply_2')),
          )
          .onPressed,
      isNull,
    );
    pending.completeError(
      apiError(409, {
        'message': 'การ์ดนี้สร้างไปแล้วหรือมีการ์ดใหม่แทนแล้ว',
        'code': 'plan_not_open',
      }),
    );
    await tester.pumpAndSettle();
    expect(
      find.text('การ์ดนี้สร้างไปแล้วหรือมีการ์ดใหม่แทนแล้ว'),
      findsOneWidget,
    );
  });

  testWidgets('a new card replaces the open one', (tester) async {
    repo.replies
      ..add(_reply([_question1, _card(2)]))
      ..add(
        _reply([
          const AssistantMessage(id: 3, role: 'user', content: 'ข้อ 3 ยากไป'),
          _card(4),
        ]),
      );
    await pump(tester);
    await ask(tester, 'ออกโจทย์');
    await ask(tester, 'ข้อ 3 ยากไป');

    expect(find.text('การ์ด 3 ข้อนี้ถูกแทนด้วยการ์ดใหม่'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('assistant_plan_apply_4')),
      findsOneWidget,
    );
  });

  testWidgets('«เปลี่ยน» to an exam ticks the exam types', (tester) async {
    repo.replies.add(_reply([_question1, _card(2)]));
    repo.applyResults.add(_card(2, status: 'replaced'));
    await pump(tester);
    await ask(tester, 'ออกโจทย์');

    await tester.tap(find.byKey(const ValueKey('assistant_plan_change_2')));
    await tester.pumpAndSettle();
    expect(find.text('งานที่ปิดแล้ว'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('assistant_target_9')));
    await tester.pumpAndSettle();

    expect(find.text('ไปที่: «สอบเศษส่วน»'), findsOneWidget);
    expect(
      [ticked(tester, 0), ticked(tester, 1), ticked(tester, 2)],
      [true, true, false],
    );
    expect(find.text('ข. 3/4'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(applied().$1, 2);
    expect(applied().$2, [0, 1]);
    expect(applied().$3, {'assignment_id': 9});
  });

  testWidgets('a new assignment from «เปลี่ยน»', (tester) async {
    repo.replies.add(_reply([_question1, _card(2)]));
    repo.applyResults.add(_card(2, status: 'replaced'));
    await pump(tester);
    await ask(tester, 'ออกโจทย์');

    await tester.tap(find.byKey(const ValueKey('assistant_plan_change_2')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('งานใหม่'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('assistant_target_classroom')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ป.5/1').last);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('assistant_target_title')),
      'แบบฝึกใหม่',
    );
    await tester.tap(find.text('การบ้านไม่ใช้ใบงาน'));
    await tester.tap(find.byKey(const ValueKey('assistant_target_use')));
    await tester.pumpAndSettle();

    expect(find.text('ไปที่: «แบบฝึกใหม่»'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(repo.applied.single.$3, {
      'new': {'classroom_id': 3, 'title': 'แบบฝึกใหม่', 'kind': 'freeform'},
    });
  });

  testWidgets('a phone folds the questions into one line', (tester) async {
    repo.replies.add(_reply([_question1, _card(2)]));
    await pump(tester, width: 390);
    await ask(tester, 'ออกโจทย์');

    expect(find.text('B. 3/4'), findsNothing);
    final fold = find.text('ข้อ 1–3 · ปรนัย 2 ข้อ, ถูก/ผิด 1 ข้อ');
    expect(fold, findsOneWidget);
    await tester.tap(fold);
    await tester.pumpAndSettle();
    expect(find.text('B. 3/4'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
