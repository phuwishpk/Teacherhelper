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
  Future<AssistantStatus> acknowledgeStudentData() async =>
      const AssistantStatus(available: true, studentDataAcknowledged: true);
  @override
  Future<void> delete(int id) async {}
  @override
  Future<AssistantReply> retry(int id) => throw UnimplementedError();
  @override
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
  }) async => replies.removeAt(0);
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

  AssistantMessage message(int id, Map<String, dynamic> plan, String status) =>
      AssistantMessage.fromJson({
        'id': id,
        'role': 'assistant',
        'content': 'เสนอในการ์ด',
        'plan': plan,
        'plan_status': status,
      });

  Map<String, dynamic> rubricPlan({
    String? problem,
    Map<String, dynamic>? result,
  }) => {
    'kind': 'rubric',
    'target': {
      'assignment_id': 88,
      'title': 'การบ้านเรียงความ',
      'kind': 'worksheet',
    },
    'question': {
      'id': 5,
      'position': 1,
      'type': 'open',
      'prompt': 'เขียนเรียงความเรื่องครอบครัว',
      'max_points': 3,
      'rubric_status': 'approved',
    },
    'criteria': [
      {'description': 'เนื้อหาตรงเรื่อง', 'points': 2, 'is_core': true},
      {'description': 'ใช้ภาษาถูกต้อง', 'points': 1, 'is_core': false},
    ],
    'reference_steps': const [],
    'problem': ?problem,
    'result': ?result,
  };

  testWidgets('a rubric card saves a draft and links to the rubric screen', (
    tester,
  ) async {
    repo.replies.add(
      _reply([_question1, message(2, rubricPlan(), 'proposed')]),
    );
    repo.applyResults.add(
      message(
        2,
        rubricPlan(
          result: {
            'assignment_id': 88,
            'title': 'การบ้านเรียงความ',
            'question_id': 5,
            'position': 1,
          },
        ),
        'applied',
      ),
    );
    await pump(tester);
    await ask(tester, 'ทำ rubric ข้อ 1');

    expect(find.text('ร่าง rubric ข้อ 1'), findsOneWidget);
    expect(find.text('เกณฑ์หลัก'), findsOneWidget);
    expect(find.text('2 คะแนน'), findsOneWidget);
    expect(find.textContaining('ร่างนี้จะแทนที่'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(applied().$2, isEmpty);
    expect(find.text('บันทึก rubric ร่างของข้อ 1 แล้ว'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('assistant_plan_open')));
    expect(routes, ['/assignments/88/questions/5/rubric']);
  });

  testWidgets('a rubric card with a problem cannot be saved', (tester) async {
    repo.replies.add(
      _reply([
        _question1,
        message(
          2,
          rubricPlan(problem: 'เกณฑ์ต้องมีเกณฑ์หลัก 1 ข้อ'),
          'proposed',
        ),
      ]),
    );
    await pump(tester);
    await ask(tester, 'ทำ rubric ข้อ 1');
    expect(find.text('เกณฑ์ต้องมีเกณฑ์หลัก 1 ข้อ'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
            find.byKey(const ValueKey('assistant_plan_apply_2')),
          )
          .onPressed,
      isNull,
    );
  });

  testWidgets('an indicator card ticks what can be done', (tester) async {
    Map<String, dynamic> plan({Map<String, dynamic>? result}) => {
      'kind': 'indicators',
      'target': {'assignment_id': 9, 'title': 'สอบเศษส่วน', 'kind': 'exam'},
      'items': [
        {
          'action': 'new',
          'code': '',
          'name': 'อ่านจับใจความสำคัญได้',
          'parent': {'id': 1, 'code': 'ท 1.1', 'name': 'มาตรฐานการอ่าน'},
          'reason': 'ตรงกับเรื่องที่สอน',
          'question_positions': const [],
          'flags': const [],
        },
        {
          'action': 'existing',
          'code': 'ค 1.1 ป.5/1',
          'skill_id': 7,
          'name': 'บวกเศษส่วน',
          'reason': '',
          'question_positions': [1, 2],
          'flags': const [],
        },
        {
          'action': 'existing',
          'code': 'NOPE-9',
          'name': '',
          'reason': '',
          'question_positions': const [],
          'flags': ['not_found'],
        },
      ],
      'result': ?result,
    };
    repo.replies.add(_reply([_question1, message(2, plan(), 'proposed')]));
    repo.applyResults.add(
      message(
        2,
        plan(
          result: {
            'created': 1,
            'added_mine': 1,
            'mapped': 2,
            'skipped': const [],
            'assignment_id': 9,
          },
        ),
        'applied',
      ),
    );
    await pump(tester);
    await ask(tester, 'แนะนำตัวชี้วัด');

    expect(find.text('ตัวชี้วัดที่เสนอ 3 รายการ'), findsOneWidget);
    expect(
      find.text('ตัวชี้วัดที่เพิ่มเองจะแชร์ให้ครูทุกคนในโรงเรียน'),
      findsOneWidget,
    );
    expect(
      find.text('ใหม่: (ระบบตั้งรหัสให้) อ่านจับใจความสำคัญได้'),
      findsOneWidget,
    );
    expect(find.textContaining('เสนอให้ข้อ 1, 2'), findsOneWidget);
    expect(find.text('ไม่พบรหัสนี้ในหลักสูตร'), findsOneWidget);
    expect(
      [ticked(tester, 0), ticked(tester, 1), ticked(tester, 2)],
      [true, true, false],
    );

    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(applied().$2, [0, 1]);
    expect(
      find.text(
        'เพิ่มตัวชี้วัดใหม่ 1 ตัว · เพิ่มลงตัวชี้วัดของฉัน 1 ตัว · เสนอให้ข้อ 2 รายการ',
      ),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('assistant_plan_open_mapping')));
    await tester.tap(find.byKey(const ValueKey('assistant_plan_open')));
    expect(routes, ['/assignments/9/indicators', '/settings/indicators']);
  });

  testWidgets('a gradebook card shows the change and what goes away', (
    tester,
  ) async {
    Map<String, dynamic> plan({
      String? problem,
      Map<String, dynamic>? result,
    }) => {
      'kind': 'gradebook',
      'target': {'course_id': 4, 'title': 'ค15101 คณิตศาสตร์ 5'},
      'categories': [
        {
          'name': 'งาน',
          'weight': 60,
          'drop_lowest': 1,
          'is_homework_default': true,
        },
        {
          'name': 'สอบ',
          'weight': 40,
          'drop_lowest': 0,
          'is_homework_default': false,
        },
      ],
      'cutoffs': [80, 75, 70, 65, 60, 55, 50],
      'current': {
        'categories': [
          {'name': 'งาน', 'weight': 50, 'drop_lowest': 0, 'item_count': 3},
          {'name': 'จิตพิสัย', 'weight': 50, 'drop_lowest': 0, 'item_count': 2},
        ],
        'cutoffs': [80, 75, 70, 65, 60, 55, 50],
      },
      'removed': [
        {'name': 'จิตพิสัย', 'item_count': 2},
      ],
      'problem': ?problem,
      'result': ?result,
    };
    repo.replies.add(_reply([_question1, message(2, plan(), 'proposed')]));
    repo.applyResults.add(
      message(
        2,
        plan(
          result: {
            'course_id': 4,
            'title': 'ค15101 คณิตศาสตร์ 5',
            'categories': 2,
            'cutoffs': true,
          },
        ),
        'applied',
      ),
    );
    await pump(tester);
    await ask(tester, 'ตั้งหมวดคะแนน');

    expect(
      find.text('งาน 60% (ตัด 1 ต่ำสุด) · หมวดตั้งต้นของการบ้าน\nสอบ 40%'),
      findsOneWidget,
    );
    expect(
      find.text(
        'หมวด «จิตพิสัย» จะถูกเอาออก งาน 2 ชิ้นในหมวดนี้จะเป็น "ยังไม่ระบุหมวด"',
      ),
      findsOneWidget,
    );
    expect(find.text('80 / 75 / 70 / 65 / 60 / 55 / 50'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(applied().$2, isEmpty);
    expect(
      find.text('บันทึกการตั้งค่าสมุดคะแนนของ «ค15101 คณิตศาสตร์ 5» แล้ว'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('assistant_plan_open')));
    expect(routes, ['/courses/4/gradebook/settings']);
  });

  testWidgets('a practice card ticks the items with a key', (tester) async {
    Map<String, dynamic> plan({Map<String, dynamic>? result}) => {
      'kind': 'practice',
      'target': {'skill_id': 7, 'code': 'ค 1.1', 'title': 'ค 1.1 บวกเศษส่วน'},
      'items': [
        {
          'answer_type': 'mcq',
          'prompt_text': '1/2 + 1/4 = ?',
          'options': [
            {'key': 'A', 'text': '1/6'},
            {'key': 'B', 'text': '3/4'},
          ],
          'answer_key': {'correct': 'B'},
          'explanation': 'ทำส่วนให้เท่ากัน',
        },
        {
          'answer_type': 'numeric',
          'prompt_text': '0.5 + 0.25 = ?',
          'answer_key': {
            'numeric': {'value': 0.75, 'abs_tol': 0},
          },
          'explanation': 'บวกทศนิยม',
        },
        {
          'answer_type': 'short',
          'prompt_text': 'ไม่มีเฉลย',
          'answer_key': {'accepted': const []},
          'explanation': 'x',
          'error': 'ใส่คำตอบที่ยอมรับอย่างน้อย 1 คำตอบ',
        },
      ],
      'result': ?result,
    };
    repo.replies.add(_reply([_question1, message(2, plan(), 'proposed')]));
    repo.applyResults.add(
      message(
        2,
        plan(
          result: {
            'skill_id': 7,
            'title': 'ค 1.1 บวกเศษส่วน',
            'created': 2,
            'skipped': const [],
          },
        ),
        'applied',
      ),
    );
    await pump(tester);
    await ask(tester, 'ขอแบบฝึก');

    expect(find.text('แบบฝึกที่เสนอ 3 ข้อ'), findsOneWidget);
    expect(find.text('เฉลย: 0.75'), findsOneWidget);
    expect(find.text('ใส่คำตอบที่ยอมรับอย่างน้อย 1 คำตอบ'), findsOneWidget);
    expect(
      [ticked(tester, 0), ticked(tester, 1), ticked(tester, 2)],
      [true, true, false],
    );
    await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
    await tester.pumpAndSettle();
    expect(applied().$2, [0, 1]);
    expect(find.text('บันทึกแบบฝึก 2 ข้อเป็นร่างแล้ว'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('assistant_plan_open')));
    expect(routes, ['/practice-bank']);
  });
}
