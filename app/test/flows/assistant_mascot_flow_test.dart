import 'dart:async';

import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/live/app_settings.dart';
import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/widgets/adaptive_shell.dart';
import 'package:eduvision/core/widgets/mascot.dart';
import 'package:eduvision/core/widgets/mascot_mood.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeAssistant implements AssistantRepository {
  AssistantStatus statusValue = const AssistantStatus(
    available: true,
    usedToday: 1,
  );
  List<AssistantConversation> list = const [];
  final sent = <(String, int?)>[];
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
  Future<AssistantReply> conversation(int id) async => _reply(id, []);

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
    sent.add((text, conversationId));
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
  }) async {
    throw UnimplementedError();
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
        'title': 'สนทนา',
        'input_tokens': 100,
        'output_tokens': 50,
        'updated_at': '2026-10-20T03:00:00Z',
      },
      'messages': messages,
    })!;

void main() {
  testWidgets('assistant flow from greeting to retry with the mascot', (
    tester,
  ) async {
    final repo = _FakeAssistant();
    final storage = InMemoryTokenStorage();

    tester.view.physicalSize = const Size(420, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          assistantRepositoryProvider.overrideWithValue(repo),
          tokenStorageProvider.overrideWithValue(storage),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          home: Scaffold(
            body: AssistantPanel(onClose: () {}, onNavigate: (_) {}),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // 1. เปิดแชทว่าง → อารมณ์ wave
    final emptyMascots = tester.widgetList<Mascot>(find.byType(Mascot));
    expect(emptyMascots, isNotEmpty);
    expect(emptyMascots.any((m) => m.mood == MascotMood.wave), isTrue);

    // 2. พิมพ์คำถามแล้วส่ง โดยให้ repository ปลอมค้างไว้ → อารมณ์ thinking
    final pendingAnswer = Completer<AssistantReply>();
    repo.replies.add(pendingAnswer);

    await tester.enterText(
      find.byKey(const ValueKey('assistant_input')),
      'คำถามที่ 1',
    );
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    await tester.pump();

    final thinkingMascots = tester.widgetList<Mascot>(find.byType(Mascot));
    expect(thinkingMascots.any((m) => m.mood == MascotMood.thinking), isTrue);
    expect(find.byKey(const ValueKey('assistant_thinking')), findsOneWidget);

    // 3. ได้คำตอบ → idle
    pendingAnswer.complete(
      _reply(1, [
        _message(1, 'user', 'คำถามที่ 1'),
        _message(2, 'assistant', 'คำตอบที่ 1'),
      ]),
    );
    await tester.pumpAndSettle();

    final answeredMascots = tester.widgetList<Mascot>(find.byType(Mascot));
    expect(answeredMascots.any((m) => m.mood == MascotMood.idle), isTrue);
    expect(answeredMascots.any((m) => m.mood == MascotMood.thinking), isFalse);
    expect(find.text('คำตอบที่ 1'), findsOneWidget);

    // 4. คำถามถัดไปล้มเหลว → sorry และเห็น assistant_retry
    repo.replies.add(
      _reply(1, [
        _message(1, 'user', 'คำถามที่ 1'),
        _message(2, 'assistant', 'คำตอบที่ 1'),
        _message(3, 'user', 'คำถามที่ 2'),
        _message(4, 'error', 'เซิร์ฟเวอร์ขัดข้อง', code: 'AI-FAIL', call: 999),
      ]),
    );

    await tester.enterText(
      find.byKey(const ValueKey('assistant_input')),
      'คำถามที่ 2',
    );
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('assistant_retry')), findsOneWidget);
    final errorMascots = tester.widgetList<Mascot>(find.byType(Mascot));
    expect(errorMascots.any((m) => m.mood == MascotMood.sorry), isTrue);

    // 5. กดลองใหม่แล้วสำเร็จ → กลับเป็น idle
    repo.replies.add(
      _reply(1, [
        _message(1, 'user', 'คำถามที่ 1'),
        _message(2, 'assistant', 'คำตอบที่ 1'),
        _message(3, 'user', 'คำถามที่ 2'),
        _message(4, 'assistant', 'ตอบสำเร็จหลังลองใหม่'),
      ]),
    );

    await tester.tap(find.byKey(const ValueKey('assistant_retry')));
    await tester.pumpAndSettle();

    final retriedMascots = tester.widgetList<Mascot>(find.byType(Mascot));
    expect(retriedMascots.any((m) => m.mood == MascotMood.idle), isTrue);
    expect(retriedMascots.any((m) => m.mood == MascotMood.sorry), isFalse);
    expect(find.text('ตอบสำเร็จหลังลองใหม่'), findsOneWidget);

    // 6. ไปปิดสวิตช์ → ตัวการ์ตูนหายจากแชท
    final element = tester.element(find.byType(AssistantPanel));
    final container = ProviderScope.containerOf(element);
    await container.read(mascotEnabledProvider.notifier).setEnabled(false);
    await tester.pumpAndSettle();

    expect(find.byType(Mascot), findsNothing);
    expect(find.byIcon(Icons.smart_toy_outlined), findsOneWidget);
  });

  testWidgets('the button that opens the chat shows the mascot, or the robot '
      'when it is switched off', (tester) async {
    Future<void> pumpAt(Size size, bool mascotOn) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        MaterialApp(
          key: UniqueKey(),
          theme: AppTheme.light(),
          home: AdaptiveShell(
            title: 'Krucheck',
            assistant: ShellAction(
              key: const ValueKey('assistant_button'),
              tooltip: 'ผู้ช่วย Krucheck',
              icon: Icons.smart_toy_outlined,
              picture: mascotOn
                  ? (size) => Mascot(mood: MascotMood.wave, size: size)
                  : null,
              onPressed: () {},
            ),
            destinations: const [
              ShellDestination('หน้าหลัก', Icons.home_outlined, Icons.home),
              ShellDestination('ห้องเรียน', Icons.class_outlined, Icons.class_),
            ],
            selectedIndex: 0,
            onSelected: (_) {},
            body: const SizedBox(),
          ),
        ),
      );
      await tester.pump();
    }

    final button = find.byKey(const ValueKey('assistant_button'));
    Finder inButton(Finder of) => find.descendant(of: button, matching: of);

    for (final size in const [Size(390, 844), Size(1440, 900)]) {
      await pumpAt(size, true);
      expect(inButton(find.byType(Mascot)), findsOneWidget);
      expect(inButton(find.byIcon(Icons.smart_toy_outlined)), findsNothing);
      final mascot = tester.widget<Mascot>(inButton(find.byType(Mascot)));
      expect(mascot.size, size.width < 600 ? 46 : 28);
      if (size.width < 600) {
        // Big enough to find and press with a thumb.
        expect(tester.getSize(button), const Size(56, 56));
        // The mascot is blue: on the theme's blue button it would disappear.
        expect(
          tester.widget<FloatingActionButton>(button).backgroundColor,
          AppTheme.light().colorScheme.secondaryContainer,
        );
      }

      await pumpAt(size, false);
      expect(inButton(find.byType(Mascot)), findsNothing);
      expect(inButton(find.byIcon(Icons.smart_toy_outlined)), findsOneWidget);
    }
  });
}
