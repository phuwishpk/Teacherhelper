import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/widgets/adaptive_shell.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../review/review_fixtures.dart';

/// "ผู้ช่วย Krucheck" (DESIGN §37).
class _FakeAssistant implements AssistantRepository {
  AssistantStatus statusValue = const AssistantStatus(
    available: true,
    usedToday: 3,
  );
  List<AssistantConversation> list = const [];
  final sent = <(String, int?)>[];
  final retried = <int>[];
  final deleted = <int>[];

  /// The next answers, in order; a Completer keeps the AI "thinking".
  final replies = <Object>[];

  Future<AssistantReply> _next() async {
    final r = replies.removeAt(0);
    final value = r is Completer<AssistantReply> ? await r.future : r;
    if (value is AssistantReply) return value;
    throw value;
  }

  @override
  Future<AssistantStatus> status() async => statusValue;

  @override
  Future<List<AssistantConversation>> conversations() async => list;

  @override
  Future<AssistantReply> conversation(int id) async => _reply(id, [
    _message(1, 'user', 'ถามเก่า'),
    _message(2, 'assistant', 'ตอบเก่า'),
  ]);

  @override
  Future<AssistantStatus> acknowledgeStudentData() async =>
      const AssistantStatus(available: true, studentDataAcknowledged: true);
  @override
  Future<void> delete(int id) async => deleted.add(id);

  @override
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  }) {
    sent.add((text, conversationId));
    return _next();
  }

  @override
  Future<AssistantReply> retry(int conversationId) {
    retried.add(conversationId);
    return _next();
  }

  final applied = <(int, List<int>, Map<String, dynamic>?)>[];

  /// The next apply results: an [AssistantMessage] or an error to throw.
  final applyResults = <Object>[];

  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) async {
    applied.add((messageId, selected, target));
    final r = applyResults.removeAt(0);
    if (r is AssistantMessage) return r;
    throw r;
  }
}

Map<String, dynamic> _message(
  int id,
  String role,
  String content, {
  String? code,
  int? call,
}) => {
  'id': id,
  'role': role,
  'content': content,
  'error_code': code,
  'ai_call_id': call,
};

AssistantReply _reply(int conversation, List<Map<String, dynamic>> messages) =>
    AssistantReply.tryParse({
      'conversation': {
        'id': conversation,
        'title': 'ตัดเกรดยังไง',
        'input_tokens': 41000,
        'output_tokens': 230,
        'updated_at': '2026-10-20T03:00:00Z',
      },
      'messages': messages,
    })!;

void main() {
  test('the repository posts the text and reads a stored failure', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'conversation': {'id': 7, 'title': 'ถาม'},
          'messages': [
            _message(1, 'user', 'ถาม'),
            _message(2, 'assistant', 'ตอบ'),
          ],
        },
      }),
    );
    final reply = await ApiAssistantRepository(
      fakeDio(adapter),
    ).send('ถาม', conversationId: 7);
    final request = adapter.requests.single;
    expect(
      '${request.method} ${request.uri.path}',
      'POST /api/v1/assistant/messages',
    );
    final body = request.data is String
        ? jsonDecode(request.data as String)
        : request.data;
    expect(body, {'text': 'ถาม', 'conversation_id': 7});
    expect(request.receiveTimeout, const Duration(seconds: 120));
    expect(reply.conversation.id, 7);
    expect(reply.messages.last.content, 'ตอบ');

    final failed = AssistantReply.tryParse({
      'message': 'ผู้ช่วยตอบไม่สำเร็จ',
      'code': 'ai_unavailable',
      'ai_error_code': 'AI-QUOTA',
      'conversation': {'id': 7, 'title': 'ถาม'},
      'messages': [
        _message(1, 'user', 'ถาม'),
        _message(2, 'error', 'โควตาหมด', code: 'AI-QUOTA', call: 48213),
      ],
    })!;
    expect(failed.messages.last.code?.label, 'AI-QUOTA · #48213');
  });

  late _FakeAssistant repo;
  late List<String> calls;

  setUp(() {
    repo = _FakeAssistant();
    calls = [];
  });

  Future<void> pumpPanel(WidgetTester tester, {double width = 420}) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [assistantRepositoryProvider.overrideWithValue(repo)],
        child: MaterialApp(
          theme: AppTheme.light(),
          home: Scaffold(
            body: AssistantPanel(
              onClose: () => calls.add('close'),
              onNavigate: (route) => calls.add(route),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('without a key of their own the teacher is sent to settings', (
    tester,
  ) async {
    repo.statusValue = const AssistantStatus(available: false);
    await pumpPanel(tester);

    expect(find.byKey(const ValueKey('assistant_input')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('assistant_settings')));
    expect(calls, ['/settings']);
  });

  testWidgets('the AI thinks with the input locked, then answers', (
    tester,
  ) async {
    final answer = Completer<AssistantReply>();
    repo.replies.add(answer);
    await pumpPanel(tester);

    await tester.enterText(
      find.byKey(const ValueKey('assistant_input')),
      'ตัดเกรดยังไง',
    );
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    await tester.pump();

    expect(find.text('ตัดเกรดยังไง'), findsOneWidget);
    expect(find.byKey(const ValueKey('assistant_thinking')), findsOneWidget);
    expect(find.text('ผู้ช่วยกำลังคิด…'), findsWidgets);
    final input = tester.widget<TextField>(
      find.byKey(const ValueKey('assistant_input')),
    );
    expect(input.enabled, isFalse);
    expect(
      tester
          .widget<IconButton>(find.byKey(const ValueKey('assistant_send')))
          .onPressed,
      isNull,
    );

    answer.complete(
      _reply(7, [
        _message(1, 'user', 'ตัดเกรดยังไง'),
        _message(2, 'assistant', 'เปิดแท็บตัดเกรด แล้วเลือกรายวิชา'),
      ]),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('assistant_thinking')), findsNothing);
    expect(find.text('เปิดแท็บตัดเกรด แล้วเลือกรายวิชา'), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('assistant_usage'))).data,
      'วันนี้ถามแล้ว 3/100 ข้อความ · บทสนทนานี้ใช้ 41,230 โทเค็น',
    );

    // The next question continues the same conversation.
    repo.replies.add(
      _reply(7, [_message(3, 'user', 'ต่อ'), _message(4, 'assistant', 'ได้')]),
    );
    await tester.enterText(
      find.byKey(const ValueKey('assistant_input')),
      'ต่อ',
    );
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();
    expect(repo.sent, [('ตัดเกรดยังไง', null), ('ต่อ', 7)]);
    expect(find.text('เปิดแท็บตัดเกรด แล้วเลือกรายวิชา'), findsOneWidget);
    expect(find.text('ได้'), findsOneWidget);
  });

  testWidgets('a stored failure shows its code and "ลองใหม่" asks again', (
    tester,
  ) async {
    repo.replies
      ..add(
        apiError(502, {
          'message': 'ผู้ช่วยตอบไม่สำเร็จ',
          'code': 'ai_unavailable',
          'ai_error_code': 'AI-QUOTA',
          'ai_call_id': 48213,
          'conversation': {'id': 7, 'title': 'ถาม'},
          'messages': [
            _message(1, 'user', 'ถาม'),
            _message(2, 'error', 'โควตาหมด', code: 'AI-QUOTA', call: 48213),
          ],
        }),
      )
      ..add(
        _reply(7, [
          _message(1, 'user', 'ถาม'),
          _message(3, 'assistant', 'คำตอบ'),
        ]),
      );
    await pumpPanel(tester);

    await tester.tap(find.byKey(const ValueKey('assistant_suggestion_0')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('assistant_error')), findsOneWidget);
    expect(find.text('โควตาหมด'), findsOneWidget);
    expect(find.text('รหัส AI-QUOTA · #48213'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assistant_retry')));
    await tester.pumpAndSettle();
    expect(repo.retried, [7]);
    expect(find.byKey(const ValueKey('assistant_error')), findsNothing);
    expect(find.text('ถาม'), findsOneWidget);
    expect(find.text('คำตอบ'), findsOneWidget);
  });

  testWidgets('no connection: NET-OFFLINE, and "ลองใหม่" sends again', (
    tester,
  ) async {
    repo.replies
      ..add(
        DioException(
          requestOptions: RequestOptions(path: '/assistant/messages'),
          type: DioExceptionType.connectionError,
        ),
      )
      ..add(
        _reply(8, [
          _message(1, 'user', 'ถาม'),
          _message(2, 'assistant', 'ตอบ'),
        ]),
      );
    await pumpPanel(tester);

    await tester.enterText(
      find.byKey(const ValueKey('assistant_input')),
      'ถาม',
    );
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    await tester.pumpAndSettle();
    expect(find.text('รหัส NET-OFFLINE'), findsOneWidget);
    expect(
      find.text('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองใหม่'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('assistant_retry')));
    await tester.pumpAndSettle();
    expect(repo.sent, [('ถาม', null), ('ถาม', null)]);
    expect(find.text('ถาม'), findsOneWidget);
    expect(find.text('ตอบ'), findsOneWidget);
  });

  testWidgets('the day\'s limit is a CHAT-LIMIT error', (tester) async {
    repo.replies.add(
      apiError(429, {
        'message': 'วันนี้ถามผู้ช่วยครบ 100 ข้อความแล้ว',
        'code': 'assistant_limit',
        'ai_error_code': 'CHAT-LIMIT',
      }),
    );
    await pumpPanel(tester);
    await tester.enterText(
      find.byKey(const ValueKey('assistant_input')),
      'ถาม',
    );
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    await tester.pumpAndSettle();
    expect(find.text('วันนี้ถามผู้ช่วยครบ 100 ข้อความแล้ว'), findsOneWidget);
    expect(find.text('รหัส CHAT-LIMIT'), findsOneWidget);
  });

  testWidgets('the list opens and deletes conversations', (tester) async {
    repo.list = [
      AssistantConversation(
        id: 5,
        title: 'สแกนไม่ผ่าน',
        inputTokens: 1200,
        updatedAt: DateTime.utc(2026, 10, 19, 2),
      ),
    ];
    await pumpPanel(tester);

    await tester.tap(find.byKey(const ValueKey('assistant_list')));
    await tester.pumpAndSettle();
    expect(find.text('บทสนทนาทั้งหมด'), findsWidgets);
    expect(find.textContaining('1,200 โทเค็น'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assistant_conversation_5')));
    await tester.pumpAndSettle();
    expect(find.text('ตอบเก่า'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assistant_list')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('assistant_delete_5')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('assistant_delete_confirm')));
    await tester.pumpAndSettle();
    expect(repo.deleted, [5]);
  });

  Future<void> pumpShell(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final key = GlobalKey<ScaffoldState>();
    final expanded = width >= 1200;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [assistantRepositoryProvider.overrideWithValue(repo)],
        child: MaterialApp(
          theme: AppTheme.light(),
          home: AdaptiveShell(
            scaffoldKey: key,
            title: 'Krucheck',
            destinations: const [
              ShellDestination('หน้าหลัก', Icons.home_outlined, Icons.home),
              ShellDestination(
                'ห้องเรียน',
                Icons.groups_outlined,
                Icons.groups,
              ),
            ],
            selectedIndex: 0,
            onSelected: (_) {},
            body: const Center(child: Text('เนื้อหา')),
            assistant: ShellAction(
              key: const ValueKey('assistant_button'),
              tooltip: 'ผู้ช่วย Krucheck',
              icon: Icons.smart_toy_outlined,
              onPressed: () => expanded
                  ? key.currentState?.openEndDrawer()
                  : calls.add('page'),
            ),
            endDrawer: expanded
                ? Drawer(
                    width: AssistantPanel.width,
                    child: AssistantPanel(
                      onClose: () => key.currentState?.closeEndDrawer(),
                      onNavigate: (_) {},
                    ),
                  )
                : null,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('1440 px: the sidebar button opens a 420 px panel', (
    tester,
  ) async {
    await pumpShell(tester, 1440);
    expect(find.byType(FloatingActionButton), findsNothing);
    await tester.tap(find.byKey(const ValueKey('assistant_button')));
    await tester.pumpAndSettle();

    final panel = find.byType(AssistantPanel);
    expect(tester.getSize(panel).width, AssistantPanel.width);
    expect(tester.getTopRight(panel).dx, 1440);
    expect(find.byKey(const ValueKey('assistant_input')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('assistant_close')));
    await tester.pumpAndSettle();
    expect(panel, findsNothing);
    expect(tester.takeException(), isNull);
  });

  for (final width in [390.0, 820.0]) {
    testWidgets('${width.toInt()} px: a floating button at the bottom left', (
      tester,
    ) async {
      await pumpShell(tester, width);
      final button = find.byKey(const ValueKey('assistant_button'));
      expect(tester.widget(button), isA<FloatingActionButton>());
      expect(tester.getCenter(button).dx, lessThan(width / 2));
      await tester.tap(button);
      expect(calls, ['page']);

      // The full page fits the width.
      await pumpPanel(tester, width: width);
      expect(find.byKey(const ValueKey('assistant_input')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
