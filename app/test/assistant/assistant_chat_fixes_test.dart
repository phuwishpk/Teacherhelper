import 'dart:async';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

/// Faults found in the assistant chat on 2026-11-01 (DESIGN §37.5).
class _FakeAssistant implements AssistantRepository {
  AssistantStatus statusValue = const AssistantStatus(available: true);
  List<AssistantConversation> list = const [];
  bool openFails = false;
  int opens = 0;
  final sent = <(String, List<int>)>[];
  final retried = <int>[];
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
  Future<AssistantReply> conversation(int id) async {
    opens++;
    if (openFails) {
      openFails = false;
      throw _offline();
    }
    return _reply(id, [
      _message(1, 'user', 'ถามเก่า'),
      _message(2, 'assistant', 'ตอบเก่า'),
    ]);
  }

  @override
  Future<AssistantStatus> acknowledgeStudentData() async =>
      const AssistantStatus(available: true, studentDataAcknowledged: true);

  @override
  Future<void> delete(int id) async {}

  @override
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  }) {
    sent.add((text, documentIds));
    return _next();
  }

  @override
  Future<AssistantReply> retry(int conversationId) {
    retried.add(conversationId);
    return _next();
  }

  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) async => throw UnimplementedError();
}

class _Picker implements DocumentFilePicker {
  int count = 1;

  @override
  Future<List<PickedDocument>> pick({
    bool imagesOnly = false,
    String? dialogTitle,
  }) async => [
    for (var i = 0; i < count; i++)
      PickedDocument(
        name: 'เฉลย$i.pdf',
        bytes: Uint8List.fromList([37, 80, 68, 70]),
      ),
  ];
}

class _Uploads extends Fake implements AnswerKeyRepository {
  Completer<void>? gate;
  var _next = 31;

  @override
  Future<List<SourceDocument>> upload(List<PickedDocument> files) async {
    if (gate case final g?) await g.future;
    return [
      for (final f in files)
        SourceDocument(
          id: _next++,
          originalName: f.name,
          mimeType: 'application/pdf',
          pageCount: 2,
        ),
    ];
  }
}

DioException _offline() => DioException(
  requestOptions: RequestOptions(path: '/assistant'),
  type: DioExceptionType.connectionError,
);

Map<String, dynamic> _message(
  int id,
  String role,
  String content, {
  String? code,
}) => {'id': id, 'role': role, 'content': content, 'error_code': code};

Map<String, dynamic> _conversation(int id) => {
  'id': id,
  'title': 'เรื่องเก่า',
  'input_tokens': 10,
  'output_tokens': 5,
};

AssistantReply _reply(int id, List<Map<String, dynamic>> messages) =>
    AssistantReply.tryParse({
      'conversation': _conversation(id),
      'messages': messages,
    })!;

void main() {
  late _FakeAssistant repo;
  late _Picker picker;
  late _Uploads uploads;
  late List<String> routes;

  final input = find.byKey(const ValueKey('assistant_input'));
  final send = find.byKey(const ValueKey('assistant_send'));
  final attach = find.byKey(const ValueKey('assistant_attach'));
  final retry = find.byKey(const ValueKey('assistant_retry'));

  setUp(() {
    repo = _FakeAssistant();
    picker = _Picker();
    uploads = _Uploads();
    routes = [];
  });

  Future<void> pumpPanel(
    WidgetTester tester, {
    Size size = const Size(420, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          assistantRepositoryProvider.overrideWithValue(repo),
          documentFilePickerProvider.overrideWithValue(picker),
          answerKeyRepositoryProvider.overrideWithValue(uploads),
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

  bool focused(WidgetTester tester) => tester
      .widget<EditableText>(
        find.descendant(of: input, matching: find.byType(EditableText)),
      )
      .focusNode
      .hasFocus;

  // F1
  testWidgets('"ลองใหม่" opens again a conversation that failed to open', (
    tester,
  ) async {
    repo.list = [AssistantConversation.fromJson(_conversation(7))];
    repo.openFails = true;
    await pumpPanel(tester);

    await tester.tap(find.byKey(const ValueKey('assistant_list')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('assistant_conversation_7')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('assistant_error')), findsOneWidget);

    await tester.tap(retry);
    await tester.pumpAndSettle();
    expect(repo.opens, 2);
    expect(find.text('ตอบเก่า'), findsOneWidget);
  });

  // F2
  testWidgets('a question waits for the file that is still uploading', (
    tester,
  ) async {
    uploads.gate = Completer();
    repo.replies.add(
      _reply(7, [
        _message(1, 'user', 'อ่านไฟล์นี้'),
        _message(2, 'assistant', 'อ่านแล้ว'),
      ]),
    );
    await pumpPanel(tester);

    await tester.tap(attach);
    await tester.pump();
    await tester.pump();
    expect(find.text('กำลังอัปโหลด…'), findsOneWidget);
    expect(tester.widget<IconButton>(send).onPressed, isNull);

    await tester.enterText(input, 'อ่านไฟล์นี้');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pump();
    expect(repo.sent, isEmpty);
    expect(find.text('อ่านไฟล์นี้'), findsOneWidget);

    uploads.gate!.complete();
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pumpAndSettle();
    expect(repo.sent.single.$2, [31]);
  });

  // F3
  testWidgets('taking a file off leaves the other upload running', (
    tester,
  ) async {
    await pumpPanel(tester);
    await tester.tap(attach);
    await tester.pumpAndSettle();
    uploads.gate = Completer();
    await tester.tap(attach);
    await tester.pump();
    await tester.pump();

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('assistant_file_31')),
        matching: find.byIcon(Icons.close),
      ),
    );
    await tester.pump();
    expect(find.text('กำลังอัปโหลด…'), findsOneWidget);
    expect(tester.widget<IconButton>(attach).onPressed, isNull);

    uploads.gate!.complete();
    await tester.pumpAndSettle();
    expect(find.text('กำลังอัปโหลด…'), findsNothing);
  });

  // F4
  testWidgets('more files than the limit: the teacher is told why', (
    tester,
  ) async {
    picker.count = 7;
    await pumpPanel(tester);
    await tester.tap(attach);
    await tester.pumpAndSettle();
    expect(find.byType(InputChip), findsNWidgets(5));
    expect(find.text('แนบได้ไม่เกิน 5 ไฟล์ต่อคำถาม'), findsOneWidget);

    picker.count = 1;
    await tester.tap(find.byKey(const ValueKey('assistant_file_31')).first);
    await tester.tap(attach);
    await tester.pumpAndSettle();
    expect(find.byType(InputChip), findsNWidgets(5));
    expect(find.text('แนบได้ไม่เกิน 5 ไฟล์ต่อคำถาม'), findsOneWidget);
  });

  // F5
  testWidgets('a file with no question says what to type', (tester) async {
    await pumpPanel(tester);
    await tester.tap(attach);
    await tester.pumpAndSettle();
    expect(find.text('บอกผู้ช่วยว่าจะให้ทำอะไรกับไฟล์นี้'), findsOneWidget);
    expect(repo.sent, isEmpty);
  });

  // F6
  testWidgets('desktop: the input keeps its focus after an answer', (
    tester,
  ) async {
    repo.replies.add(
      _reply(7, [_message(1, 'user', 'ถาม'), _message(2, 'assistant', 'ตอบ')]),
    );
    await pumpPanel(tester, size: const Size(1440, 900));
    await tester.tap(input);
    await tester.enterText(input, 'ถาม');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();
    expect(find.text('ตอบ'), findsOneWidget);
    expect(focused(tester), isTrue);
  });

  testWidgets('phone: the keyboard stays down so the answer can be read', (
    tester,
  ) async {
    repo.replies.add(
      _reply(7, [_message(1, 'user', 'ถาม'), _message(2, 'assistant', 'ตอบ')]),
    );
    await pumpPanel(tester, size: const Size(390, 844));
    await tester.tap(input);
    await tester.enterText(input, 'ถาม');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();
    expect(focused(tester), isFalse);
  });

  // F7
  testWidgets('phone: a key added in settings counts on the way back', (
    tester,
  ) async {
    repo.statusValue = const AssistantStatus(available: false);
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (_, _) => const AssistantScreen()),
        GoRoute(
          path: AppRoutes.settings,
          builder: (_, _) => const Scaffold(body: Text('ตั้งค่า')),
        ),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [assistantRepositoryProvider.overrideWithValue(repo)],
        child: MaterialApp.router(
          theme: AppTheme.light(),
          routerConfig: router,
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('assistant_settings')));
    await tester.pumpAndSettle();
    expect(find.text('ตั้งค่า'), findsOneWidget);
    repo.statusValue = const AssistantStatus(available: true);
    router.pop();
    await tester.pumpAndSettle();

    expect(input, findsOneWidget);
  });
}

